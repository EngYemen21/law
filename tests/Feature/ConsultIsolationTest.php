<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عزل الاستشارات: المحامي لا يرى/يعدّل إلا استشاراته المسندة (سدّ IDOR)، والموظف محصور بفرعه،
 * والإدارة كاملة. يكمّل ConsultSessionTest (فلترة القوائم) بحماية الوصول المباشر بالإجراءات.
 */
class ConsultIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function consultFor(User $lawyer, array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع',
            'channel' => 'مرئية',
            'lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'branch' => $lawyer->branch,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة',
            'decisions' => ['متابعة'],
        ], $extra));
    }

    public function test_lawyer_cannot_access_unassigned_consult(): void
    {
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultFor($lawyerA);

        // عرض الرحلة عبر ?ref — يُمنع للمحامي غير المسند
        $this->actingAs($lawyerB)->get('/lawyer/consult?ref='.$consult->ref)->assertForbidden();
        // الإجراءات — تُمنع أيضاً
        $this->actingAs($lawyerB)->post(route('lawyer.consults.end', $consult))->assertForbidden();
        $this->actingAs($lawyerB)->post(route('lawyer.consults.tasks', $consult))->assertForbidden();

        // المحامي المسند يتصرّف بنجاح
        $this->actingAs($lawyerA)->post(route('lawyer.consults.end', $consult))->assertRedirect();
    }

    public function test_employee_cannot_access_other_branch_consult(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $consult = $this->consultFor($lawyer); // branch = فرع الرياض

        $riyadh = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $dammam = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الدمام']);

        $this->actingAs($dammam)->get('/employee/consult?ref='.$consult->ref)->assertForbidden();
        $this->actingAs($riyadh)->get('/employee/consult?ref='.$consult->ref)->assertOk();
    }

    public function test_admin_sees_any_consult(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $consult = $this->consultFor($lawyer);
        $admin = User::factory()->create(['role' => Role::Admin, 'branch' => 'فرع الدمام']);

        $this->actingAs($admin)->get('/admin/consult?ref='.$consult->ref)->assertOk();
    }

    public function test_created_tasks_go_to_assigned_lawyer_not_actor(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $consult = $this->consultFor($lawyer, ['session' => 'منتهية', 'status' => 'منتهية']);

        $this->actingAs($employee)->post(route('employee.consults.tasks', $consult))->assertRedirect();
        // المهمة تُسند لمحامي الاستشارة (FK) لا للموظف الفاعل
        $this->assertSame(1, Task::where('assigned_to', $lawyer->id)->count());
        $this->assertSame(0, Task::where('assigned_to', $employee->id)->count());
    }
}
