<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بعد تأكيد الدفع، يعود العميل لدردشة التذكرة (حيث لوحة اختيار الموعد) بدل صفحة «استشاراتي»،
 * وإن لم ترتبط الاستشارة بتذكرة فإلى «استشاراتي». يُختبر عبر فرع «الفاتورة مدفوعة أصلاً» (بلا بوّابة).
 */
class ConsultPayRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function paidConsult(User $client, ?Ticket $ticket): Consult
    {
        $consult = Consult::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket?->id,
            'ref' => 'CS-26-9001',
            'subject' => 'نزاع تجاري',
            'lawyer' => 'أ. سارة القحطاني',
            'channel' => 'هاتفية',
            'status' => 'بانتظار السداد',
            'when_label' => 'قيد التحديد',
        ]);
        Invoice::create([
            'user_id' => $client->id,
            'consult_id' => $consult->id,
            'number' => 'INV-CS-9001',
            'description' => 'أتعاب استشارة',
            'amount' => 402,
            'status' => 'مدفوعة',
            'tone' => 'b-green',
            'due_label' => 'الآن',
            'paid' => true,
        ]);

        return $consult;
    }

    public function test_redirects_to_ticket_chat_when_consult_has_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-4001', 'type' => 'نزاع تجاري',
            'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber', 'last_message' => '—', 'date_label' => 'الآن',
        ]);
        $consult = $this->paidConsult($client, $ticket);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult))
            ->assertRedirect(route('tickets.show', $ticket, absolute: false));
    }

    public function test_redirects_to_myconsults_when_no_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->paidConsult($client, null);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult))
            ->assertRedirect(route('myconsults', absolute: false));
    }
}
