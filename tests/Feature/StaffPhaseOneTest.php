<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **تبويب «تسجيل الموظفين» — المرحلة ١: أمانٌ وصحّة** (قرار المالك 2026-09-30).
 *
 * كانت تُعرض «كلمة مرورٍ مؤقّتة» والدخول بالرمز وحده، ولا يُسجَّل في سجلّ التدقيق تسجيلُ موظّفٍ ولا تغيير دوره
 * أو صلاحيّاته أو أجره ولا إيقافه، ويغيّر المدير دوره بنفسه فيفقد لوحته، ويُحوَّل محامٍ بملفّاتٍ مفتوحة إلى
 * دورٍ آخر بلا تحذير، والقسم الإداريّ إلزاميٌّ في الشاشة اختياريٌّ في الخادم.
 */
class StaffPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @param  array<string, mixed>  $over */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'سلمى الغامدي', 'role' => 'employee', 'job_title' => 'موظف خدمة عملاء',
            'email' => 'salma'.uniqid().'@salasel.test', 'mobile' => '055'.random_int(1000000, 9999999), 'nid' => '10'.random_int(10000000, 99999999),
            'dept' => 'خدمة العملاء', 'payType' => 'salary', 'salary' => 8000, 'perms' => ['إدارة التذاكر'],
        ], $over);
    }

    public function test_no_temporary_password_is_shown_login_is_by_otp(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload())
            ->assertRedirect()->assertSessionMissing('generatedPassword');

        $page = (string) file_get_contents(resource_path('js/pages/admin/staff.tsx'));
        $this->assertStringNotContainsString('generatedPassword', $page);
        $this->assertStringNotContainsString('كلمة مرور', $page);
        $this->assertStringNotContainsString('generatedPassword', (string) file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php')));
    }

    public function test_create_update_and_toggle_are_audited_with_before_and_after(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload(['email' => 'audit@salasel.test']))->assertRedirect();
        $user = User::where('email', 'audit@salasel.test')->sole();
        $created = AuditLog::where('action', 'تسجيل موظف')->sole();
        $this->assertSame('موظفون وصلاحيات', $created->category);

        $this->actingAs($admin)->put(route('admin.staff.update', $user), $this->payload([
            'email' => 'audit@salasel.test', 'mobile' => $user->phone, 'nid' => $user->national_id,
            'salary' => 9500, 'perms' => ['إدارة التذاكر', 'الرد على العملاء'],
        ]))->assertRedirect();
        $updated = AuditLog::where('action', 'تعديل بيانات موظف')->sole();
        $this->assertSame(8000, $updated->before_state['الراتب'] ?? null);
        $this->assertSame(9500, $updated->after_state['الراتب'] ?? null);
        $this->assertContains('الرد على العملاء', $updated->after_state['الصلاحيّات'] ?? []);

        $this->actingAs($admin)->post(route('admin.staff.toggle', $user))->assertRedirect();
        $this->assertTrue(AuditLog::where('action', 'إيقاف موظف')->exists());
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'phone' => '0550000001', 'national_id' => '1000000111']);
        $body = $this->payload(['role' => 'employee', 'email' => $admin->email, 'mobile' => $admin->phone, 'nid' => $admin->national_id]);

        $this->actingAs($admin)->put(route('admin.staff.update', $admin), $body)->assertSessionHasErrors('role');
        $this->assertSame(Role::Admin, $admin->fresh()->role);

        // بقيّة بياناته تُعدَّل كما كانت
        $this->actingAs($admin)->put(route('admin.staff.update', $admin), array_merge($body, ['role' => 'admin', 'name' => 'اسمٌ جديد']))->assertSessionHasNoErrors();
        $this->assertSame('اسمٌ جديد', $admin->fresh()->name);
    }

    public function test_a_lawyer_with_open_files_keeps_the_lawyer_role(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'phone' => '0550000002', 'national_id' => '1000000222']);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-P1-1', 'type' => 'نزاع', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id]);
        $body = $this->payload(['role' => 'employee', 'email' => $lawyer->email, 'mobile' => $lawyer->phone, 'nid' => $lawyer->national_id]);

        $this->actingAs($admin)->put(route('admin.staff.update', $lawyer), $body)->assertSessionHasErrors('role');
        $this->assertStringContainsString('تذكرة', (string) session('errors')->first('role'));
        $this->assertSame(Role::Lawyer, $lawyer->fresh()->role);

        Ticket::where('number', 'SB-P1-1')->update(['assigned_lawyer_id' => null]);
        $this->actingAs($admin)->put(route('admin.staff.update', $lawyer), $body)->assertSessionHasNoErrors();
        $this->assertSame(Role::Employee, $lawyer->fresh()->role);
    }

    public function test_the_admin_department_is_required_for_non_lawyers(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload(['dept' => '']))->assertSessionHasErrors('dept');
        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload(['role' => 'lawyer', 'dept' => '', 'job_title' => 'محامٍ']))->assertSessionHasNoErrors();
    }

    /** الحالة والشمول من الخادم (علمان) لا من نصوصٍ عربيّة في الشروط. */
    public function test_the_page_reads_flags_not_arabic_labels(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->get(route('admin.staff'))
            ->assertInertia(fn ($page) => $page->where('staff.0.active', true));

        $page = (string) file_get_contents(resource_path('js/pages/admin/staff.tsx'));
        foreach (["=== 'موقوف'", "|| 'نشط'", "=== 'كل الأقسام'", "=== 'يغطي كل الأقسام'", "role === 'موظف خدمة عملاء'", "role === 'محامٍ'"] as $literal) {
            $this->assertStringNotContainsString($literal, $page, $literal);
        }
    }
}
