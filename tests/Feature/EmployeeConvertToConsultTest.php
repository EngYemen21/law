<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * «تحويل التذكرة إلى استشارة» — الموظف يُنشئ طلب تسعير نيابةً عن العميل بنفس حرّاس مسار
 * حجز العميل: رأيٌ قانونيّ اعتمدته الإدارة (2026-09-14)، ولا حالة نهائية، ولا ازدواج طلب.
 */
class EmployeeConvertToConsultTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** @return array{0: Ticket, 1: User, 2: User} */
    private function ticketWithEmployee(string $status = 'الرأي القانوني'): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-2026-7100',
            'type' => 'استشارة قانونية',
            'department' => 'القانون التجاري',
            'status' => $status,
        ]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        return [$ticket, $employee, $client];
    }

    public function test_employee_converts_ticket_to_consult_pricing_request(): void
    {
        [$ticket, $employee, $client] = $this->ticketWithEmployee();

        $this->actingAs($employee)
            ->post(route('employee.tickets.convert-consult', $ticket))
            ->assertNoContent();

        $consult = Consult::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($consult);
        $this->assertSame('بانتظار التسعير', $consult->status);
        $this->assertSame($client->id, $consult->user_id);
        $this->assertTrue($ticket->fresh()->messages->contains(
            fn ($m) => $m->who === 'staff' && str_contains($m->body, 'تحويل التذكرة إلى طلب استشارة')
        ));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id, 'icon' => 'cal']);
    }

    public function test_rejects_conversion_on_completed_ticket(): void
    {
        [$ticket, $employee] = $this->ticketWithEmployee('مكتملة');

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.convert-consult', $ticket))
            ->assertStatus(422);

        $this->assertSame(0, Consult::where('ticket_id', $ticket->id)->count());
    }

    /** **لا تحويلَ قبل اعتماد الرأي المبدئيّ** — تذكرةٌ قيد التحليل لم يكتمل ملخّصها. */
    public function test_rejects_conversion_before_the_opinion_is_approved(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7101', 'type' => 'استشارة قانونية',
            'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.convert-consult', $ticket))
            ->assertStatus(422);

        $this->assertSame(0, Consult::where('ticket_id', $ticket->id)->count());
    }

    public function test_rejects_duplicate_pending_consult(): void
    {
        [$ticket, $employee] = $this->ticketWithEmployee();

        $this->actingAs($employee)->post(route('employee.tickets.convert-consult', $ticket))->assertNoContent();
        $this->actingAs($employee)
            ->postJson(route('employee.tickets.convert-consult', $ticket))
            ->assertStatus(422);

        $this->assertSame(1, Consult::where('ticket_id', $ticket->id)->count());
    }
}
