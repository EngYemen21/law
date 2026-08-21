<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * تحقّق من تسجيل الموظفين وإدارتهم (spatie + حسابات دخول حقيقية).
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_admin_registers_a_real_employee_with_permissions(): void
    {
        $this->actingAs($this->admin())->post(route('admin.staff.store'), [
            'name' => 'سلمى الغامدي',
            'role' => 'employee',
            'job_title' => 'موظف خدمة عملاء',
            'email' => 'salma@salasel.test',
            'mobile' => '0551110000',
            'nid' => '1088776655',
            'dept' => 'خدمة العملاء',
            'join' => '2026-07-01',
            'start' => '08:00',
            'end' => '16:00',
            'payType' => 'salary',
            'salary' => 8000,
            'perms' => ['إدارة التذاكر', 'الرد على العملاء'],
        ])->assertRedirect()->assertSessionHas('generatedPassword');

        $user = User::where('email', 'salma@salasel.test')->firstOrFail();
        $this->assertSame(Role::Employee, $user->role);
        $this->assertSame('active', $user->status);
        $this->assertSame(8000, $user->salary);
        $this->assertEqualsCanonicalizing(['إدارة التذاكر', 'الرد على العملاء'], $user->getPermissionNames()->all());
        $this->assertTrue($user->can('إدارة التذاكر'));
        $this->assertFalse($user->can('إدارة الموظفين'));

        // الحساب الجديد يدخل عبر رقم الهوية + رمز SMS (لا كلمة مرور)
        $user->update(['national_id' => '2000000003', 'phone' => '0590000003']);
        $this->post('/logout');
        $this->loginViaOtp($user->fresh())
            ->assertRedirect(route('employee.dashboard', absolute: false));
    }

    public function test_explicit_role_field_sets_dashboard(): void
    {
        $admin = $this->admin();

        // الدور «محامٍ» صراحةً → لوحة المحامي
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'أ. ليلى', 'role' => 'lawyer', 'job_title' => 'محامٍ', 'email' => 'laila@salasel.test',
            'mobile' => '0590000072', 'nid' => '1090000072',
            'payType' => 'session', 'session' => 700, 'perms' => ['المساعد القانوني'],
        ])->assertRedirect();
        $this->assertSame(Role::Lawyer, User::where('email', 'laila@salasel.test')->firstOrFail()->role);

        // الدور «الإدارة العليا» صراحةً → Admin
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'مدير النظام', 'role' => 'admin', 'job_title' => 'مدير', 'email' => 'mgr@salasel.test',
            'mobile' => '0590000080', 'nid' => '1090000080',
            'payType' => 'salary', 'salary' => 20000, 'perms' => [],
        ])->assertRedirect();
        $this->assertSame(Role::Admin, User::where('email', 'mgr@salasel.test')->firstOrFail()->role);

        // صفة «إداري» مع دور «موظف» تبقى Employee (لا تتحوّل لمشرف — يمنع تكرار الثغرة)
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'أحمد الإداري', 'role' => 'employee', 'job_title' => 'إداري', 'email' => 'idari@salasel.test',
            'mobile' => '0590000087', 'nid' => '1090000087',
            'payType' => 'salary', 'salary' => 9000, 'perms' => ['توزيع التذاكر'],
        ])->assertRedirect();
        $idari = User::where('email', 'idari@salasel.test')->firstOrFail();
        $this->assertSame(Role::Employee, $idari->role);
        $this->assertFalse($idari->can('إدارة الموظفين')); // لا يتجاوز صلاحياته
    }

    public function test_role_field_is_required_and_validated(): void
    {
        $this->actingAs($this->admin())->post(route('admin.staff.store'), [
            'name' => 'x', 'role' => 'superuser', 'job_title' => 'محاسب',
            'email' => 'x@salasel.test', 'payType' => 'salary',
        ])->assertSessionHasErrors('role');
    }

    public function test_admin_updates_staff_role_permissions_and_fields(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create([
            'role' => Role::Employee, 'email' => 'edit@salasel.test', ]);
        $staff->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());

        $this->actingAs($admin)->put(route('admin.staff.update', $staff), [
            'name' => 'اسم محدّث',
            'role' => 'lawyer', // ترقية إلى محامٍ
            'job_title' => 'محامٍ',
            'email' => 'edit@salasel.test', // نفس البريد (يُتجاهل في الفريد)
            'mobile' => '0590000113', 'nid' => '1090000113',
            'dept' => 'القضايا التجارية',
            'payType' => 'both', 'salary' => 15000, 'pct' => 12,
            'perms' => ['المساعد القانوني', 'إدارة القضايا والأتعاب'],
        ])->assertRedirect()->assertSessionHas('success');

        $staff->refresh();
        $this->assertSame(Role::Lawyer, $staff->role);
        $this->assertSame('اسم محدّث', $staff->name);
        $this->assertSame('both', $staff->pay_type);
        $this->assertSame(15000, $staff->salary);
        $this->assertEqualsCanonicalizing(['المساعد القانوني', 'إدارة القضايا والأتعاب'], $staff->getPermissionNames()->all());
        // الصلاحية القديمة أُزيلت (sync)
        $this->assertFalse($staff->fresh()->can('إدارة التذاكر'));
    }

    public function test_update_rejects_email_taken_by_another_user(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@salasel.test']);
        $staff = User::factory()->create(['role' => Role::Employee, 'email' => 'me@salasel.test']);

        $this->actingAs($admin)->put(route('admin.staff.update', $staff), [
            'name' => 'x', 'role' => 'employee', 'job_title' => 'محاسب',
            'email' => 'taken@salasel.test', 'payType' => 'salary',
        ])->assertSessionHasErrors('email');
    }

    public function test_update_forbidden_on_client(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->put(route('admin.staff.update', $client), [
            'name' => 'x', 'role' => 'employee', 'job_title' => 'محاسب',
            'email' => 'c@salasel.test', 'payType' => 'salary',
        ])->assertForbidden();
    }

    public function test_update_does_not_change_password(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => Role::Employee, 'email' => 'pw@salasel.test', 'password' => Hash::make('keepme123')]);

        $this->actingAs($admin)->put(route('admin.staff.update', $staff), [
            'name' => 'ثابت', 'role' => 'employee', 'job_title' => 'محاسب',
            'email' => 'pw@salasel.test', 'mobile' => '0590000163', 'nid' => '1090000163',
            'payType' => 'salary', 'salary' => 5000, 'perms' => [],
        ])->assertRedirect();

        $this->assertTrue(Hash::check('keepme123', $staff->fresh()->password));
    }

    public function test_staff_card_exposes_raw_fields_for_editing(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'role' => Role::Lawyer, 'pay_type' => 'session', 'session_fee' => 800,
        ]);

        $this->actingAs($admin)->get(route('admin.staff'))
            ->assertInertia(fn ($p) => $p->component('admin/staff')
                ->where('staff.0.roleKey', 'lawyer')
                ->where('staff.0.payType', 'session')
                ->where('staff.0.sessionFee', 800));
    }

    public function test_permissions_are_scoped_to_selected_role(): void
    {
        $admin = $this->admin();

        // موظف بدور employee: صلاحية محامٍ خالصة («المساعد القانوني») تُرفَض/تُقصّ
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'موظف', 'role' => 'employee', 'job_title' => 'موظف خدمة عملاء',
            'email' => 'scoped@salasel.test', 'mobile' => '0590000190', 'nid' => '1090000190',
            'payType' => 'salary', 'salary' => 6000,
            'perms' => ['إدارة التذاكر', 'المساعد القانوني'], // الثانية خارج صلاحيات الموظف
        ])->assertRedirect();

        $u = User::where('email', 'scoped@salasel.test')->firstOrFail();
        $this->assertContains('إدارة التذاكر', $u->getPermissionNames()->all());
        $this->assertNotContains('المساعد القانوني', $u->getPermissionNames()->all()); // قُصّت
    }

    public function test_catalog_exposes_role_permissions(): void
    {
        $this->actingAs($this->admin())->get(route('admin.staff'))
            ->assertInertia(fn ($p) => $p->component('admin/staff')
                ->has('permCatalog.rolePermissions.employee')
                ->has('permCatalog.rolePermissions.lawyer')
                // الإدارة العليا = كل الصلاحيات (23 بعد إسقاط «إدارة الفروع»)
                ->has('permCatalog.rolePermissions.admin', 23));
    }

    public function test_toggle_suspends_and_blocks_login(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => Role::Employee, 'email' => 'e@salasel.test', 'national_id' => '2000000004', 'phone' => '0590000004']);

        $this->actingAs($admin)->post(route('admin.staff.toggle', $staff))->assertRedirect();
        $this->assertSame('suspended', $staff->fresh()->status);

        // الموقوف يُرفض في الدخول (بعد إنهاء جلسة الإدارة)
        $this->post('/logout');
        $this->fakeTaqnyatVerify();
        $this->post('/auth/otp/request', ['national_id' => '2000000004'])
            ->assertSessionHasErrors('national_id');
        $this->assertGuest();

        // إعادة التفعيل تسمح بالدخول
        $this->actingAs($admin)->post(route('admin.staff.toggle', $staff))->assertRedirect();
        $this->assertSame('active', $staff->fresh()->status);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'dup@salasel.test']);

        $this->actingAs($this->admin())->post(route('admin.staff.store'), [
            'name' => 'x', 'job_title' => 'محاسب', 'email' => 'dup@salasel.test', 'payType' => 'salary',
        ])->assertSessionHasErrors('email');
    }

    // إضافة دور آخر لنفس الشخص (نفس الهُويّة/الجوال، بريد مختلف) — يُسمح
    public function test_admin_can_add_another_role_for_same_person(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'role' => Role::Lawyer, 'email' => 'p.lawyer@salasel.test',
            'national_id' => '1077001100', 'phone' => '0577001100',
        ]);

        // نفس الهُويّة/الجوال بدور موظف (بريد مختلف) → ينجح
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'شخص بدورين', 'role' => 'employee', 'job_title' => 'موظف',
            'email' => 'p.employee@salasel.test', 'mobile' => '0577001100', 'nid' => '1077001100',
            'payType' => 'salary', 'salary' => 7000, 'perms' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, User::where('national_id', '1077001100')->count());
    }

    // منع تكرار نفس الدور لنفس الهُويّة
    public function test_admin_cannot_duplicate_same_role_for_same_person(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'role' => Role::Lawyer, 'email' => 'duprole@salasel.test',
            'national_id' => '1077002200', 'phone' => '0577002200',
        ]);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'تكرار', 'role' => 'lawyer', 'job_title' => 'محامٍ',
            'email' => 'duprole2@salasel.test', 'mobile' => '0577002200', 'nid' => '1077002200',
            'payType' => 'salary', 'salary' => 9000, 'perms' => [],
        ])->assertSessionHasErrors(['nid', 'mobile']);
    }

    public function test_nid_and_mobile_are_required_for_staff(): void
    {
        $this->actingAs($this->admin())->post(route('admin.staff.store'), [
            'name' => 'بلا هوية', 'role' => 'employee', 'job_title' => 'موظف',
            'email' => 'noid@salasel.test', 'payType' => 'salary',
        ])->assertSessionHasErrors(['nid', 'mobile']);
    }

    public function test_staff_lookup_returns_existing_person_roles(): void
    {
        User::factory()->create([
            'role' => Role::Lawyer, 'name' => 'محامٍ موجود',
            'national_id' => '1077003300', 'phone' => '0577003300',
        ]);

        $this->actingAs($this->admin())->getJson(route('admin.staff.lookup', ['nid' => '1077003300']))
            ->assertOk()
            ->assertJson(['exists' => true, 'name' => 'محامٍ موجود', 'roles' => ['المحامي']]);

        $this->actingAs($this->admin())->getJson(route('admin.staff.lookup', ['nid' => '9999999999']))
            ->assertOk()->assertJson(['exists' => false]);
    }

    public function test_non_admin_cannot_reach_staff_page(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        // الموظف يملك كل الصلاحيات (عبر المصنع) لكن ليس دور admin → يُعاد توجيهه
        $this->actingAs($employee)->get(route('admin.staff'))
            ->assertRedirect(route('employee.dashboard', absolute: false));
    }

    public function test_staff_index_lists_office_accounts_without_clients(): void
    {
        User::factory()->create(['role' => Role::Employee]);
        User::factory()->create(['role' => Role::Lawyer]);
        User::factory()->create(['role' => Role::Client]); // لا يظهر

        // الإدارة تظهر أيضاً: حساب admin يُنشأ من هذه الشاشة نفسها وكان يختفي بعدها
        // فيصير طريقاً مسدوداً (لا تعديل ولا متابعة). العميل وحده مستثنى.
        $this->actingAs($this->admin())->get(route('admin.staff'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/staff')
                ->has('staff', 3)
                // الكتالوج مصدره الخادم عبر prop مشترك
                ->has('permCatalog.groups', 5)
                ->has('permCatalog.presets', 5)
                ->has('permCatalog.viewMap'));
    }
}
