<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * Rule موحَّد (ActiveLawyer) يحمي كل مسارات اختيار المحامي من تمرير
 * عميل/موظف/إداري أو محامٍ موقوف.
 *
 * يغطّي: حجز الطاقم (الإدارة والموظّف)، وتحويل التذكرة، والتوزيع، والإحالة، والاجتماعات.
 */
class LawyerValidationTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function activeLawyer(): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'department' => 'القضايا التجارية',
        ]);
    }

    /** اختيار المحامي بيد الطاقم بعد السداد (2026-09-14) — فالتحقّق يُفحص عند حجز الموعد. */
    private function paidConsultForTicket(User $client): Consult
    {
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-'.uniqid(), 'status' => 'بانتظار حجز الاستشارة',
        ]);

        return $this->requestPricedAndPaid($client, $ticket, 'video');
    }

    private function slotDate(): string
    {
        return now()->addDays(2)->toDateString();
    }

    // ── admin.schedule.store (الإدارة تحجز الموعد) ──

    public function test_staff_booking_accepts_active_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->activeLawyer();

        $consult = $this->paidConsultForTicket($client);
        $this->adminPublishes($consult, ['lawyer_id' => $lawyer->id, 'date' => $this->slotDate(), 'time' => '11:00'])
            ->assertOk();

        $this->assertSame($lawyer->id, $consult->fresh()->assigned_lawyer_id);
    }

    // المحامي الموقوف لا يُحجز له موعد — يُردّ بالتحقّق، ولا يُكتب شيء.
    public function test_staff_booking_rejects_a_suspended_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->activeLawyer();
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);

        $consult = $this->paidConsultForTicket($client);
        $this->adminPublishes($consult, ['lawyer_id' => $suspended->id, 'date' => $this->slotDate(), 'time' => '11:00'])
            ->assertJsonValidationErrors('lawyer_id');

        $this->assertSame(0, Appointment::where('user_id', $client->id)->count());
        $this->assertSame('بانتظار تحديد الموعد', $consult->fresh()->status);
    }

    // ── employee.schedule.store (الموظف يقترح — محامٍ نشط فقط) ──

    public function test_employee_schedule_rejects_non_lawyer_as_lawyer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $anotherEmployee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office',
            'date' => $this->slotDate(), 'time' => '13:00',
            'lawyer_id' => $anotherEmployee->id,
        ])->assertSessionHasErrors('lawyer_id');
    }

    public function test_employee_schedule_accepts_active_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->activeLawyer();
        $this->paidConsultForTicket($client);

        // ردّ الرفض تحويلٌ أيضاً — فلا يكفي `assertRedirect` وحده دليلاً على القبول
        $this->actingAs($this->schedulingEmployee())->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office',
            'date' => $this->slotDate(), 'time' => '13:00',
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', ['user_id' => $client->id, 'lawyer_id' => $lawyer->id]);
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
