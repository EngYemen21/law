<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\LawyerApproveConsultSummary;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **قرار الحوكمة يقع مرّةً واحدة مهما تكرّر النداء.**
 *
 * رُصد حيّاً (2026-09-25): نقرتان **متزامنتان** على «رفع المقترح» أنتجتا انتقالين متطابقين
 * وإشعارين للإدارة عن قرارٍ واحد؛ ونظيرُهما في اعتماد المستشار للملخّص أنتج إشعارين **وصفرَ
 * أثرٍ في سجلّ الرحلة**. بينما اعتماد المآل والتسعير منيعان.
 *
 * **والسبب لم يكن قفلاً ناقصاً:** `Workflow::run` يقفل الصفّ بـ`lockForUpdate` **ويعيد قراءته**
 * قبل نداء `guard()`. فالمناعة تأتي من أن يفحص الحارسُ **الحقلَ الذي يكتبه انتقالُه**:
 *
 * - `ApproveOutcomeTrack` يفحص الحالة التي ينقل إليها ⇒ منيع.
 * - `ProposeOutcomeTrack` كان يفحص التجميد وصحّة المسار وطول التسبيب — ولا شيء ممّا يكتب.
 * - واعتماد المستشار لم يكن يمرّ بالمحرّك أصلاً.
 *
 * **ولماذا نداءان متتابعان لا متزامنان في الاختبار؟** لأنّ القفل يُسلسل المتزامنَين: الثاني
 * لا يبدأ حتى يلتزم الأوّل. فالتتابع هو **عين** ما يراه الطلب الثاني بعد القفل — وهو حتميّ
 * التكرار، بخلاف سباقٍ حقيقيّ لا يُعتمد عليه في اختبار.
 */
class GovernanceActionsAreIdempotentTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $client): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-IDEM-'.uniqid(),
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'status' => TicketStatus::Analyzing->value,
            'tone' => 'b-blue',
        ]);
    }

    private function endedConsult(User $client, User $lawyer): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'ref' => 'CN-IDEM-'.uniqid(),
            'subject' => 'استشارة اختبار',
            'channel' => 'مرئية',
            'status' => 'منتهية',
            'session' => SessionState::Ended->value,
            'lawyer' => $lawyer->name,
            'summary' => 'ملخّص الجلسة المعدّ من المستشار.',
        ]);
    }

    // ── اقتراح مسار المآل ──────────────────────────────────────────────────────

    public function test_proposing_the_same_track_twice_is_denied(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->ticket(User::factory()->create(['role' => Role::Client]));

        $payload = ['track' => TicketOutcomeTrack::Case->value, 'reason' => 'النزاع موضوعيّ ويستوجب قيد دعوى.'];

        Workflow::run(new ProposeOutcomeTrack, $ticket, $employee, $payload);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new ProposeOutcomeTrack, $ticket->fresh(), $employee, $payload);
    }

    public function test_a_repeated_proposal_leaves_one_transition_and_one_notification(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->ticket(User::factory()->create(['role' => Role::Client]));

        $payload = ['track' => TicketOutcomeTrack::Execution->value, 'reason' => 'بحوزة العميل سندٌ تنفيذيّ واضح.'];

        Workflow::run(new ProposeOutcomeTrack, $ticket, $employee, $payload);
        try {
            Workflow::run(new ProposeOutcomeTrack, $ticket->fresh(), $employee, $payload);
        } catch (TransitionDenied) {
            // مقصود
        }

        $this->assertSame(1, JourneyTransition::where('entity_type', 'Ticket')
            ->where('entity_id', $ticket->id)
            ->where('transition', 'ticket.propose_outcome_track')->count(), 'سُجّل انتقالان لقرارٍ واحد.');

        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->count(), 'وصل الإدارةَ إشعاران عن قرارٍ واحد.');
    }

    /** **وتصحيح المقترح يبقى مسموحاً** — المنع للتكرار المتطابق لا لتغيير الرأي. */
    public function test_changing_the_proposal_to_another_track_is_still_allowed(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->ticket(User::factory()->create(['role' => Role::Client]));

        Workflow::run(new ProposeOutcomeTrack, $ticket, $employee, [
            'track' => TicketOutcomeTrack::Case->value, 'reason' => 'مقترحٌ أوّل قابلٌ للتصحيح.',
        ]);
        Workflow::run(new ProposeOutcomeTrack, $ticket->fresh(), $employee, [
            'track' => TicketOutcomeTrack::Execution->value, 'reason' => 'تبيّن وجود سندٍ تنفيذيّ فيُصحَّح المقترح.',
        ]);

        $this->assertSame(TicketOutcomeTrack::Execution->value, $ticket->fresh()->proposed_track);
    }

    // ── اعتماد المستشار لملخّص الجلسة ───────────────────────────────────────────

    public function test_the_lawyer_summary_approval_is_recorded_in_the_journey(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->assertTrue(AiReviewOutcome::lawyerApproveConsultSummary($consult, $lawyer));

        $this->assertSame(1, JourneyTransition::where('entity_type', 'Consult')
            ->where('entity_id', $consult->id)
            ->where('transition', 'consult.lawyer_approve_summary')->count(),
            'اعتماد المستشار بلا أثرٍ في سجلّ الرحلة.');
    }

    public function test_a_repeated_lawyer_approval_notifies_the_admin_once(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->assertTrue(AiReviewOutcome::lawyerApproveConsultSummary($consult, $lawyer));
        $this->assertFalse(AiReviewOutcome::lawyerApproveConsultSummary($consult->fresh(), $lawyer),
            'قُبل اعتمادٌ ثانٍ لملخّصٍ اعتمده المستشار.');

        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->count(), 'وصل الإدارةَ إشعاران عن اعتمادٍ واحد.');
        $this->assertSame(1, JourneyTransition::where('entity_type', 'Consult')
            ->where('entity_id', $consult->id)
            ->where('transition', 'consult.lawyer_approve_summary')->count());
    }

    /** ولا يُعتمد ملخّصٌ لجلسةٍ لم تنعقد — الحارس القائم يبقى. */
    public function test_a_summary_of_an_unheld_session_is_not_approved(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(User::factory()->create(['role' => Role::Client]), $lawyer);
        $consult->forceFill(['session' => 'بانتظار الجلسة'])->saveQuietly();

        $this->assertFalse(AiReviewOutcome::lawyerApproveConsultSummary($consult->fresh(), $lawyer));
    }

    /** والانتقال نفسه يردّ التكرار حتى لو نُودي مباشرةً. */
    public function test_the_transition_itself_denies_a_second_approval(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(User::factory()->create(['role' => Role::Client]), $lawyer);

        Workflow::run(new LawyerApproveConsultSummary, $consult, $lawyer);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new LawyerApproveConsultSummary, $consult->fresh(), $lawyer);
    }
}
