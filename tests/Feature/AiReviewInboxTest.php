<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صندوق المراجعة الموحَّد — مخرجات تنتظر قرار إنسان، في مكان واحد وبعزل الدور.
 *
 * قبله: ملخّص التذكرة يُعتمد من شاشة المحامي، وتحليل الاستشارة من شاشة الموظف،
 * وتحليل التنفيذ بلا شاشة اعتماد أصلاً — فلا يعرف أحد كم مخرجاً ينتظر ولا أيّها
 * عالي الخطورة ولا كم بقي معلَّقاً.
 */
class AiReviewInboxTest extends TestCase
{
    use RefreshDatabase;

    private function aiRun(array $overrides = []): AiRun
    {
        $ticket = Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'SB-'.uniqid(), 'type' => 'نزاع', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);

        return AiRun::create(array_merge([
            'task_type' => 'consult',
            'entity_type' => $ticket::class,
            'entity_id' => $ticket->id,
            'entity_ref' => $ticket->number,
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_NEEDS_REVIEW,
            'trace_id' => (string) Str::uuid(),
        ], $overrides));
    }

    // ── ما يدخل الصندوق ──

    public function test_only_unreviewed_items_awaiting_review_are_listed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->aiRun(['entity_ref' => 'WAITING']);
        $this->aiRun(['entity_ref' => 'DONE', 'review_action' => AiReviewAction::Accept->value]);
        $this->aiRun(['entity_ref' => 'ACCEPTED_AUTO', 'status' => AiRun::STATUS_COMPLETED]);

        $refs = AiReviewInbox::forUser($admin)->pluck('entity_ref')->all();

        $this->assertSame(['WAITING'], $refs);
        $this->assertSame(1, AiReviewInbox::countFor($admin));
    }

    /** ما لا يُقاس أولاً: مخرج بلا ثقة مقيسة لا يُعرف خطره، فيتصدّر الطابور. */
    public function test_unmeasured_confidence_is_surfaced_before_low_confidence(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->aiRun(['entity_ref' => 'LOW', 'confidence' => 30]);
        $this->aiRun(['entity_ref' => 'UNMEASURED', 'confidence' => null]);
        $this->aiRun(['entity_ref' => 'HIGH', 'confidence' => 95]);

        $refs = AiReviewInbox::forUser($admin)->pluck('entity_ref')->all();

        $this->assertSame(['UNMEASURED', 'LOW', 'HIGH'], $refs);
    }

    // ── عزل الدور ──

    public function test_the_client_never_sees_the_review_inbox(): void
    {
        $this->aiRun();

        $client = User::factory()->create(['role' => Role::Client]);

        $this->assertCount(0, AiReviewInbox::forUser($client));
        $this->assertSame(0, AiReviewInbox::countFor($client));
    }

    public function test_the_employee_sees_tickets_and_consults_not_legal_drafts(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->aiRun(['task_type' => 'triage', 'entity_ref' => 'TRIAGE']);
        $this->aiRun(['task_type' => 'consult', 'entity_ref' => 'CONSULT']);
        $this->aiRun(['task_type' => 'execution', 'entity_ref' => 'EXEC']);

        $refs = AiReviewInbox::forUser($employee)->pluck('entity_ref')->all();

        sort($refs);
        $this->assertSame(['CONSULT', 'TRIAGE'], $refs, 'مسودّات التنفيذ ليست من عمل الموظّف');
    }

    public function test_the_lawyer_sees_only_what_was_escalated_or_already_touched(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);

        $this->aiRun(['entity_ref' => 'MINE', 'escalated_to' => $lawyer->id]);
        $this->aiRun(['entity_ref' => 'THEIRS', 'escalated_to' => $other->id]);
        $this->aiRun(['entity_ref' => 'UNASSIGNED']);

        $refs = AiReviewInbox::forUser($lawyer)->pluck('entity_ref')->all();

        $this->assertSame(['MINE'], $refs, 'المحامي لا يرى مخرجات ملفّات زملائه');
    }

    public function test_the_admin_sees_everything(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->aiRun(['task_type' => 'triage']);
        $this->aiRun(['task_type' => 'execution']);
        $this->aiRun(['task_type' => 'consult']);

        $this->assertSame(3, AiReviewInbox::countFor($admin));
    }

    // ── الفصل الذي يقيس الجودة ──

    /**
     * «قبول» و«تعديل ثم قبول» ليسا فعلاً واحداً: الأوّل يقول إن المخرج صحيح كما هو،
     * والثاني يقول إنه احتاج يد إنسان. دمجهما في زرّ «اعتماد» يُخفي مقياس الجودة.
     */
    public function test_edit_rate_separates_clean_outputs_from_corrected_ones(): void
    {
        $this->aiRun(['review_action' => AiReviewAction::Accept->value]);
        $this->aiRun(['review_action' => AiReviewAction::Accept->value]);
        $this->aiRun(['review_action' => AiReviewAction::Edit->value]);
        $this->aiRun(['review_action' => AiReviewAction::Edit->value]);

        $this->assertSame(0.5, AiReviewInbox::humanEditRate());
    }

    /** لا قياس ⇒ `null` لا صفر — الصفر يقول «لا تعديل» وهو ادّعاء بلا بيانات. */
    public function test_edit_rate_is_null_when_nothing_was_reviewed(): void
    {
        $this->assertNull(AiReviewInbox::humanEditRate());
    }

    // ── الرفض المنظَّم يصير بيانات ──

    public function test_rejection_reasons_aggregate_into_a_pattern(): void
    {
        $this->aiRun(['review_action' => AiReviewAction::Reject->value, 'review_reason' => AiReviewReason::UnsupportedCitation->value]);
        $this->aiRun(['review_action' => AiReviewAction::Reject->value, 'review_reason' => AiReviewReason::UnsupportedCitation->value]);
        $this->aiRun(['review_action' => AiReviewAction::Reject->value, 'review_reason' => AiReviewReason::Tone->value]);
        $this->aiRun(['review_action' => AiReviewAction::Accept->value]);

        $reasons = AiReviewInbox::rejectionReasons();

        $this->assertSame(2, $reasons[AiReviewReason::UnsupportedCitation->value]);
        $this->assertSame(1, $reasons[AiReviewReason::Tone->value]);
        $this->assertArrayNotHasKey(AiReviewReason::MissingFacts->value, $reasons, 'لا يُبلَّغ عمّا لم يقع');
    }

    // ── عقود الأفعال والأسباب ──

    public function test_only_rejection_demands_a_structured_reason(): void
    {
        $this->assertTrue(AiReviewAction::Reject->requiresReason());

        foreach ([AiReviewAction::Accept, AiReviewAction::Edit, AiReviewAction::Rerun, AiReviewAction::Escalate] as $action) {
            $this->assertFalse($action->requiresReason(), "«{$action->value}» لا يلزمه سبب");
        }
    }

    public function test_rerun_and_escalate_keep_the_review_open(): void
    {
        $this->assertFalse(AiReviewAction::Rerun->closesReview());
        $this->assertFalse(AiReviewAction::Escalate->closesReview());

        foreach ([AiReviewAction::Accept, AiReviewAction::Edit, AiReviewAction::Reject] as $action) {
            $this->assertTrue($action->closesReview());
        }
    }

    /** الاختلاق والاستشهاد بلا مصدر عاليا الخطورة: تراجعهما يمنع اعتماد نموذج جديد. */
    public function test_fabrication_and_unsupported_citation_are_flagged_high_risk(): void
    {
        $this->assertTrue(AiReviewReason::FabricatedFact->isHighRisk());
        $this->assertTrue(AiReviewReason::UnsupportedCitation->isHighRisk());
        $this->assertFalse(AiReviewReason::Tone->isHighRisk());
    }

    public function test_escalation_requires_an_assignee(): void
    {
        $this->assertTrue(AiReviewAction::Escalate->requiresAssignee());
        $this->assertFalse(AiReviewAction::Reject->requiresAssignee());
    }
}
