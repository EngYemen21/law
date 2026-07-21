<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من حجز موعد الاستشارة من داخل محادثة التذكرة (مرحلة «بانتظار حجز الاستشارة»).
 */
class ConsultBookingTest extends TestCase
{
    use RefreshDatabase;

    private function ticketReadyToBook(User $client): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-7777',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة القحطاني',
            'status' => 'بانتظار حجز الاستشارة',
            'tone' => 'b-amber',
        ]);
    }

    public function test_client_books_consultation_from_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:30',
        ])->assertNoContent();

        // أُنشئ موعد حقيقي مرتبط بالتذكرة
        $appt = Appointment::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($appt);
        $this->assertSame($client->id, $appt->user_id);
        $this->assertSame('استشارة مرئية', $appt->type);
        $this->assertSame('11:30', $appt->time);
        $this->assertSame('أ. سارة القحطاني', $appt->lawyer);
        $this->assertSame($lawyer->id, $appt->lawyer_id);

        // أُنشئ سجلّ استشارة حقيقي (CN-) مرتبط بالتذكرة والموعد — يظهر في «استشاراتي»
        $consult = Consult::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($consult);
        $this->assertStringStartsWith('CN-', $consult->ref);
        $this->assertSame('مرئية', $consult->channel);
        $this->assertSame($appt->id, $consult->appointment_id);
        $this->assertSame(450, $consult->price);
        $this->assertSame(518, $consult->total);
        $this->assertSame('بانتظار الجلسة', $consult->session);

        // تقدّمت التذكرة إلى «موعد مؤكد» + رسالة بطاقة الموعد + إشعار
        $ticket->refresh();
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'مواعيد'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // يظهر الموعد في صفحة مواعيد العميل
        $this->actingAs($client)->get(route('appointments'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('appointments', 1));
    }

    public function test_office_booking_creates_appointment_with_real_time(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية', 'branch' => 'فرع الرياض']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'office', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '13:00',
        ])->assertNoContent();

        $appt = Appointment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('استشارة حضورية', $appt->type);
        $this->assertSame($lawyer->id, $appt->lawyer_id);
        $this->assertNotNull($appt->starts_at);        // وقت حقيقي (يفعّل منع التعارض)
        $this->assertNotEmpty($appt->branch);          // موقع المكتب الافتراضي
    }

    public function test_booking_validates_type(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'invalid', 'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertSessionHasErrors('type');
    }

    public function test_other_client_cannot_book_on_foreign_ticket(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($owner);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $this->actingAs($intruder)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'lawyer_id' => $lawyer->id, 'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '10:00',
        ])->assertForbidden();
    }

    /**
     * الحارسة الجديدة على book: لا يمكن تأكيد موعد على تذكرة في مرحلة نهائية
     * (مكتملة/مغلقة/بانتظار اعتماد النتيجة/بانتظار اعتماد الإدارة) — يمنع العميل
     * من إعادة فتح تذكرة مغلقة أو تجاوز بوابات الاعتماد.
     */
    private function assertBookRejectedForStatus(string $status): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = $this->ticketReadyToBook($client);
        $ticket->update(['status' => $status]);

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'lawyer_id' => $lawyer->id,
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '10:00',
        ])->assertStatus(422);

        // لا يُنشَأ موعد ولا تُكتب رسالة عند الرفض (الحارسة قبل أي أثر جانبي)
        $this->assertSame(0, Appointment::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, Consult::where('ticket_id', $ticket->id)->count());
        $this->assertFalse($ticket->fresh()->messages->contains(fn ($m) => $m->role === 'مواعيد'));
        // الحالة الأصلية محفوظة (لم تُكتب 'موعد مؤكد')
        $this->assertSame($status, $ticket->fresh()->status);
    }

    public function test_book_rejects_completed_ticket(): void
    {
        $this->assertBookRejectedForStatus('مكتملة');
    }

    public function test_book_rejects_closed_ticket(): void
    {
        $this->assertBookRejectedForStatus('مغلقة');
    }

    public function test_book_rejects_pending_result_approval_ticket(): void
    {
        $this->assertBookRejectedForStatus('بانتظار اعتماد النتيجة');
    }

    public function test_book_rejects_pending_admin_approval_ticket(): void
    {
        $this->assertBookRejectedForStatus('بانتظار اعتماد الإدارة');
    }

    public function test_book_allows_reschedule_from_already_confirmed_appointment(): void
    {
        // إعادة جدولة موعد مسموحة: التذكرة في 'موعد مؤكد' بالفعل، فيقبل الحجز الجديد.
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketReadyToBook($client);
        $ticket->update(['status' => 'موعد مؤكد']);

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'lawyer_id' => $lawyer->id,
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '12:00',
        ])->assertNoContent();

        $ticket->refresh();
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertSame(1, Appointment::where('ticket_id', $ticket->id)->count());
    }
}
