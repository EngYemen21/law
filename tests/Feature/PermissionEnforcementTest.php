<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * تحقّق من إنفاذ الصلاحيات التفصيلية (spatie + EnsurePermission + Gate::before للإدارة).
 */
class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_employee_without_permission_is_blocked(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        // تقييد: يملك فقط «جدولة المواعيد» (لا «إدارة التذاكر»)
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());

        $this->actingAs($employee)->get(route('employee.tickets'))
            ->assertRedirect(route('employee.dashboard', absolute: false));
    }

    public function test_employee_with_permission_passes(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());

        $this->actingAs($employee)->get(route('employee.tickets'))->assertOk();
    }

    public function test_lawyer_permission_gate_on_assistant(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // بلا صلاحية المساعد القانوني → ممنوع
        $lawyer->syncPermissions(Permission::whereIn('name', ['اعتماد الملخصات'])->get());
        $this->actingAs($lawyer)->get(route('lawyer.assistant'))
            ->assertRedirect(route('lawyer.dashboard', absolute: false));

        // مع الصلاحية → مسموح
        $lawyer->syncPermissions(Permission::whereIn('name', ['المساعد القانوني'])->get());
        $this->actingAs($lawyer)->get(route('lawyer.assistant'))->assertOk();
    }

    public function test_admin_bypasses_all_permissions(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        // بلا أي صلاحية مسندة، ومع ذلك يمرّ (Gate::before) عبر لوحات الموظف/المحامي
        $this->actingAs($admin)->get(route('employee.tickets'))->assertOk();
        $this->actingAs($admin)->get(route('lawyer.assistant'))->assertOk();
        $this->assertTrue($admin->can('إدارة الموظفين'));
    }

    public function test_unmapped_route_is_always_allowed(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]); // بلا صلاحيات

        // لوحة الموظف الرئيسية غير مربوطة بصلاحية → متاحة
        $this->actingAs($employee)->get(route('employee.dashboard'))->assertOk();
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        User::factory()->create([
            'role' => Role::Employee, 'national_id' => '2000000002', 'phone' => '0590000002', 'status' => 'suspended',
        ]);

        // الموقوف يُرفض عند طلب رمز الدخول (مع تهيئة تقنيات كي نصل لفحص الإيقاف)
        $this->fakeTaqnyatVerify();
        $this->post('/auth/otp/request', ['national_id' => '2000000002'])
            ->assertSessionHasErrors('national_id');
        $this->assertGuest();
    }

    public function test_admin_previews_staff_dashboard_and_returns(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $staff = User::factory()->create(['role' => Role::Employee]);

        // بدء المعاينة → يصبح المستخدم الحالي هو الموظف
        $this->actingAs($admin)->post(route('admin.staff.preview', $staff))
            ->assertRedirect(route('employee.dashboard', absolute: false));
        $this->assertSame($staff->id, auth()->id());

        // إنهاء المعاينة → العودة للمدير
        $this->post(route('impersonate.leave'))->assertRedirect('/admin/staff');
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_shared_permissions_exposed_to_frontend(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());

        $this->actingAs($employee)->get(route('employee.dashboard'))
            ->assertInertia(fn ($p) => $p
                ->where('auth.user.isSuper', false)
                ->where('auth.user.permissions', ['إدارة التذاكر']));
    }
}
