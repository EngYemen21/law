<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تثبيت قرار صاحب المنتج بعد إزالة كيان «الفرع»: المكتب واحد، فالموظف يرى كل شيء
 * ويتصرّف عليه بلا محور عزل. نقطة فشل واحدة صاخبة لو أُعيد أيّ عزل رؤية للموظف
 * (تصفية قائمة أو حارس 403 أو شرط بثّ) دون قرار صريح.
 *
 * ما يبقى محروساً — وتُثبته هذه الاختبارات أيضاً:
 * - المحامي معزول بالإسناد (assigned_lawyer_id) في السجلات والقنوات.
 * - العميل لا يدخل القنوات الداخلية للمكتب ولو كان مالك السجل.
 */
class EmployeeSeesEverythingTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = User::factory()->create(['role' => Role::Employee]);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    public function test_employee_ticket_list_is_not_scoped(): void
    {
        foreach (['SB-A', 'SB-B', 'SB-C'] as $no) {
            Ticket::create([
                'user_id' => User::factory()->create(['role' => Role::Client])->id,
                'number' => $no, 'type' => 'تجاري', 'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
            ]);
        }

        $this->actingAs($this->employee)->get(route('employee.tickets'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('tickets', 3));
    }

    public function test_employee_case_and_execution_lists_are_not_scoped(): void
    {
        LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-A', 'type' => 'تجاري',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
        Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-A', 'subject' => 'تنفيذ',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
        ]);

        $this->actingAs($this->employee)->get(route('employee.cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('cases', 1));
        $this->actingAs($this->employee)->get(route('employee.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('execs', 1));
    }

    public function test_employee_direct_access_to_any_record_is_allowed(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-D', 'type' => 'تجاري',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
        ]);
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-D', 'type' => 'تجاري',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);

        $this->actingAs($this->employee)->get(route('employee.tickets.show', $ticket))->assertOk();
        $this->actingAs($this->employee)->get(route('employee.cases.show', $case))->assertOk();
    }

    public function test_employee_meeting_and_consult_access_is_not_scoped(): void
    {
        Meeting::create([
            'ref' => 'M-9100', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'قادم',
            'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $consult = Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-9100', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
            'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة',
        ]);

        $this->actingAs($this->employee)->get('/employee/meeting?id=M-9100')->assertOk();
        $this->actingAs($this->employee)->get('/employee/consult?ref='.$consult->ref)->assertOk();
    }

    public function test_broadcast_channels_admit_any_employee(): void
    {
        $rec = (object) ['user_id' => $this->client->id, 'assigned_lawyer_id' => $this->lawyer->id];

        $this->assertTrue(ChannelAccess::staffCanSee($this->employee, $rec));
        $this->assertTrue(ChannelAccess::ownerOrStaff($this->employee, $rec));
        $this->assertNotNull(ChannelAccess::presenceMember($this->employee, $rec));
    }

    public function test_lawyer_isolation_by_assignment_is_preserved(): void
    {
        $stranger = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-E', 'type' => 'تجاري',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);

        $this->assertPageRefused($this->actingAs($stranger)->get(route('lawyer.tickets.show', $ticket)));
        $this->assertFalse(ChannelAccess::staffCanSee($stranger, $ticket));
    }

    public function test_client_is_never_admitted_to_staff_channels(): void
    {
        $rec = (object) ['user_id' => $this->client->id, 'assigned_lawyer_id' => $this->lawyer->id];

        $this->assertFalse(ChannelAccess::staffCanSee($this->client, $rec));
        $this->assertNull(ChannelAccess::presenceMember($this->client, $rec));
        // المالك يبقى على قناته العامة (الحالة/الرسائل) — العزل على الداخلية فقط
        $this->assertTrue(ChannelAccess::ownerOrStaff($this->client, $rec));
    }
}
