<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
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

    /** يقود التذكرة عبر الدورة الكاملة حتى تصبح جاهزة لاختيار الموعد (طلب → تسعير → دفع). */
    private function driveToPaid(User $client, Ticket $ticket, string $type = 'phone'): Consult
    {
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => $type])->assertSuccessful();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    public function test_request_creates_unpriced_pending_consult_with_no_appointment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketFor($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'phone'])->assertSuccessful();

        $consult = Consult::firstOrFail();
        $this->assertSame('بانتظار التسعير', $consult->status);
        $this->assertNull($consult->appointment_id);
        $this->assertNull($consult->priced_at);
        $this->assertNull($consult->paid_at);
        $this->assertSame(0, Appointment::count());
        // التذكرة لم تتحوّل لموعد مؤكد بعد
        $this->assertNotSame('موعد مؤكد', $ticket->fresh()->status);
    }

    public function test_slot_selection_blocked_before_payment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketFor($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'phone'])->assertSuccessful();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        // اختيار الموعد قبل السداد → مرفوض، ولا يُنشأ أي موعد
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_booking_binds_chosen_lawyer_and_real_time_after_payment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية', 'branch' => 'فرع الرياض']);
        $ticket = $this->ticketFor($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $consult = $this->driveToPaid($client, $ticket);
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);

        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);   // المستشار المختار
        $this->assertSame('فرع الرياض', $consult->branch);              // فرعه (عزل)
        $this->assertNotNull($consult->starts_at);
        $this->assertSame('جديدة', $consult->status);                   // دخل رحلة المعالجة
        $this->assertSame($ticket->id, $consult->ticket_id);
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
    }

    public function test_double_booking_same_lawyer_slot_is_rejected(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $c1 = User::factory()->create(['role' => Role::Client]);
        $t1 = $this->ticketFor($c1);
        $consult1 = $this->driveToPaid($c1, $t1);
        $this->actingAs($c1)->post(route('consults.schedule', $consult1), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertRedirect();

        // عميل آخر يحاول نفس الفترة → المختصّ الوحيد مشغول، فلا بديل متاح ويُرفض
        $c2 = User::factory()->create(['role' => Role::Client]);
        $t2 = $this->ticketFor($c2);
        $consult2 = $this->driveToPaid($c2, $t2);
        $this->actingAs($c2)->post(route('consults.schedule', $consult2), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertSessionHasErrors('time');

        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());
    }
}
