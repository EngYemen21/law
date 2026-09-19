<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        // Gate::before ما زال يعفي الإدارة من صلاحيات spatie — على مسارات لوحتها هي
        // (صفحات لوحات الأدوار الأخرى صارت محظورة عليها بقرار 2026-08-28 — انظر AuthorizationHardeningTest)
        $this->actingAs($admin)->get(route('admin.staff'))->assertOk();      // permission:إدارة الموظفين
        $this->actingAs($admin)->get(route('admin.audit-logs'))->assertOk(); // permission:سجل التدقيق الأمني
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

        // الموقوف لا يصله رمز ولا يقبل منه رمز (مع تهيئة تقنيات كي نصل لفحص الإيقاف) —
        // والردّ نفسه ردّ أيّ هويّة كي لا يكشف طلب الرمز أنّ الحساب موجود وموقوف
        $this->fakeTaqnyatVerify('1234');
        $this->post('/auth/otp/request', ['national_id' => '2000000002'])
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /** أُلغيت «معاينة اللوحة» (الإمبرسنيشن) بقرار 2026-08-28 — الاختبار يوثّق زوال المسارين. */
    public function test_staff_preview_impersonation_is_removed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $staff = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($admin)->post("/admin/staff/{$staff->id}/preview")->assertNotFound();
        $this->assertSame($admin->id, auth()->id());

        $this->post('/impersonate/leave')->assertNotFound();
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
