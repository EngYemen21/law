<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مسار الاستشارة المعتمد بتجاوز الملخّص يُحجز** (قرار المالك 2026-09-30).
 *
 * ثبت في المتصفّح (SB-2026-1515): الإدارة العليا اعتمدت «طلب استشارة قانونية» بسبب تجاوزٍ مكتوب، فوصل
 * العميلَ «يمكنك الآن حجز الموعد» — ثمّ ردّ الحجزَ حارسُ `consultRequestBlocker` (422) لأنّه يشترط ملخّصاً
 * معتمداً، فعلق العميل بلا مخرج ولا سببٍ مقروء.
 */
class ConsultTrackWaiverBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CW-'.uniqid(), 'type' => 'استفسار عقد إيجار',
            'status' => TicketStatus::AwaitingDocs->value, 'tone' => 'b-amber',
        ]);
    }

    private function approveConsultationWithWaiver(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $this->ticket), [
            'track' => TicketOutcomeTrack::Consultation->value,
            'reason' => 'يحتاج العميل جلسة استشاريّة لبحث خيارات إنهاء العقد.',
            OutcomeSummaryGate::WAIVER => 'الطلب واضح ولا يحتاج ملخّصاً قبل الاستشارة.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::AwaitingBooking->value, $this->ticket->fresh()->status);
    }

    public function test_the_client_books_after_a_waived_consultation_track(): void
    {
        $this->approveConsultationWithWaiver();

        $this->actingAs($this->client)->postJson(route('tickets.book', $this->ticket), ['type' => 'video'])->assertSuccessful();

        $this->assertSame(1, Consult::where('ticket_id', $this->ticket->id)->count());
    }

    public function test_the_ticket_is_offered_on_the_booking_page(): void
    {
        $this->approveConsultationWithWaiver();

        $this->assertNull(TicketJourney::consultRequestBlocker($this->ticket->fresh()));
    }

    /** بلا قرار مسارٍ وبلا ملخّصٍ معتمد يبقى الرفض كما هو — وبسببٍ مقروء. */
    public function test_without_a_track_decision_the_summary_is_still_required(): void
    {
        $this->ticket->update(['status' => TicketStatus::AwaitingBooking->value]);

        $this->actingAs($this->client)->postJson(route('tickets.book', $this->ticket), ['type' => 'video'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type' => 'تُطلب الاستشارة بعد اعتماد الإدارة لملخّص الملفّ.']);

        $this->assertSame(0, Consult::where('ticket_id', $this->ticket->id)->count());
    }

    /** قرارٌ بمسارٍ آخر لا يفتح الحجز. */
    public function test_another_track_does_not_open_booking(): void
    {
        $this->ticket->update([
            'status' => TicketStatus::AwaitingBooking->value,
            'approved_track' => TicketOutcomeTrack::Close->value, 'approved_track_at' => now(),
        ]);

        $this->assertNotNull(TicketJourney::consultRequestBlocker($this->ticket->fresh()));
    }
}
