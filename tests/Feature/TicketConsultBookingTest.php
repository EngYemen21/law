<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * حجز الاستشارة من داخل صفحة التذكرة: تفرّغ المتخصّصين بقسم التذكرة (مرتّب) + حجز حقيقي
 * بالمعرّف ووقت فعلي (assigned_lawyer_id + starts_at) + منع الحجز المزدوج.
 *
 * منذ 2026-09-14 يحدّد **الطاقم** الموعد بعد السداد، لا العميل.
 */
class TicketConsultBookingTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function ticketFor(User $client, string $dept = 'القضايا التجارية'): Ticket
    {
        return $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-'.random_int(1000, 9999),
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
        $date = now()->addDays(2)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'phone'])->assertSuccessful();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        // حجز الطاقم قبل السداد → مرفوض، ولا يُنشأ أي موعد
        $this->adminPublishes($consult, [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_booking_binds_chosen_lawyer_and_real_time_after_payment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketFor($client);
        $date = now()->addDays(2)->toDateString();

        $consult = $this->requestPricedAndPaid($client, $ticket, 'phone');
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);

        $this->adminPublishes($consult, [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertOk();

        $consult->refresh();
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);   // المستشار المختار
        $this->assertNotNull($consult->starts_at);
        $this->assertSame('جديدة', $consult->status);                   // دخل رحلة المعالجة
        $this->assertSame($ticket->id, $consult->ticket_id);
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
    }

    /**
     * **لا يُحجز المحامي مرّتين.**
     *
     * الحجز بيد الطاقم (2026-09-14): الحجز الثاني على الخانة نفسها يُردّ برسالةٍ تطلب وقتاً آخر،
     * وتبقى الاستشارة الثانية مدفوعةً بانتظار موعدها — لا تُسنَد لمحامٍ مشغول ولا لإداريّ.
     */
    public function test_a_second_booking_never_double_books_the_lawyer(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $date = now()->addDays(2)->toDateString();

        $c1 = User::factory()->create(['role' => Role::Client]);
        $consult1 = $this->requestPricedAndPaid($c1, $this->ticketFor($c1), 'phone');
        $this->adminPublishes($consult1, [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertOk();

        $c2 = User::factory()->create(['role' => Role::Client]);
        $consult2 = $this->requestPricedAndPaid($c2, $this->ticketFor($c2), 'phone');
        $this->adminPublishes($consult2, [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:00',
        ])->assertStatus(422);

        // **الثابت:** موعدٌ واحدٌ للمحامي مهما تعدّد الطالبون
        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());
        $this->assertNotSame($lawyer->id, $consult2->fresh()->assigned_lawyer_id, 'ولا يُسنَد المحامي المشغول');
        $this->assertSame('بانتظار تحديد الموعد', $consult2->fresh()->status, 'والثانية تنتظر موعداً آخر');
    }
}
