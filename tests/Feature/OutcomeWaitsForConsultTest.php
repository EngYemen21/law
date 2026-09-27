<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **لا قرارَ مآلٍ والاستشارة قائمة** (ملاحظة المالك 2026-09-27).
 *
 * اعتُمد الملخّص، وطلب العميل استشارةً فسُعّرت ودُفعت — ثمّ رفع المحامي مسار «استشارة» من البطاقة،
 * فقفزت التذكرة إلى «بانتظار اعتماد الإدارة للمسار» والاستشارة ماضية، وكان اعتمادُه سيعيدها إلى
 * «بانتظار حجز الاستشارة» ويطلب الحجز ثانيةً من عميلٍ دفع. القرار بعد الجلسة.
 */
class OutcomeWaitsForConsultTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** @return array{Ticket, User, User} */
    private function ticketWithPaidConsult(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->ticketWithApprovedOpinion($client, ['assigned_lawyer_id' => $lawyer->id]);

        $this->requestPricedAndPaid($client, $ticket);

        return [$ticket->fresh(), $lawyer, $client];
    }

    public function test_no_track_can_be_proposed_while_a_paid_consult_is_open(): void
    {
        [$ticket, $lawyer] = $this->ticketWithPaidConsult();
        $before = $ticket->status;

        foreach (TicketOutcomeTrack::cases() as $track) {
            $this->actingAs($lawyer)->post(route('lawyer.tickets.track.propose', $ticket), [
                'track' => $track->value,
                'reason' => 'رفع مقترح المسار أثناء قيام الاستشارة المدفوعة.',
            ])->assertStatus(422);
        }

        $fresh = $ticket->fresh();
        $this->assertSame($before, $fresh->status, 'تغيّرت حالة التذكرة والاستشارة قائمة');
        $this->assertNull($fresh->proposed_track);
        $this->assertSame(0, JourneyTransition::where('transition', 'ticket.propose_outcome_track')->count());
    }

    public function test_admin_cannot_approve_a_track_while_a_consult_is_open_even_with_a_waiver(): void
    {
        [$ticket] = $this->ticketWithPaidConsult();

        $this->actingAs($this->journeyAdmin())->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Consultation->value,
            'reason' => 'اعتماد مسار الاستشارة والاستشارة مدفوعة سلفاً.',
            OutcomeSummaryGate::WAIVER => 'سبب تجاوز لا يرفع مانع الاستشارة القائمة.',
        ])->assertStatus(422);

        $this->assertNull($ticket->fresh()->approved_track);
    }

    public function test_the_decision_card_is_told_why(): void
    {
        [$ticket] = $this->ticketWithPaidConsult();
        $consult = $ticket->consults()->latest('id')->first();

        $blocker = $ticket->trackGovernance()['consultBlocker'] ?? null;

        $this->assertNotNull($blocker);
        $this->assertStringContainsString($consult->ref, $blocker);
    }

    public function test_the_decision_opens_once_the_consult_is_cancelled(): void
    {
        [$ticket, $lawyer] = $this->ticketWithPaidConsult();
        $ticket->consults()->update(['status' => 'ملغاة']);

        $this->assertNull(OutcomeSummaryGate::consultBlocker($ticket->fresh()));

        $this->actingAs($lawyer)->post(route('lawyer.tickets.track.propose', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'بعد إلغاء الاستشارة: رفع مقترح القضية أمام المحكمة المختصة.',
        ])->assertRedirect();

        $this->assertSame(TicketOutcomeTrack::Case->value, $ticket->fresh()->proposed_track);
    }
}
