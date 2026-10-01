<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **بطاقات المآل في محادثة العميل — حكم الخادم** (`Ticket::outcomeCards`).
 *
 * كانت الصفحة تقارن تسمية العميل («طلب مكتمل ومغلق») بالحالة الداخليّة («مغلقة») فلا تصدق أبداً،
 * فتذكرةٌ مغلقة أو محوَّلة بلا `approved_track` (قبل حقول الحوكمة 2026-09-17، أو بيانات تجريبيّة)
 * لم يرَ عميلها بطاقتها. أُثبت في المتصفّح على SB-2026-214.
 */
class ClientOutcomeCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    /** @param  array<string, mixed>  $extra */
    private function ticket(TicketStatus $status, array $extra = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $this->client->id, 'number' => 'SB-OC-'.uniqid(), 'type' => 'نزاع',
            'status' => $status->value, 'tone' => TicketJourney::toneFor($status->value),
        ], $extra));
    }

    public function test_a_legacy_closed_ticket_without_an_approved_track_shows_its_closure_card(): void
    {
        $ticket = $this->ticket(TicketStatus::Closed);

        $this->actingAs($this->client)->get(route('tickets.show', $ticket))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('ticket.status', 'طلب مكتمل ومغلق')
                ->where('ticket.outcomeCards', ['execution' => false, 'case' => false, 'closure' => true]));
    }

    public function test_a_legacy_converted_ticket_shows_its_case_card(): void
    {
        $this->assertSame(['execution' => false, 'case' => true, 'closure' => false], $this->ticket(TicketStatus::ConvertedToCase)->toCard()['outcomeCards']);
    }

    public function test_the_approved_track_alone_also_shows_the_card(): void
    {
        $ticket = $this->ticket(TicketStatus::ReadyForOutcome, ['approved_track' => 'close']);

        $this->assertTrue($ticket->toCard()['outcomeCards']['closure']);
    }

    public function test_an_execution_file_replaces_the_case_card(): void
    {
        $ticket = $this->ticket(TicketStatus::ConvertedToCase, ['approved_track' => 'case']);
        Execution::create(['user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'number' => 'EXE-OC-1', 'subject' => 'تنفيذ', 'stage' => 1, 'status' => 'جديد', 'tone' => 'b-blue']);

        $this->assertSame(['execution' => true, 'case' => false, 'closure' => false], $ticket->fresh()->toCard()['outcomeCards']);
    }

    public function test_an_open_ticket_shows_no_outcome_card_and_the_broadcast_carries_them(): void
    {
        $open = $this->ticket(TicketStatus::LegalOpinion);
        $this->assertSame(['execution' => false, 'case' => false, 'closure' => false], $open->toCard()['outcomeCards']);

        $closed = $this->ticket(TicketStatus::Closed);
        $this->assertTrue((new TicketStatusBroadcast($closed))->broadcastWith()['outcomeCards']['closure']);
    }

    public function test_the_client_page_compares_no_status_text(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/ticketchat.tsx'));

        foreach (["'مغلقة'", "'محولة إلى قضية'", "'محولة إلى تنفيذ'", "'الرأي القانوني'"] as $literal) {
            $this->assertStringNotContainsString($literal, $page, $literal);
        }
        $this->assertStringContainsString('status.cards.closure', $page);
    }
}
