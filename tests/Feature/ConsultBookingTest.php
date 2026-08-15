<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دورة حجز الاستشارة من داخل محادثة التذكرة (مطابقة للتصميم):
 * طلب («بانتظار التسعير») → تسعير الإدارة + فاتورة («بانتظار السداد») → دفع محاكى
 * («بانتظار تحديد الموعد») → اختيار الموعد → موعد + استشارة («جديدة») وتقدّم التذكرة.
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

    /** يقود التذكرة عبر الطلب → التسعير → الدفع، فتصبح جاهزة لاختيار الموعد. */
    private function driveToPaid(User $client, Ticket $ticket, string $type = 'video', int $price = 450): Consult
    {
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => $type])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => $price])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    public function test_full_booking_flow_creates_appointment_and_consult(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $consult = $this->driveToPaid($client, $ticket, 'video');

        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '11:30',
        ])->assertRedirect();

        // أُنشئ موعد حقيقي مرتبط بالتذكرة
        $appt = Appointment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame($client->id, $appt->user_id);
        $this->assertSame('استشارة مرئية', $appt->type);
        $this->assertSame('11:30', $appt->time);
        $this->assertSame('أ. سارة القحطاني', $appt->lawyer);
        $this->assertSame($lawyer->id, $appt->lawyer_id);

        // سجلّ الاستشارة (CN-) مرتبط بالتذكرة والموعد + مُسعّر ومدفوع
        $consult->refresh();
        $this->assertStringStartsWith('CN-', $consult->ref);
        $this->assertSame('مرئية', $consult->channel);
        $this->assertSame($appt->id, $consult->appointment_id);
        $this->assertSame(450, $consult->price);
        $this->assertSame(518, $consult->total);
        $this->assertSame('بانتظار الجلسة', $consult->session);
        $this->assertSame('جديدة', $consult->status);
        $this->assertNotNull($consult->paid_at);

        // تقدّمت التذكرة إلى «موعد مؤكد» + رسالة بطاقة الموعد + إشعارات (تسعير/دفع/تأكيد)
        $ticket->refresh();
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'مواعيد'));
        $this->assertSame(3, UserNotification::where('user_id', $client->id)->count());

        // يظهر الموعد في صفحة مواعيد العميل
        $this->actingAs($client)->get(route('appointments'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('appointments', 1));
    }

    public function test_admin_pricing_issues_invoice_and_moves_to_pending_payment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();
        $this->assertSame('بانتظار التسعير', $consult->status);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();

        $consult->refresh();
        $this->assertSame('بانتظار السداد', $consult->status);
        $this->assertNotNull($consult->priced_at);
        $this->assertNull($consult->paid_at);

        $invoice = Invoice::where('consult_id', $consult->id)->firstOrFail();
        $this->assertSame(518, $invoice->amount);
        $this->assertFalse($invoice->paid);
        $this->assertSame($client->id, $invoice->user_id);
    }

    public function test_pay_marks_invoice_paid_and_gates_scheduling(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();

        // قبل السداد: اختيار الموعد مرفوض
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '10:00',
        ])->assertStatus(422);
        $this->assertSame(0, Appointment::count());

        // السداد المحاكى
        ConsultBooking::markPaid($consult->fresh());
        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNotNull($consult->paid_at);
        $this->assertTrue(Invoice::where('consult_id', $consult->id)->firstOrFail()->paid);
    }

    public function test_office_booking_creates_appointment_with_real_time(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية', 'branch' => 'فرع الرياض']);
        $ticket = $this->ticketReadyToBook($client);
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        $consult = $this->driveToPaid($client, $ticket, 'office', 600);
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => '13:00',
        ])->assertRedirect();

        $appt = Appointment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('استشارة حضورية', $appt->type);
        $this->assertSame($lawyer->id, $appt->lawyer_id);
        $this->assertNotNull($appt->starts_at);
        $this->assertNotEmpty($appt->branch);
    }

    public function test_booking_validates_type(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'invalid'])
            ->assertSessionHasErrors('type');
    }

    public function test_other_client_cannot_book_on_foreign_ticket(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($owner);
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertForbidden();
    }

    public function test_duplicate_pending_request_rejected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        // طلب ثانٍ بينما الأول لم يكتمل → مرفوض (نداء axios ⇒ 422 بجسم أخطاء)
        $this->actingAs($client)->postJson(route('tickets.book', $ticket), ['type' => 'phone'])->assertStatus(422);
        $this->assertSame(1, Consult::where('ticket_id', $ticket->id)->count());
    }

    /**
     * الحارسة على الطلب: لا يُطلب حجز على تذكرة في مرحلة نهائية
     * (مكتملة/مغلقة/بانتظار اعتماد النتيجة/بانتظار اعتماد الإدارة).
     */
    private function assertBookRejectedForStatus(string $status): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);
        $ticket->update(['status' => $status]);

        // الواجهة تنادي هذه النقطة بـaxios (Accept: application/json) فيصل الرفض 422 بجسم أخطاء،
        // بينما طلب HTML عاديّ يُعاد توجيهه بأخطاء الجلسة — الاختبار يحاكي نداء الواجهة الفعليّ.
        $this->actingAs($client)->postJson(route('tickets.book', $ticket), ['type' => 'video'])->assertStatus(422);

        $this->assertSame(0, Consult::where('ticket_id', $ticket->id)->count());
        $this->assertFalse($ticket->fresh()->messages->contains(fn ($m) => $m->role === 'مواعيد'));
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
}
