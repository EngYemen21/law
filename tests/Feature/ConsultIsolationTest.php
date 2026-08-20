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

    public function test_employee_can_access_any_consult(): void
    {
        $consult = $this->consultFor(User::factory()->create(['role' => Role::Lawyer]));

        // مكتب واحد بلا فروع: أيّ موظف يفتح أيّ استشارة (المحامي وحده معزول بالإسناد)
        $this->actingAs(User::factory()->create(['role' => Role::Employee]))
            ->get('/employee/consult?ref='.$consult->ref)->assertOk();
        $this->actingAs(User::factory()->create(['role' => Role::Employee]))
            ->get('/employee/consult?ref='.$consult->ref)->assertOk();
    }

    public function test_admin_sees_any_consult(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultFor($lawyer);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get('/admin/consult?ref='.$consult->ref)->assertOk();
    }

    public function test_pricing_is_admin_only(): void
    {
        // التسعير وشاشة «طلبات الاستشارات» للإدارة العليا وحدها (مطابق التصميم)
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultFor($lawyer, ['status' => 'بانتظار التسعير']);

        // غير الإدارة: حارس الدور يعيد التوجيه (302) بعيدًا عن مسار /admin
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->get('/admin/consult-requests')->assertRedirect();
        $this->actingAs($lawyer)->get('/admin/consult-requests')->assertRedirect();

        // الإدارة ترى الشاشة وتسعّر بنجاح → بانتظار السداد
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->get('/admin/consult-requests')->assertOk();
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 500])->assertRedirect();
        $this->assertSame('بانتظار السداد', $consult->fresh()->status);
    }

    public function test_consult_requests_lists_pre_session_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // طلب قبل الجلسة
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-REQ-1', 'subject' => 'نزاع',
            'channel' => 'هاتفية', 'lawyer' => 'مستشار', 'status' => 'بانتظار التسعير']);
        // استشارة داخل رحلة المعالجة
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-PROC-1', 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'مستشار', 'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة']);

        $admin = User::factory()->create(['role' => Role::Admin]);

        // شاشة الطلبات: طلبات ما قبل الجلسة فقط
        $this->actingAs($admin)->get('/admin/consult-requests')
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/consult-requests')->has('consults', 1));
        // إدارة الاستشارات: تستثني طلبات ما قبل الجلسة
        $this->actingAs($admin)->get('/admin/consults')
            ->assertOk()->assertInertia(fn ($p) => $p->has('consults', 1));
    }

    public function test_only_owner_can_pay_or_schedule(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultFor($lawyer, ['status' => 'بانتظار السداد']);
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)->post(route('consults.pay', $consult))->assertForbidden();
        $this->actingAs($intruder)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => now()->addDay()->toDateString(), 'time' => '10:00',
        ])->assertForbidden();
    }

    public function test_created_tasks_go_to_assigned_lawyer_not_actor(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->consultFor($lawyer, ['session' => 'منتهية', 'status' => 'منتهية']);

        $this->actingAs($employee)->post(route('employee.consults.tasks', $consult))->assertRedirect();
        // المهمة تُسند لمحامي الاستشارة (FK) لا للموظف الفاعل
        $this->assertSame(1, Task::where('assigned_to', $lawyer->id)->count());
        $this->assertSame(0, Task::where('assigned_to', $employee->id)->count());
    }
}
