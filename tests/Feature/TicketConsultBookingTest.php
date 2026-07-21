<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجز الاستشارة الذكي من داخل صفحة التذكرة: تفرّغ المتخصّصين بقسم التذكرة (مرتّب) + حجز حقيقي
 * بالمعرّف ووقت فعلي (assigned_lawyer_id + starts_at) + منع الحجز المزدوج.
 */
class TicketConsultBookingTest extends TestCase
{
    use RefreshDatabase;

    private function ticketFor(User $client, string $dept = 'القضايا التجارية'): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-'.random_int(1000, 9999),
            'type' => 'نزاع تجاري',
            'department' => $dept,
            'status' => 'بانتظار حجز الاستشارة',
        ]);
    }

    public function test_ticket_availability_lists_specialists_of_ticket_department(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $match = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'العقارات']);
        $ticket = $this->ticketFor($client, 'القضايا التجارية');
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $res = $this->actingAs($client)->getJson(route('tickets.availability', $ticket).'?date='.$date);

        $res->assertOk();
        $ids = collect($res->json('lawyers'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));   // المتخصّص التجاري حاضر
        $this->assertFalse($ids->contains($other->id));  // العقاري مستبعَد
    }

    public function test_foreign_client_cannot_read_ticket_availability(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketFor($client);

        $this->actingAs($intruder)->getJson(route('tickets.availability', $ticket))->assertForbidden();
    }

    public function test_booking_binds_chosen_lawyer_and_real_time(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية', 'branch' => 'فرع الرياض']);
        $ticket = $this->ticketFor($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'phone', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertSuccessful();

        $consult = Consult::firstOrFail();
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);   // المستشار المختار
        $this->assertSame('فرع الرياض', $consult->branch);              // فرعه (عزل)
        $this->assertNotNull($consult->starts_at);
        $this->assertSame($ticket->id, $consult->ticket_id);
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
    }

    public function test_double_booking_same_lawyer_slot_is_rejected(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $c1 = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($c1)->post(route('tickets.book', $this->ticketFor($c1)), [
            'type' => 'phone', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertSuccessful();

        // عميل آخر يحاول نفس المستشار ونفس الفترة → يُرفض بحارس التعارض
        $c2 = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($c2)->post(route('tickets.book', $this->ticketFor($c2)), [
            'type' => 'phone', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertSessionHasErrors('starts_at');

        $this->assertSame(1, Consult::where('assigned_lawyer_id', $lawyer->id)->count());
    }
}
