<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عزل البيانات بالفرع/المحامي:
 * - الموظف لا يرى إلا سجلات فرعه (قوائم + وصول مباشر 403).
 * - المحامي لا يصل مباشرةً لتذكرة/قضية/تنفيذ محامٍ آخر (403).
 * - فتح التذكرة يختم فرعها آلياً من فرع المحامي المختص (إسناد الذكاء الاصطناعي — حتمي بلا مزوّد).
 * - تسجيل الموظف/المحامي يشترط فرعاً.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const HQ = 'الفرع الرئيسي — جدة';

    private const RIYADH = 'فرع الرياض';

    private function ticketIn(string $branch, string $number): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => $number, 'type' => 'تجاري', 'department' => 'القضايا التجارية',
            'branch' => $branch, 'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
        ]);
    }

    public function test_employee_sees_only_own_branch_tickets(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => self::HQ]);
        $this->ticketIn(self::HQ, 'SB-HQ-1');
        $this->ticketIn(self::RIYADH, 'SB-RY-1');

        $this->actingAs($employee)->get(route('employee.tickets'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('tickets', 1)
            ->where('tickets.0.no', 'SB-HQ-1'));
    }

    public function test_employee_forbidden_on_other_branch_ticket(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => self::HQ]);
        $other = $this->ticketIn(self::RIYADH, 'SB-RY-2');

        $this->actingAs($employee)->get(route('employee.tickets.show', $other))->assertForbidden();
        $this->actingAs($employee)->post(route('employee.tickets.status', $other), ['status' => 'مكتملة', 'tone' => 'b-green'])
            ->assertForbidden();
    }

    public function test_employee_forbidden_on_other_branch_case_and_execution(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => self::HQ]);
        $client = User::factory()->create(['role' => Role::Client]);

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-RY-1', 'type' => 'تجاري',
            'branch' => self::RIYADH, 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-RY-1', 'subject' => 'تنفيذ',
            'branch' => self::RIYADH, 'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
        ]);

        $this->actingAs($employee)->get(route('employee.cases.show', $case))->assertForbidden();
        // التنفيذ الموحّد: الموظف لا يتصرّف على تنفيذ فرع آخر (عزل الفرع)، ولا يظهر في قائمته
        $this->actingAs($employee)->post(route('exec-flow.act', $exec), ['action' => 'refer'])->assertForbidden();
        $this->actingAs($employee)->get(route('employee.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')->has('execs', 0));
    }

    public function test_lawyer_cannot_open_another_lawyers_records(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::HQ]);
        $other = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::HQ]);
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-L-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $other->id, 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-L-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $other->id, 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-L-1', 'subject' => 'تنفيذ',
            'assigned_lawyer_id' => $other->id, 'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
        ]);

        $this->actingAs($mine)->get(route('lawyer.tickets.show', $ticket))->assertForbidden();
        $this->actingAs($mine)->get(route('lawyer.cases.show', $case))->assertForbidden();
        // التنفيذ الموحّد: لا يتصرّف المحامي على ملفّ مسند لزميل آخر
        $this->actingAs($mine)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertForbidden();

        // المحامي المسند نفسه يصل بلا مانع
        $this->actingAs($other)->get(route('lawyer.cases.show', $case))->assertOk();
    }

    public function test_new_ticket_is_stamped_with_assigned_lawyer_branch(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::RIYADH, 'department' => 'القضايا التجارية']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية', 'details' => 'تفاصيل الطلب',
        ])->assertRedirect();

        $ticket = Ticket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame(self::RIYADH, $ticket->branch);
    }

    public function test_assignment_prefers_department_match(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $realEstate = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::HQ, 'department' => 'العقارات']);
        $commercial = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::RIYADH, 'department' => 'القضايا التجارية']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية', 'details' => 'خلاف تجاري',
        ])->assertRedirect();

        $ticket = Ticket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame($commercial->id, $ticket->assigned_lawyer_id);
        $this->assertSame(self::RIYADH, $ticket->branch);
    }

    public function test_staff_registration_requires_branch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'بلا فرع', 'role' => 'employee', 'job_title' => 'موظف',
            'email' => 'nobranch@salasel.test', 'payType' => 'salary', 'salary' => 5000,
        ])->assertSessionHasErrors('branch');
    }
}
