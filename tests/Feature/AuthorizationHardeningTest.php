<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * تحقّق من التأمين: deny-by-default، throttle الدخول، الإيقاف الفوري، الإمبرسنيشن، كلمات المرور.
 */
class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function restrictedEmployee(array $perms = []): User
    {
        $u = User::factory()->create(['role' => Role::Employee]);
        $u->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $u;
    }

    // ── deny-by-default: مسارات كانت تمرّ سابقاً (fail-open) صارت محميّة ──

    public function test_employee_without_case_permission_blocked_from_case_reply(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'ق-2026-9001', 'type' => 'تجاري', 'status' => 'منظورة',
        ]);
        $employee = $this->restrictedEmployee(['جدولة المواعيد']); // بلا «إدارة القضايا والأتعاب»

        $this->actingAs($employee)->post(route('employee.cases.reply', $case), ['body' => 'x'])
            ->assertRedirect(route('employee.dashboard', absolute: false));
    }

    public function test_employee_with_case_permission_allowed(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'ق-2026-9002', 'type' => 'تجاري', 'status' => 'منظورة',
        ]);
        $employee = $this->restrictedEmployee(['إدارة القضايا والأتعاب']);

        $this->actingAs($employee)->get('/employee/cases')->assertOk();
        $this->actingAs($employee)->post(route('employee.cases.reply', $case), ['body' => 'مرحباً'])->assertNoContent();
    }

    public function test_lawyer_without_meeting_permission_blocked_from_minutes(): void
    {
        $meeting = Meeting::create(['ref' => 'M-9001', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'قادم']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::whereIn('name', ['المساعد القانوني'])->get()); // بلا «إدارة الاجتماعات»

        $this->actingAs($lawyer)->post("/lawyer/meetings/{$meeting->id}/minutes", ['minutes' => 'x'])
            ->assertRedirect(route('lawyer.dashboard', absolute: false));
    }

    // انقلب العقد بقرار 2026-08-28: الإدارة مقصورة على لوحتها — صفحات اللوحات الأخرى محظورة
    // حتى بالرابط المباشر، ويبقى الإشراف الوظيفي (الإجراءات + واجهات GET المحددة) فقط.
    public function test_admin_blocked_from_other_role_pages(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        foreach (['/employee/dashboard', '/lawyer/dashboard', '/dashboard', '/employee/cases', '/lawyer/tickets', '/myconsults'] as $page) {
            $this->actingAs($admin)->get($page)->assertRedirect('/admin/dashboard');
        }
    }

    public function test_admin_oversight_runs_on_admin_routes_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // النظائر الإدارية الجديدة تعمل (قرار 2026-08-28: مسارات خاصة بالأدمن بلا استثناءات)
        $this->actingAs($admin)
            ->getJson('/admin/schedule/slots?lawyer_id='.$lawyer->id.'&date='.now()->addDay()->toDateString())
            ->assertOk();
        $this->actingAs($admin)->post('/admin/tickets/TK-NOPE/convert-consult')->assertNotFound(); // عبَر البوابة وسقط على الربط

        // ومسارات الأدوار الأخرى مقفلة عليه حتى للإجراءات (POST) — مسار بلا مُعامِلات
        // كي تظهر البوابة نفسها (ربط النماذج يسبقها فيحجب المعرّفات الوهمية بـ404)
        $this->actingAs($admin)->post('/employee/schedule')->assertRedirect('/admin/dashboard');
        $this->actingAs($admin)
            ->getJson('/employee/schedule/slots?lawyer_id='.$lawyer->id.'&date='.now()->addDay()->toDateString())
            ->assertForbidden();
    }

    // ── throttle طلب رمز الدخول ──

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['national_id' => '2000000001', 'phone' => '0590000001']);

        // الحدّ 3 طلبات/دقيقة لكلّ هويّة (والجلسة والجوال) — لا عنوان الجهاز
        for ($i = 0; $i < 3; $i++) {
            $this->post('/auth/otp/request', ['national_id' => '2000000001'])->assertStatus(302);
        }
        // الطلب الرابع يُحظر (429)
        $this->post('/auth/otp/request', ['national_id' => '2000000001'])->assertStatus(429);
    }

    /**
     * 🔴 المفتاح كان الهويّة + $request->ip()، و trustProxies(at:'*') يجعل الـIP قيمةً يرسلها
     * الطرف الآخر في X-Forwarded-For — فتدويرها كان يمنح حصّة جديدة ⇒ قصف جوال صاحب الهويّة بلا حدّ.
     */
    public function test_forged_forwarded_for_does_not_reset_the_request_quota(): void
    {
        User::factory()->create(['national_id' => '2000000001', 'phone' => '0590000001']);
        $this->fakeTaqnyatVerify();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/auth/otp/request', ['national_id' => '2000000001'], ['X-Forwarded-For' => '203.0.113.'.$i])
                ->assertStatus(302);
        }

        $blocked = $this->post('/auth/otp/request', ['national_id' => '2000000001'], ['X-Forwarded-For' => '198.51.100.7']);
        $this->assertSame(429, $blocked->getStatusCode(), 'تدوير X-Forwarded-For جدّد حصّة طلب الرمز.');
    }

    /** البدء من جديد (جلسة جديدة كلّ مرّة) لا يفلت من سقف الساعة للهويّة الواحدة. */
    public function test_starting_over_cannot_escape_the_hourly_quota_of_an_identity(): void
    {
        User::factory()->create(['national_id' => '2000000001', 'phone' => '0590000001']);
        $this->fakeTaqnyatVerify();

        // 21 ثانية بين الطلبات: لا يُبلغ حدّ الدقيقة (3) أبداً، فالسقف المقيس هنا سقف الساعة وحده
        for ($i = 0; $i < 10; $i++) {
            $this->flushSession();
            $this->travel(21)->seconds();
            $this->post('/auth/otp/request', ['national_id' => '2000000001'])->assertStatus(302);
        }

        $this->flushSession();
        $this->travel(21)->seconds();
        $this->post('/auth/otp/request', ['national_id' => '2000000001'])->assertStatus(429);
    }

    /** سقف الساعة يحرس الجوال المُرسَل إليه أيضاً — تدوير الهويّة في التسجيل لا يقصف جوالاً واحداً. */
    public function test_rotating_national_ids_cannot_bomb_one_phone_during_registration(): void
    {
        $this->fakeTaqnyatVerify();

        for ($i = 0; $i < 3; $i++) {
            $this->flushSession();
            $this->post('/auth/register', [
                'name' => 'عميل تجريبي', 'national_id' => '108845220'.$i, 'phone' => '0555555545', 'email' => "c{$i}@example.com",
            ])->assertStatus(302);
        }

        $this->flushSession();
        $blocked = $this->post('/auth/register', [
            'name' => 'عميل تجريبي', 'national_id' => '1088452209', 'phone' => '+966555555545', 'email' => 'c9@example.com',
        ]);
        $this->assertSame(429, $blocked->getStatusCode(), 'الجوال نفسه بصيغة دوليّة جدّد الحصّة.');
    }

    // ── الإيقاف الفوري ──

    public function test_suspended_mid_session_is_logged_out_on_next_request(): void
    {
        $employee = $this->restrictedEmployee(['إدارة التذاكر']);
        $this->actingAs($employee)->get('/employee/tickets')->assertOk();

        $employee->update(['status' => 'suspended']);

        $this->actingAs($employee)->get('/employee/tickets')->assertRedirect('/login');
        $this->assertGuest();
    }

    // ── الإمبرسنيشن — أُلغي بقرار 2026-08-28 ──

    public function test_impersonation_routes_are_removed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $staff = $this->restrictedEmployee(['إدارة التذاكر']);

        $this->actingAs($admin)->post("/admin/staff/{$staff->id}/preview")->assertNotFound();
        $this->post('/impersonate/leave')->assertNotFound();
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_stale_impersonator_session_key_grants_nothing(): void
    {
        // مفتاح جلسة قديم من قبل الإلغاء لا يفتح أي باب: لا لافتة تُشارك ولا مسار خروج موجود
        $employee = $this->restrictedEmployee(['إدارة التذاكر']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($employee)
            ->withSession(['impersonator_id' => $admin->id])
            ->post('/impersonate/leave')->assertNotFound();
        $this->assertSame($employee->id, auth()->id());
    }

    // ── toggle ──

    public function test_toggle_forbidden_on_client(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->post(route('admin.staff.toggle', $client))->assertForbidden();
    }

    // ── كلمات المرور ──

    public function test_store_returns_generated_password_once(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'موظف جديد', 'role' => 'employee', 'job_title' => 'محاسب',
            'email' => 'new@salasel.test', 'mobile' => '0590001490', 'nid' => '1090001490',
            'payType' => 'salary', 'salary' => 8000, 'perms' => [],
        ])->assertRedirect()->assertSessionHas('generatedPassword');

        $created = User::where('email', 'new@salasel.test')->firstOrFail();
        // كلمة المرور ليست «password» الثابتة
        $this->assertFalse(Hash::check('password', $created->password));
    }

    public function test_user_can_change_own_password(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'password' => Hash::make('oldpass123')]);

        // كلمة حالية خاطئة → خطأ
        $this->actingAs($user)->post(route('profile.password'), [
            'current_password' => 'wrong', 'password' => 'NewPass!234', 'password_confirmation' => 'NewPass!234',
        ])->assertSessionHasErrors('current_password');

        // كلمة حالية صحيحة → نجاح
        $this->actingAs($user)->post(route('profile.password'), [
            'current_password' => 'oldpass123', 'password' => 'NewPass!234', 'password_confirmation' => 'NewPass!234',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(Hash::check('NewPass!234', $user->fresh()->password));
    }
}
