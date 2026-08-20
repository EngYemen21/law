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
 * Rule موحَّد (ActiveLawyer) يحمي كل مسارات اختيار المحامي من تمرير
 * عميل/موظف/إداري أو محامٍ موقوف أو محامٍ خارج الفرع المسموح.
 *
 * يغطّي أربعة مسارات: tickets.book، book.store، employee.schedule.store، employee.transfer.do.
 */
class LawyerValidationTest extends TestCase
{
    use RefreshDatabase;

    private function activeLawyer(): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'department' => 'القضايا التجارية',
        ]);
    }

    private function ticketReadyToBook(User $client): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-'.uniqid(),
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية',
            'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber',
        ]);
    }

    // اختيار المحامي انتقل إلى خطوة الجدولة (بعد السداد)؛ فالتحقّق من المحامي يُفحص هناك.
    // يقود التذكرة/الحجز المباشر إلى «بانتظار تحديد الموعد» ليُختبر المحامي عند consults.schedule.
    private function paidConsultForTicket(User $client): Consult
    {
        $ticket = $this->ticketReadyToBook($client);
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    private function paidConsultDirect(User $client): Consult
    {
        $this->actingAs($client)->post(route('book.store'), ['type' => 'phone', 'specialty' => 'القضايا التجارية'])->assertRedirect();
        $consult = Consult::where('user_id', $client->id)->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 350])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    private function scheduleWith(User $client, Consult $consult, int $lawyerId)
    {
        return $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyerId,
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '11:00',
        ]);
    }

    // ── consults.schedule (اختيار المستشار من التذكرة) ──

    public function test_book_accepts_active_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);

        $consult = $this->paidConsultForTicket($client);
        $this->scheduleWith($client, $consult, $lawyer->id)->assertRedirect();
    }

    // العميل لم يعد يختار المحامي عند الجدولة؛ النظام يُسند أعلى مختصّ متاح ويتجاهل أيّ lawyer_id وارد.
    // فحقن عميل/موظف/محامٍ موقوف مستحيل الأثر بنيويّاً — يُسنَد المختصّ النشط لا المحقون.
    public function test_schedule_ignores_client_lawyer_id_and_auto_assigns_specialist(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $specialist = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);

        $consult = $this->paidConsultForTicket($client);
        // حقن معرّف محامٍ موقوف — يُتجاهَل، ويُسنَد المختصّ النشط
        $this->scheduleWith($client, $consult, $suspended->id)->assertRedirect();

        $appt = Appointment::where('user_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame($specialist->id, $appt->lawyer_id);
    }

    // ── employee.schedule.store (الموظف نيابةً عن عميل — محامٍ نشط فقط) ──

    public function test_employee_schedule_rejects_non_lawyer_as_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $anotherEmployee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office',
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '13:00',
            'lawyer_id' => $anotherEmployee->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_employee_schedule_accepts_active_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->activeLawyer();

        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office',
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '13:00',
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect();
    }

    // ── employee.transfer.do (تحويل تذكرة — محامي الوجهة نشط) ──

    public function test_transfer_rejects_suspended_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $suspended = User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'suspended', ]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-T-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد المعالجة',
        ]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $suspended->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_transfer_rejects_client_as_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $anotherClient = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-T-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد المعالجة',
        ]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $anotherClient->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_transfer_accepts_active_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->activeLawyer();
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-T-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد المعالجة',
        ]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect();
    }

    // ── admin.distribute.assign (الإدارة — محامٍ نشط فقط) ──

    public function test_admin_distribute_rejects_suspended_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد المعالجة',
        ]);

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), [
            'lawyer_id' => $suspended->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_admin_distribute_rejects_non_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد المعالجة',
        ]);

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), [
            'lawyer_id' => $client->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    // ── admin.consults.refer (إحالة استشارة — محامٍ نشط فقط) ──
    // آخر route مسجَّل باسم consults.refer هو ضمن group الأدمن، فيكون اسمه الكامل admin.consults.refer.

    public function test_consult_refer_rejects_suspended_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'هاتفية', 'lawyer' => 'محامٍ افتراضي',
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد · 10ص',
            'status' => 'جاهزة للمحامي',
        ]);

        $this->actingAs($admin)->post(route('admin.consults.refer', $consult), [
            'lawyer_id' => $suspended->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_consult_refer_rejects_non_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'هاتفية', 'lawyer' => 'محامٍ افتراضي',
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد · 10ص',
            'status' => 'جاهزة للمحامي',
        ]);

        $this->actingAs($admin)->post(route('admin.consults.refer', $consult), [
            'lawyer_id' => $client->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    // ── admin.meetings.store (جدولة اجتماع — محامٍ نشط فقط) ──

    public function test_meeting_store_rejects_suspended_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع اختبار', 'type' => 'تخطيط',
            'lawyer_id' => $suspended->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_meeting_store_rejects_non_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع اختبار', 'type' => 'تخطيط',
            'lawyer_id' => $client->id,
        ])->assertSessionHasErrors('lawyer_id');
    }
}
