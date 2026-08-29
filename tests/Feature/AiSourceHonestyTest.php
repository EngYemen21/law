<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الحارس الدائم للتمييز بين تحليل ذكاء اصطناعيّ فعليّ وقالبٍ احتياطيّ.
 *
 * كان القالب الاحتياطيّ في `analyzeExecution` يعود بنفس شكل النجاح حرفياً، فيضبط
 * `applyAnalysis` علم `ai_done=true` ويرفع المرحلة ويُشعر العميل بأن «الذكاء الاصطناعي
 * حلّل طلب التنفيذ» — والقالب لم يفحص مستنداً واحداً، إنما فحص وجود اسم المنفَّذ ضده.
 */
class AiSourceHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function execution(User $client, array $overrides = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EX-2026-'.uniqid(),
            'sanad' => 'حكم قضائي',
            'subject' => 'مطالبة مالية',
            'amount' => 50000,
            'defendant' => 'شركة المدى', // مذكور ⇒ القالب يعدّ الطلب «مكتملاً»
            'stage' => 1,
        ], $overrides));
    }

    // ── المزوّد غائب (phpunit يفرّغ المفاتيح) ⇒ القالب الاحتياطيّ هو ما يعود ──

    public function test_execution_analysis_without_provider_is_marked_fallback(): void
    {
        $exec = $this->execution(User::factory()->create(['role' => Role::Client]));

        $result = app(LegalAiService::class)->analyzeExecution($exec);

        $this->assertSame(AiSource::Fallback->value, $result['source']);
    }

    public function test_fallback_does_not_claim_ai_analysis_happened(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execution($client);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $fresh = $exec->fresh();
        $this->assertFalse((bool) $fresh->ai_done, 'القالب الاحتياطيّ لا يُوسَم تحليلاً مكتملاً');
        $this->assertSame(AiSource::Fallback->value, $fresh->ai_source);
    }

    public function test_fallback_does_not_advance_the_stage(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // بيانات «مكتملة» (المنفَّذ ضده مذكور) — كان هذا وحده يقفز بالطلب إلى المرحلة 2
        $exec = $this->execution($client);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $this->assertSame(1, (int) $exec->fresh()->stage, 'طلب لم يُفحص سنده لا ينتقل لبانتظار الدراسة');
    }

    // ── سياسة المكتب: العميل لا يُطلَع على العطل؛ المكتب وحده يراه ──

    public function test_client_is_not_told_about_the_failure(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execution($client);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $notification = UserNotification::where('user_id', $client->id)->latest('id')->first();
        $this->assertNotNull($notification);
        foreach (['تعذّر', 'الذكاء الاصطناعي', 'تحليل'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $notification->body);
        }
        $this->assertStringContainsString('قيد المراجعة', (string) $notification->body);

        // ولا في لوحته: last_action يُعرَض للعميل في dashboard.tsx
        $this->assertStringNotContainsString('تعذّر', (string) $exec->fresh()->last_action);
    }

    public function test_failure_note_is_internal_and_hidden_from_the_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execution($client);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));
        $exec = $exec->fresh()->load('messages');

        $note = $exec->messages->firstWhere('who', 'note');
        $this->assertNotNull($note, 'التعذّر يُدوَّن ملاحظةً داخليّة');
        $this->assertSame('تعذّر التحليل الذكيّ', $note->role);
        $this->assertStringContainsString('لم يُفحص أي مستند', (string) $note->body);

        // بطاقة العميل لا تحمل الملاحظة؛ وبطاقة المكتب تحملها
        $clientBodies = collect($exec->toFlowCard(false)['messages'])->pluck('text')->implode(' ');
        $officeBodies = collect($exec->toFlowCard(true, true)['messages'])->pluck('text')->implode(' ');
        $this->assertStringNotContainsString('لم يُفحص أي مستند', $clientBodies);
        $this->assertStringContainsString('لم يُفحص أي مستند', $officeBodies);
    }

    public function test_staff_and_admin_are_alerted_about_the_failure(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execution($client, ['assigned_lawyer_id' => $lawyer->id]);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        foreach ([$employee, $admin, $lawyer] as $staff) {
            $alert = UserNotification::where('user_id', $staff->id)->latest('id')->first();
            $this->assertNotNull($alert, "لم يُنبَّه {$staff->role->value}");
            $this->assertStringContainsString('تعذّر التحليل الذكيّ', (string) $alert->body);
        }
    }

    // ── نجاح فعليّ: العقد القديم يبقى كما هو بحذافيره ──

    public function test_real_analysis_keeps_the_previous_behaviour(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execution($client);

        ExecService::applyAnalysis($exec, [
            'summary' => 'سند تنفيذيّ مستوفٍ للشروط بعد فحص المرفقات.',
            'missing' => [],
            'procedures' => ['تقديم طلب تنفيذ إلكتروني'],
            'source' => AiSource::AiSuccess->value,
        ]);

        $fresh = $exec->fresh();
        $this->assertTrue((bool) $fresh->ai_done);
        $this->assertSame(AiSource::AiSuccess->value, $fresh->ai_source);
        $this->assertSame(2, (int) $fresh->stage);

        $notification = UserNotification::where('user_id', $client->id)->latest('id')->first();
        $this->assertStringContainsString('بالذكاء الاصطناعي', (string) $notification->body);
    }

    /** منادٍ قديم لا يمرّر `source` — لا ينكسر ويُعامَل كما كان (توافق خلفيّ). */
    public function test_caller_without_source_is_treated_as_real_analysis(): void
    {
        $exec = $this->execution(User::factory()->create(['role' => Role::Client]));

        ExecService::applyAnalysis($exec, [
            'summary' => 'ملخّص',
            'missing' => [],
            'procedures' => [],
        ]);

        $this->assertTrue((bool) $exec->fresh()->ai_done);
    }

    // ── سجلّ ai_runs ──

    public function test_every_execution_analysis_is_recorded_with_its_source(): void
    {
        $exec = $this->execution(User::factory()->create(['role' => Role::Client]));

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(AiSource::Fallback, $run->source);
        $this->assertSame(AiRun::STATUS_NEEDS_REVIEW, $run->status);
        $this->assertSame($exec->number, $run->entity_ref);
        $this->assertNotNull($run->trace_id);
        // لا نموذج ولا ثقة مختلقان: لم يُستدعَ نموذج أصلاً
        $this->assertNull($run->model);
        $this->assertNull($run->confidence);
        $this->assertSame('provider_unavailable', $run->failure_code);
    }

    // ── الاستشارات: نفس العقد ──

    public function test_consult_fallback_does_not_claim_analysis_nor_suggest_a_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        // محامٍ حقيقيّ موجود: كان الاحتياطيّ يلتقط أوّل اسم أبجدياً ويعرضه «مقترحاً»
        User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-9100', 'subject' => 'نزاع تجاري',
            'type' => 'تجاري', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة القحطاني',
            'status' => 'قيد مراجعة الموظف',
        ]);

        $this->actingAs($employee)->post(route('employee.consults.analyze', $consult))->assertRedirect();

        $consult->refresh();
        $this->assertFalse((bool) $consult->ai_done);
        $this->assertSame(AiSource::Fallback->value, $consult->ai_source);
        $this->assertSame('', $consult->ai_lawyer);

        // سجلّ التدقيق لا يقول «اكتمل»
        $entry = collect($consult->audit)->firstWhere('field', 'تحليل الفريق القانوني');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('تعذّر', (string) $entry['after']);
    }

    public function test_consult_fallback_alerts_staff_and_admin_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-9101', 'subject' => 'نزاع',
            'type' => 'تجاري', 'channel' => 'مرئية', 'lawyer' => '—', 'status' => 'قيد مراجعة الموظف',
        ]);

        $this->actingAs($employee)->post(route('employee.consults.analyze', $consult))->assertRedirect();

        foreach ([$employee, $admin] as $staff) {
            $this->assertTrue(
                UserNotification::where('user_id', $staff->id)->where('body', 'like', '%تعذّر التحليل الذكيّ%')->exists(),
                'المكتب يجب أن يُنبَّه'
            );
        }
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'العميل لا يُطلَع على العطل');

        $run = AiRun::where('task_type', 'consult')->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(AiSource::Fallback, $run->source);
    }

    public function test_ticket_triage_without_provider_is_recorded_as_fallback(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'استشارة قانونية',
            'details' => 'لديّ نزاع تجاري مع مورّد وأحتاج رأياً قانونياً.',
        ])->assertRedirect();

        $run = AiRun::where('task_type', 'triage')->latest('id')->first();
        $this->assertNotNull($run, 'كل فرز يُسجَّل بمصدره ولو كان احتياطياً');
        $this->assertSame(AiSource::Fallback, $run->source);
        $this->assertNull($run->model);
    }
}
