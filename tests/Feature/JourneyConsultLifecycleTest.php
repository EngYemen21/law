<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Payments\MoyasarGateway;
use App\Support\LawyerAvailability;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **الاستشارة والمال عبر المحرّك** (خطّة إعادة البناء 2026-09-14، الدفعة ٢).
 *
 * كلّ اختبارٍ يُعيد إنتاج عطلٍ من سجلّ الأعطال بخطواته، ويُسمّيه برقمه.
 */
class JourneyConsultLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    private function consult(array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $this->client->id, 'ref' => 'CN-LC-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ], $extra));
    }

    private function priced(array $extra = []): Consult
    {
        $consult = $this->consult($extra);
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/price", ['price' => 500])->assertRedirect();

        return $consult->fresh();
    }

    /** @return array<string, mixed> */
    private function payment(Invoice $invoice, string $id = 'pay_lc_1'): array
    {
        return [
            'id' => $id, 'status' => 'paid', 'amount' => $invoice->amount * 100, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => $invoice->number],
        ];
    }

    private function adminRefundAlerts(): int
    {
        return UserNotification::where('user_id', $this->admin->id)->where('body', 'like', '%استرداد%')->count();
    }

    // ── ع١: سداد فاتورة استشارة ملغاة ──

    public function test_cancelling_a_request_cancels_its_unpaid_invoice(): void
    {
        $consult = $this->priced();
        $invoice = Invoice::where('consult_id', $consult->id)->sole();

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/cancel-request")->assertRedirect();

        $this->assertSame('ملغاة', $consult->fresh()->status);
        $this->assertSame('ملغاة', $invoice->fresh()->status);
        $this->assertFalse($invoice->fresh()->paid);
        // آخر سطرٍ في رحلة الاستشارة — التسعير قبله صار انتقالاً مسجَّلاً هو الآخر
        $this->assertSame('consult.cancel', JourneyTransition::where('entity_type', 'Consult')->where('entity_id', $consult->id)->latest('id')->value('transition'));
    }

    /** ع١: الدفعة الواصلة على فاتورةٍ ملغاة لا تُحيي الاستشارة، وتُنبَّه الإدارة للاسترداد. */
    public function test_a_gateway_payment_on_a_cancelled_request_revives_nothing(): void
    {
        $consult = $this->priced();
        $invoice = Invoice::where('consult_id', $consult->id)->sole();
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/cancel-request");

        $this->assertFalse(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment($this->payment($invoice->fresh())), 'webhook'));

        $this->assertSame('ملغاة', $consult->fresh()->status, 'الاستشارة الملغاة لا تعود إلى الجدولة');
        $this->assertNull($consult->fresh()->paid_at);
        $this->assertSame('ملغاة', $invoice->fresh()->status);
        $this->assertSame(1, $this->adminRefundAlerts(), 'المبلغ حُصّل فعلاً — الإدارة تعلم لتردّه');
    }

    public function test_a_cancelled_invoice_cannot_be_collected_manually_or_checked_out(): void
    {
        $consult = $this->priced();
        $invoice = Invoice::where('consult_id', $consult->id)->sole();
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/cancel-request");

        $this->assertFalse(PaymentReconciler::settleManual($invoice->fresh(), 'الإدارة'));
        $this->assertFalse($invoice->fresh()->paid);

        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.publishable_key' => 'pk_test']);
        $this->actingAs($this->client)->post(route('invoices.checkout', $invoice))->assertStatus(422);
    }

    // ── ع٢: فاتورة ألغاها تصحيح السعر ──

    public function test_paying_an_invoice_voided_by_repricing_settles_neither_invoice(): void
    {
        $consult = $this->priced();
        $old = Invoice::where('consult_id', $consult->id)->sole();

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/reprice")->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/price", ['price' => 700])->assertRedirect();
        $new = Invoice::where('consult_id', $consult->id)->whereKeyNot($old->id)->sole();

        $this->assertFalse(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment($this->payment($old->fresh())), 'callback'));

        $this->assertFalse($old->fresh()->paid, 'الملغاة لا تُسوّى');
        $this->assertFalse($new->fresh()->paid, 'ولا تُعلَّم الجديدة مدفوعةً بدفعةٍ لم تخصّها');
        $this->assertSame('بانتظار السداد', $consult->fresh()->status);
        $this->assertSame(1, $this->adminRefundAlerts());
    }

    public function test_paying_the_current_invoice_settles_exactly_that_invoice(): void
    {
        $consult = $this->priced();
        $old = Invoice::where('consult_id', $consult->id)->sole();
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/reprice");
        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/price", ['price' => 700]);
        $new = Invoice::where('consult_id', $consult->id)->whereKeyNot($old->id)->sole();

        $this->assertTrue(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment($this->payment($new->fresh(), 'pay_lc_new')), 'webhook'));

        $this->assertTrue($new->fresh()->paid);
        $this->assertSame('ملغاة', $old->fresh()->status);
        $this->assertFalse($old->fresh()->paid);
        $this->assertSame('بانتظار تحديد الموعد', $consult->fresh()->status);
        $this->assertSame(1, JourneyTransition::where('transition', 'consult.settle-payment')->count());
        $this->assertSame(0, $this->adminRefundAlerts());
    }

    // ── ع٤: بدء جلسة لطلب لم يُسعَّر ──

    public function test_a_request_still_in_booking_cannot_be_started(): void
    {
        $consult = $this->consult(['status' => 'بانتظار التسعير', 'starts_at' => null]);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/start")->assertStatus(422);

        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session);
        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);
        $this->assertSame(0, UserNotification::where('user_id', $this->client->id)->count(), 'لا «بدأت جلستك» لطلبٍ لم يُدفع');
        $this->assertFalse($consult->fresh()->isStartable(), 'والبطاقة لا تعرض الزرّ');
    }

    // ── ع١٠: الإلغاء يُرجع التذكرة ──

    public function test_cancelling_returns_the_ticket_to_legal_opinion_so_a_new_request_is_possible(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-LC-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber',
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'status' => 'approved', 'approved_at' => now(),
            'case_summary' => 'ملخّص', 'attachments_summary' => '—', 'facts' => 'وقائع', 'key_points' => 'توصيات',
        ]);
        $consult = $this->priced(['ticket_id' => $ticket->id]);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/cancel-request")->assertRedirect();

        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
    }

    // ── ع١٥: إعادة الجدولة وسط جلسة منعقدة ──

    public function test_a_live_session_cannot_be_rescheduled(): void
    {
        $consult = $this->consult([
            'status' => 'قيد الاستشارة', 'session' => 'جلسة جارية', 'meet_id' => '98765',
            'starts_at' => now(), 'when_label' => 'اليوم',
        ]);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/reschedule", ['reason' => 'client_request'])->assertStatus(422);

        $this->assertSame('98765', $consult->fresh()->meet_id, 'اجتماع Zoom القائم لا يُحذف');
        $this->assertSame('جلسة جارية', $consult->fresh()->session);
    }

    // ── ع١٩: التحليل على طلب قبل الجلسة ──

    public function test_a_request_still_in_booking_cannot_be_analyzed(): void
    {
        $consult = $this->consult(['status' => 'بانتظار التسعير']);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/analyze")->assertStatus(422);

        $this->assertSame('بانتظار التسعير', $consult->fresh()->status, 'لا يسقط من طابور التسعير');
    }

    // ── ع٩: الإحالة دون اعتماد التحليل ──

    public function test_referral_waits_for_the_analysis_to_be_approved(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult(['status' => 'بانتظار اعتماد الموظف', 'starts_at' => now()->addDay()]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $this->assertSame('بانتظار اعتماد الموظف', $consult->fresh()->status);
    }

    /** ع٢٧: الإحالة تزامن اسم المحامي مع معرّفه على التذكرة. */
    public function test_referral_syncs_both_lawyer_id_and_name_to_the_ticket(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي المحال إليه']);
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-RF-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'موعد مؤكد', 'tone' => 'b-green', 'assigned_lawyer' => 'قديم',
        ]);
        $consult = $this->consult(['status' => 'جاهزة للمحامي', 'ticket_id' => $ticket->id, 'starts_at' => now()->addDay()]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $this->assertSame('محالة للمحامي', $consult->fresh()->status);
        $this->assertSame($lawyer->id, $ticket->fresh()->assigned_lawyer_id);
        $this->assertSame('المحامي المحال إليه', $ticket->fresh()->assigned_lawyer);
    }

    // ── ع٢١: «انعقدت الجلسة» لعميلٍ لم يحضر ──

    public function test_a_no_show_does_not_let_the_ticket_announce_a_held_session(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        Permission::findOrCreate('إدارة التذاكر');
        $employee->givePermissionTo('إدارة التذاكر');

        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-NS-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'موعد مؤكد', 'tone' => 'b-green',
        ]);
        $this->consult([
            'ticket_id' => $ticket->id, 'status' => 'لم يحضر', 'session' => 'لم تُعقد',
            'starts_at' => now()->subDay(),
        ]);

        $this->actingAs($employee)->post("/employee/tickets/{$ticket->number}/advance")->assertStatus(422);

        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
        $this->assertFalse(
            $ticket->messages()->where('body', 'like', '%انعقدت الجلسة%')->exists(),
            'لا يُكتب للعميل انعقادُ جلسةٍ لم تنعقد'
        );
    }

    /** «لم يحضر» يمرّ بالمحرّك ويُسجَّل انتقالاً. */
    public function test_marking_a_no_show_is_recorded_as_a_transition(): void
    {
        $consult = $this->consult([
            'status' => 'جديدة', 'starts_at' => now()->subHours(3), 'duration_min' => 45,
        ]);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/no-show")->assertRedirect();

        $this->assertSame('لم تُعقد', $consult->fresh()->session);
        $this->assertSame('لم يحضر', $consult->fresh()->status);
        $this->assertSame('consult.no-show', JourneyTransition::where('entity_id', $consult->id)->value('transition'));
    }

    /** سببٌ فوق الحدّ يُرفض برسالةٍ عربيّة (لغة التطبيق إنجليزيّة) ولا يُلغى الطلب. */
    public function test_an_overlong_cancel_reason_is_refused_in_arabic(): void
    {
        $consult = $this->priced();

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/cancel-request", ['reason' => str_repeat('س', 501)])
            ->assertSessionHasErrors(['reason' => 'سبب الإلغاء أطول من 500 حرف — اختصره ثم أعد المحاولة.']);

        $this->assertNotSame('ملغاة', $consult->fresh()->status);
    }

    public function test_cancelling_a_request_records_reason_in_journey_transition_and_audit_log(): void
    {
        $consult = $this->priced();

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/cancel-request", [
                'reason' => 'طلب العميل الإلغاء — تعذر التنسيق',
            ])
            ->assertRedirect();

        $this->assertSame('ملغاة', $consult->fresh()->status);

        $transition = JourneyTransition::where('entity_type', 'Consult')->where('entity_id', $consult->id)->where('transition', 'consult.cancel')->sole();
        $this->assertSame('consult.cancel', $transition->transition);
        $this->assertSame('طلب العميل الإلغاء — تعذر التنسيق', $transition->reason);
        $this->assertSame(['reason' => 'طلب العميل الإلغاء — تعذر التنسيق'], $transition->payload);

        // تدقيق النموذج audit
        $audit = $consult->fresh()->audit;
        $this->assertNotEmpty($audit);
        $this->assertStringContainsString('طلب العميل الإلغاء — تعذر التنسيق', $audit[0]['after']);

        // سجل التدقيق العام
        $auditLog = AuditLog::where('auditable_id', $consult->id)->latest('id')->first();
        $this->assertNotNull($auditLog);
        $this->assertStringContainsString('طلب العميل الإلغاء — تعذر التنسيق', $auditLog->description);
    }

    public function test_cancelling_a_request_notifies_client_and_assigned_lawyer_with_reason(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->priced([
            'assigned_lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/cancel-request", [
                'reason' => 'بيانات الطلب غير مكتملة',
            ])
            ->assertRedirect();

        // إشعار العميل
        $clientNotif = UserNotification::where('user_id', $consult->user_id)->latest('id')->first();
        $this->assertNotNull($clientNotif);
        $this->assertStringContainsString('بيانات الطلب غير مكتملة', $clientNotif->body);

        // إشعار المحامي المسند
        $lawyerNotif = UserNotification::where('user_id', $lawyer->id)->latest('id')->first();
        $this->assertNotNull($lawyerNotif);
        $this->assertStringContainsString('بيانات الطلب غير مكتملة', $lawyerNotif->body);
        $this->assertStringContainsString($consult->ref, $lawyerNotif->body);
        $this->assertStringContainsString('إلغاء موعد جلسة الاستشارة', $lawyerNotif->body);
    }

    public function test_cancelling_a_paid_consult_triggers_admin_refund_alert(): void
    {
        $consult = $this->priced();
        $consult->update([
            'status' => 'بانتظار تحديد الموعد',
            'paid_at' => now(),
        ]);
        $invoice = Invoice::where('consult_id', $consult->id)->sole();
        $invoice->update([
            'status' => 'مسددة',
            'paid' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/cancel-request", [
                'reason' => 'طلب العميل الإلغاء لظرف طارئ',
            ])
            ->assertRedirect();

        $this->assertSame('ملغاة', $consult->fresh()->status);
        $this->assertSame(1, $this->adminRefundAlerts(), 'إلغاء الاستشارة المدفوعة يطلق تنبيه الاسترداد للإدارة فوراً');

        $adminNotif = UserNotification::where('user_id', $this->admin->id)
            ->where('body', 'like', '%استرداد%')
            ->latest('id')
            ->first();
        $this->assertNotNull($adminNotif);
        $this->assertStringContainsString($consult->ref, $adminNotif->body);
        $this->assertStringContainsString($invoice->number, $adminNotif->body);
    }

    public function test_cancelled_appointment_does_not_block_lawyer_busy_intervals(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $startsAt = now()->addDay()->setTime(10, 0);

        // موعد ملغي
        Appointment::create([
            'user_id' => $this->client->id,
            'ext_id' => 'AP-FREE-'.uniqid(),
            'lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
            'starts_at' => $startsAt,
            'duration_min' => 60,
            'status' => 'ملغي',
            'tone' => 'b-grey',
            'when_kind' => 'past',
            'type' => 'استشارة',
            'ico' => 'office',
            'day' => 'غد',
            'time' => '10ص',
            'place' => 'الرياض',
        ]);

        // استشارة ملغاة
        $this->consult([
            'assigned_lawyer_id' => $lawyer->id,
            'status' => 'ملغاة',
            'starts_at' => $startsAt->copy()->setTime(12, 0),
            'duration_min' => 60,
        ]);

        // اجتماع ملغى
        Meeting::create([
            'ref' => 'M-FREE-'.uniqid(),
            'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => $startsAt->copy()->setTime(14, 0),
            'dur' => '60 دقيقة',
            'status' => 'ملغى',
            'user_id' => $this->client->id,
            'title' => 'اجتماع تجريبي',
            'when_label' => 'غداً',
        ]);

        $busy = LawyerAvailability::busyIntervals($lawyer->id, $startsAt->toDateString());
        $this->assertEmpty($busy, 'المواعيد والاستشارات والاجتماعات الملغاة لا تحجز فترات انشغال للمحامي');
    }
}
