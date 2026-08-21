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
 * حدود الرؤية بعد إزالة كيان «الفرع» (مكتب واحد):
 * - الموظف يرى كل سجلات المكتب ويتصرّف عليها (لا محور عزل له — قرار صاحب المنتج).
 * - المحامي يبقى معزولاً بالإسناد: لا يصل تذكرة/قضية/تنفيذ زميلٍ آخر (403).
 * - فتح التذكرة يُسند المحامي المختصّ تخصّصاً (إسناد حتمي بلا مزوّد ذكاء اصطناعي).
 *
 * كان هذا الملف BranchIsolationTest؛ قُلبت توكيداته صراحةً لتثبيت السلوك الجديد
 * فلا يعود العزل بالفرع مصادفةً دون أن يُمسك.
 */
class StaffVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $number): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => $number, 'type' => 'تجاري', 'department' => 'القضايا التجارية',
            'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
        ]);
    }

    public function test_employee_sees_all_tickets(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->ticket('SB-1');
        $this->ticket('SB-2');

        $this->actingAs($employee)->get(route('employee.tickets'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('tickets', 2));
    }

    public function test_employee_can_open_and_act_on_any_ticket(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->ticket('SB-3');

        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))->assertOk();
        $this->actingAs($employee)->post(route('employee.tickets.status', $ticket), ['status' => 'مكتملة', 'tone' => 'b-green'])
            ->assertRedirect();
    }

    public function test_employee_can_open_any_case_and_execution(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-1', 'type' => 'تجاري',
            'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
        Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-1', 'subject' => 'تنفيذ',
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
        ]);

        $this->actingAs($employee)->get(route('employee.cases.show', $case))->assertOk();
        $this->actingAs($employee)->get(route('employee.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')->has('execs', 1));
    }

    public function test_lawyer_cannot_open_another_lawyers_records(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
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

    public function test_new_ticket_is_assigned_to_matching_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القضايا التجارية']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية', 'details' => 'تفاصيل الطلب',
        ])->assertRedirect();

        $this->assertSame($lawyer->id, Ticket::where('user_id', $client->id)->firstOrFail()->assigned_lawyer_id);
    }

    public function test_assignment_prefers_department_match(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'العقارات']);
        $commercial = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القضايا التجارية']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية', 'details' => 'خلاف تجاري',
        ])->assertRedirect();

        $this->assertSame($commercial->id, Ticket::where('user_id', $client->id)->firstOrFail()->assigned_lawyer_id);
    }
}
