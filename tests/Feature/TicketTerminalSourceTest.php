<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **«انتهت التذكرة؟» حكمُ الخادم وحده** — في الصفحة وفي البثّ. كانت صفحة العميل تقارن قائمةً داخليّة
 * بتسمية العميل فلا تصدق، وصفحة التذاكر تحتفظ بقائمةٍ تخالف الخادم في «مكتملة».
 */
class TicketTerminalSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_status_broadcast_carries_the_server_verdict(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        foreach (TicketStatus::cases() as $status) {
            $ticket = Ticket::create([
                'user_id' => $client->id, 'number' => 'SB-TRM-'.uniqid(), 'type' => 'نزاع',
                'status' => $status->value, 'tone' => TicketJourney::toneFor($status->value),
            ]);

            $this->assertSame($status->isTerminal(), (new TicketStatusBroadcast($ticket))->broadcastWith()['isTerminal'], $status->value);
        }
    }

    public function test_no_screen_keeps_its_own_terminal_list(): void
    {
        $this->assertStringNotContainsString('TERMINAL_STATUSES =', (string) file_get_contents(resource_path('js/pages/tickets.tsx')));
        $this->assertStringNotContainsString(
            "['مكتملة', 'مغلقة', 'محولة إلى قضية', 'محولة إلى تنفيذ']",
            (string) file_get_contents(resource_path('js/pages/ticketchat.tsx'))
        );
    }
}
