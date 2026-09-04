<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiRunLogger;
use App\Services\Ai\AiThresholdCalibration;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * أدوات الحوكمة تحرس فعلاً — لا على الورق.
 *
 * كلٌّ من هذه موجود ومكتوب وموثَّق، ولا يعمل: مفتاحُ إطفاءٍ لا يغطّي أخطر المسارات،
 * وعيّنةُ معايرةٍ صفرٌ أبداً، ونسبةُ فشلٍ لا يمكن أن تتجاوز الصفر، وسجلُّ تدقيقٍ
 * يكتب نموذجاً غير الذي نُودي، وصندوقُ مراجعةٍ لا تبلغه قيود الاجتماعات.
 *
 * والجامع بينها أنها **تُطمئن كاذبةً**: وجودُها يُقرأ ضماناً، فيُبنى عليه قرار.
 */
class AiGovernanceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    // ── مفتاح الإطفاء ──

    /**
     * **الحارس الأثمن:** كل تعليمة حيّة لها مفتاح إطفاء — والعالية أولاها.
     *
     * كان المصدر `AiEvaluator::GATES` (سبعة معرّفات لها حالات تقييم)، فبقيت سبعةٌ
     * بلا مفتاح منها **خمسٌ عالية**: مولّد صحيفة ناجز ومساعد المحامي وملخّص
     * الاستشارة وملخّص الاجتماع وتصنيف القضية — لا تُوقَف إلّا بنشر شيفرة.
     */
    public function test_every_live_prompt_can_be_switched_off(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $live = array_keys(array_filter(
            AiPromptRegistry::PROMPTS,
            fn (array $p) => ($p['retired'] ?? false) === false
        ));

        $this->actingAs($admin)
            ->post('/admin/ai-ops/tasks', ['tasks' => array_fill_keys($live, false)])
            ->assertRedirect();

        foreach ($live as $promptId) {
            $this->assertFalse(
                Setting::aiTaskEnabled($promptId),
                "المسار «{$promptId}» لا يُطفَأ — وهو ".AiPolicyGate::sensitivity($promptId).' الحساسيّة.'
            );
        }
    }

    /** والمتقاعد لا يُعرَض له مفتاح: مفتاحٌ لمسارٍ لا يعمل إيهامٌ بقدرةٍ وبعمل. */
    public function test_a_retired_prompt_gets_no_switch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post('/admin/ai-ops/tasks', ['tasks' => ['lawyer.match' => false]]);

        $this->assertTrue(Setting::aiTaskEnabled('lawyer.match'), 'لا يُخزَّن إطفاءٌ لمسارٍ متقاعد');
    }

    // ── معايرة العتبة ──

    /**
     * عيّنة المعايرة تُطابق **الاسم المخزَّن** لا معرّف التعليمة.
     *
     * كانت `whereIn` على `ticket.triage` والمخزَّن `triage`، فالعيّنة صفرٌ أبداً
     * ولا تُعايَر العتبة من قرارٍ بشريّ واحد مهما راجع المكتب.
     */
    public function test_the_calibration_sample_matches_the_stored_task_type(): void
    {
        $this->assertSame('triage', AiRunLogger::storedTaskType('ticket.triage'));
        $this->assertSame('consult', AiRunLogger::storedTaskType('consult.analyze'));
        $this->assertSame('execution', AiRunLogger::storedTaskType('execution.analyze'));
        $this->assertSame('case.pleading', AiRunLogger::storedTaskType('case.pleading'), 'وما لا خريطة له هو نفسه');

        // قيدُ فرزٍ مراجَعٌ بثقةٍ مقيسة — يجب أن يدخل العيّنة
        AiRun::create([
            'task_type' => 'triage', 'entity_ref' => 'SB-1',
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_COMPLETED,
            'confidence' => 80, 'review_action' => 'accept',
            'reviewed_at' => now(),
        ]);

        $this->assertSame(1, AiThresholdCalibration::analyse(30)['sample'], 'صفرٌ هنا يعني أن الأداة عمياء');
    }

    // ── نسبة الفشل ──

    /**
     * نسبة الفشل تقيس تعثّراً وقع — لا حالةً غير قابلة للوصول.
     *
     * كانت تَعُدّ `STATUS_FAILED`، ولا يُنتجها إلّا `hasUsableOutput === false`
     * ولا مُنادٍ في الإنتاج يمرّرها. فالنسبة صفرٌ بنيويّاً وإنذارُها لا يُطلق أبداً.
     */
    public function test_the_failure_rate_counts_failures_that_actually_happen(): void
    {
        AiRun::create([
            'task_type' => 'ticket.summary', 'entity_ref' => 'SB-2',
            'source' => AiSource::Fallback, 'status' => AiRun::STATUS_NEEDS_REVIEW,
            'failure_code' => AiFailure::PROVIDER_ERROR,
        ]);

        $this->assertGreaterThan(0, AiOpsMetrics::snapshot(30)['failure_rate'], 'تعثّرُ مزوّدٍ فشلٌ يُقاس');
    }

    /** والإطفاء المتعمَّد وتجاوز الميزانيّة ليسا فشلاً — قراران اتُّخذا عمداً. */
    public function test_a_deliberate_switch_off_is_not_counted_as_failure(): void
    {
        foreach ([AiFailure::TASK_DISABLED, AiFailure::BUDGET_EXCEEDED] as $i => $code) {
            AiRun::create([
                'task_type' => 'ticket.summary', 'entity_ref' => 'SB-D'.$i,
                'source' => AiSource::Fallback, 'status' => AiRun::STATUS_NEEDS_REVIEW,
                'failure_code' => $code,
            ]);
        }

        $this->assertSame(0.0, AiOpsMetrics::snapshot(30)['failure_rate'], 'لا يُنذَر المكتب بما فعله بنفسه');
    }

    // ── صندوق المراجعة ──

    /** قيود الاجتماعات تبلغ محاميها — لا الإدارة وحدها. */
    public function test_meeting_runs_reach_their_lawyer_inbox(): void
    {
        $this->seed(PermissionSeeder::class);

        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $other->syncPermissions(Permission::all());

        $meeting = Meeting::create([
            'user_id' => $lawyer->id, 'ref' => 'M-INB-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => 'اليوم', 'type' => 'اجتماع عميل', 'status' => 'منتهٍ',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        AiRun::create([
            'task_type' => 'meeting.decisions', 'entity_type' => Meeting::class,
            'entity_id' => $meeting->id, 'entity_ref' => (string) $meeting->id,
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        $this->assertContains((string) $meeting->id, AiReviewInbox::forUser($lawyer)->pluck('entity_ref')->all());
        $this->assertNotContains((string) $meeting->id, AiReviewInbox::forUser($other)->pluck('entity_ref')->all());
    }

    // ── سجلّ النموذج ──

    /** القيد يسجّل النموذج **الموجَّه** لا نموذج الإعداد. */
    public function test_the_audit_records_the_model_that_was_actually_called(): void
    {
        $src = file_get_contents(app_path('Services/Ai/AiGateway.php'));
        $code = (string) preg_replace('#/\*.*?\*/#su', '', $src);

        $this->assertStringNotContainsString('model: (string) config(', $code, 'نموذج الإعداد ليس المُنادى');
        $this->assertStringContainsString('AiModelRouter::modelFor($provider, $promptId)', $code);
    }

    // ── تقرير الحوكمة ──

    /** وتقرير الحوكمة مجدول — لا يُقرأ بالمصادفة. */
    public function test_the_governance_report_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->implode(' | ');

        $this->assertStringContainsString('ai:report', $events);
    }
    // ── الاتّجاه المعاكس: اعتمادُ الملفّ يُسجَّل قراراً ──

    /**
     * **الحارس الأثمن:** اعتماد ملخّص التذكرة من شاشتها يُسجَّل في `ai_runs`.
     *
     * كان الاعتماد يُكتب في `ticket_summaries` وحدها، فيبقى القيد `needs_review`
     * وقرارُه `NULL` **أبداً** — يعرضه الصندوق معلَّقاً، ولا يعدّه `humanEditRate`،
     * ويقول تقرير الحوكمة «لم يُراجَع» لمخرجٍ اعتمده محامٍ وأُرسل للعميل.
     * قِيس على قاعدة التطوير: ٢ من ٢ (منها `SB-2026-3715`).
     */
    public function test_approving_a_summary_in_the_file_screen_records_the_review(): void
    {
        $this->seed(PermissionSeeder::class);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-REV-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'محالة للمحامي', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ]);
        $run = AiRun::create([
            'task_type' => 'ticket.summary', 'entity_ref' => $ticket->number,
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        $this->actingAs($lawyer)->post("/lawyer/summary/{$ticket->getRouteKey()}/approve", [
            'case_summary' => 'ملخّص.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
        ])->assertRedirect();

        $run->refresh();
        $this->assertNotNull($run->review_action, 'القرار يُسجَّل — لا يبقى معلَّقاً أبداً');
        $this->assertSame($lawyer->id, $run->reviewed_by);
        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status, 'ولا يبقى في صندوق المراجعة');
        $this->assertNotContains($ticket->number, AiReviewInbox::forUser($lawyer)->pluck('entity_ref')->all());
    }

    /** والتحرير قبل الاعتماد يُسجَّل «تعديلاً» لا «قبولاً» — فيصير القياس صادقاً. */
    public function test_editing_before_approval_is_recorded_as_edit(): void
    {
        $this->seed(PermissionSeeder::class);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-EDT-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'محالة للمحامي', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص النموذج.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ]);
        $run = AiRun::create([
            'task_type' => 'ticket.summary', 'entity_ref' => $ticket->number,
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        $this->actingAs($lawyer)->post("/lawyer/summary/{$ticket->getRouteKey()}/approve", [
            'case_summary' => 'نصّ حرّره المحامي بنفسه.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
        ])->assertRedirect();

        $this->assertSame(AiReviewAction::Edit, $run->fresh()->review_action);
    }
}
