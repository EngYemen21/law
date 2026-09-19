<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Models\AiRun;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewOutcome;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * **سلامةُ دورة القضيّة — إصلاحُ ما كشفه تقرير مراحلها (2026-09-11).**
 *
 * - التصنيف الذكيّ «عالي الحساسيّة» كان يُكتب في الملفّ ويصل العميل بلا مراجعة.
 * - للّائحة اعتمادان منفصلان: الزرّ يرفع الدعوى والنصّ محجوب، والصندوق يُطلق النصّ ولا يرفعها.
 * - الجلسات بلا حارسٍ على حالة القضيّة ولا على حالتها هي.
 */
class CaseLifecycleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function actors(): array
    {
        return [
            User::factory()->create(['role' => Role::Client]),
            User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']),
            User::factory()->create(['role' => Role::Admin]),
        ];
    }

    private function caseOf(User $client, User $lawyer, array $attrs = []): LegalCase
    {
        return LegalCase::create(array_merge([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'number' => 'CASE-INT-'.uniqid(), 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'status' => 'قيد التحضير', 'tone' => 'b-blue', 'fee' => 1000, 'fee_status' => 'paid',
            'pleading_status' => 'pending_lawyer',
        ], $attrs));
    }

    private function draft(LegalCase $case): void
    {
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة',
            'body' => '<div class="draft">نصّ المسودّة</div>', 'withheld_at' => now(),
        ]);
    }

    // ═════ ١.١ التصنيف مقترحٌ ينتظر المراجعة ═════

    public function test_the_ai_classification_waits_for_review_before_touching_the_file(): void
    {
        [$client, $lawyer, $admin] = $this->actors();
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-INT-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'مكتملة',
        ]);
        $case = $this->caseOf($client, $lawyer, ['ticket_id' => $ticket->id]);
        $case->messages()->create(['who' => 'ai', 'name' => 'الفريق القانوني', 'role' => 'تحليل', 'body' => '<p>بيانات الطلب:</p>']);

        $ai = Mockery::mock(LegalAiService::class);
        $ai->shouldReceive('isConfigured')->andReturn(true);
        $ai->shouldReceive('available')->andReturn(true);
        $ai->shouldReceive('classifyCaseResult')->andReturn([
            'classification' => ['type' => 'نزاع عمالي', 'department' => 'القسم العمالي'],
            'meta' => [],
            'source' => AiSource::AiSuccess,
        ]);

        (new ClassifyConvertedCaseJob($case))->handle($ai);

        $case->refresh();
        // لا يمسّ الملفّ ولا ما يراه العميل قبل الاعتماد
        $this->assertSame('نزاع تجاري', $case->type);
        $this->assertSame('القسم التجاري', $case->department);
        $this->assertSame('<p>بيانات الطلب:</p>', $case->messages()->where('role', 'تحليل')->value('body'));
        $this->assertSame(['type' => 'نزاع عمالي', 'department' => 'القسم العمالي'], $case->ai_classification);

        $run = AiRun::where('task_type', 'case.classify')->latest('id')->firstOrFail();
        $this->assertSame(AiRun::STATUS_NEEDS_REVIEW, $run->status, 'عالي الحساسيّة ⇒ ينتظر إنساناً');

        // والقبول يطبّقه
        AiReviewOutcome::apply($run, AiReviewAction::Accept, $admin);

        $case->refresh();
        $this->assertSame('نزاع عمالي', $case->type);
        // يُحفظ القسم باسمه المعتمد في كتالوج الأقسام (2026-09-15)، ويبقى نصّ التحليل كما اقترحه النموذج
        $this->assertSame('القضايا العمالية', $case->department);
        $this->assertNull($case->ai_classification);
        $this->assertStringContainsString('القسم العمالي', (string) $case->messages()->where('role', 'تحليل')->value('body'));
    }

    // ═════ ١.٢ اعتمادٌ واحد للائحة ═════

    public function test_the_lawyer_cannot_approve_a_pleading_that_does_not_exist(): void
    {
        [$client, $lawyer] = $this->actors();
        $case = $this->caseOf($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);
        $this->assertSame('قيد التحضير', $case->fresh()->status, 'ولا يُبلَّغ العميل باعتماد لائحةٍ لا نصَّ لها');
    }

    public function test_the_lawyer_button_releases_the_draft_and_records_the_decision(): void
    {
        [$client, $lawyer] = $this->actors();
        $case = $this->caseOf($client, $lawyer);
        $this->draft($case);
        $run = AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => AiSource::AiSuccess->value,
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();

        $case->refresh();
        $this->assertSame('approved', $case->pleading_status);
        $this->assertSame('قيد التحضير', $case->status, 'الاعتماد لا يرفع الدعوى — الرفع والقيد في ناجز تاليان');
        $this->assertContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all(), 'النصّ يصل العميل مع الاعتماد');
        $this->assertSame(AiReviewAction::Accept, $run->fresh()->review_action, 'والقرار مسجَّلٌ على القيد لا معلَّق');
    }

    public function test_accepting_in_the_review_inbox_also_files_the_case(): void
    {
        [$client, $lawyer] = $this->actors();
        $case = $this->caseOf($client, $lawyer);
        $this->draft($case);
        $run = AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => AiSource::AiSuccess->value,
        ]);

        AiReviewOutcome::apply($run, AiReviewAction::Accept, $lawyer);

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status, 'القبول يعتمد النصّ ويُطلقه — والرفع والقيد في ناجز تاليان (الخطّة ب)');
        $this->assertSame('approved', $case->pleading_status);
        $this->assertContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all());
    }

    // ═════ ١.٣ حرّاس الجلسات ═════

    public function test_hearings_follow_the_case_and_their_own_state(): void
    {
        [$client, $lawyer] = $this->actors();
        $hearing = ['title' => 'الجلسة الأولى', 'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:00', 'court' => 'الدائرة التجارية'];

        // قبل رفع الدعوى: لا جلسات
        $preparing = $this->caseOf($client, $lawyer);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $preparing), $hearing)->assertStatus(422);

        // منظورة: تُضاف
        $case = $this->caseOf($client, $lawyer, ['status' => 'منظورة', 'pleading_status' => 'approved']);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), $hearing)->assertRedirect();
        $h = $case->hearings()->reorder('id', 'desc')->firstOrFail();

        // جلسةٌ منعقدة لا تُعاد جدولتها ولا تُلغى
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $h]), ['status' => 'منعقدة'])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $h]), [])->assertStatus(422);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.cancel', [$case, $h]))->assertStatus(422);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $h]), ['status' => 'مؤجلة'])->assertStatus(422);

        // والقضيّة المؤرشفة للقراءة
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), $hearing)->assertRedirect();
        $open = $case->hearings()->reorder('id', 'desc')->firstOrFail();
        $case->update(['status' => 'مؤرشفة']);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $open]), ['status' => 'منعقدة'])->assertStatus(422);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.cancel', [$case, $open]))->assertStatus(422);
        $this->assertSame('مجدولة', $open->fresh()->status);
    }
}
