<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeCaseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_employee_can_view_rich_cases_hub(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'ناصر العتيبي', 'phone' => '0551122334']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار القانوني أحمد']);

        $case1 = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-5001',
            'type' => 'تجاري',
            'department' => 'القضايا التجارية',
            'court' => 'المحكمة التجارية بالرياض',
            'status' => 'منظورة',
            'tone' => 'b-cyan',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        CaseHearing::create([
            'case_id' => $case1->id,
            'title' => 'جلسة المرافعة الختامية',
            'day' => 'الأربعاء 15 شوال',
            'time' => '10:00 ص',
            'court' => 'الدائرة التجارية الرابعة',
            'status' => 'مجدولة',
            'starts_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($employee)->get('/employee/cases');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/cases')
            ->has('cases', 1)
            ->has('counts')
            ->where('counts.active', 1)
            ->where('counts.withHearings', 1)
            ->where('counts.inCourt', 1)
            ->has('departments')
            ->has('types')
            ->has('lawyers')
        );
    }

    public function test_employee_can_view_case_details_with_client_context(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سلطان القحطاني', 'phone' => '0567788990']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار فيصل']);

        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9001',
            'type' => 'استشارة تجارية',
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-6001',
            'type' => 'عمالي',
            'department' => 'القضايا العمالية',
            'court' => 'المحكمة العمالية',
            'status' => 'قيد التحضير',
            'tone' => 'b-amber',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $response = $this->actingAs($employee)->get("/employee/cases/{$case->number}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/case')
            ->has('case')
            ->where('case.no', $case->number)
            ->has('clientStats')
            ->where('clientStats.totalTickets', 1)
            ->where('clientStats.totalCases', 1)
            ->has('messages')
            ->has('hearings')
            ->has('documents')
        );
    }

    /**
     * الخطّة ب: القضيّة المرفوعة في ناجز «بانتظار القيد» لها عدّادها عند الموظّف، وبيانات القيد
     * (الرقم والمحكمة والدائرة) تظهر للموظّف والإدارة للاطّلاع — من العمود لا من الجلسة.
     */
    public function test_najiz_filing_is_visible_to_the_employee_and_the_admin(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7001', 'type' => 'تجاري',
            'status' => 'بانتظار القيد', 'tone' => 'b-amber',
            'najiz_request_no' => 'NJ-5501', 'filed_at' => now()->toDateString(),
        ]);
        $registered = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7002', 'type' => 'تجاري',
            'status' => 'منظورة', 'tone' => 'b-cyan',
            'najiz_request_no' => 'NJ-5502', 'najiz_case_no' => '4700999888',
            'court' => 'المحكمة التجارية بجدة', 'circuit' => 'الدائرة الثانية', 'registered_at' => now()->toDateString(),
        ]);

        $this->actingAs($employee)->get('/employee/cases')->assertInertia(fn (Assert $page) => $page
            ->where('counts.awaiting', 1)
            ->where('counts.inCourt', 1)
            ->where('counts.active', 2)
            ->where('cases.0.court', 'المحكمة التجارية بجدة')
            ->where('cases.0.najizCaseNo', '4700999888')
        );

        $this->actingAs($employee)->get("/employee/cases/{$registered->number}")->assertInertia(fn (Assert $page) => $page
            ->where('case.court', 'المحكمة التجارية بجدة')
            ->where('case.najiz.caseNo', '4700999888')
            ->where('case.najiz.circuit', 'الدائرة الثانية')
            ->where('case.najiz.requestNo', 'NJ-5502')
        );

        $this->actingAs($admin)->get(route('admin.cases.show', $registered))->assertInertia(fn (Assert $page) => $page
            ->where('case.najiz.caseNo', '4700999888')
        );
    }
}
