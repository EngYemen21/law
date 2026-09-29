<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_audit_logs_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@test.com']);

        AuditLog::record(
            action: 'تعديل سعر استشارة',
            description: 'تم تعديل السعر من 500 إلى 600',
            category: 'استشارات',
            severity: 'warning',
            auditableRef: 'CN-2026-1001',
            beforeState: ['price' => 500],
            afterState: ['price' => 600],
            user: $admin
        );

        $response = $this->actingAs($admin)->get(route('admin.audit-logs'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/audit-logs')
            ->has('logs')
            ->has('stats')
            ->has('categories')
            ->has('actors')
            ->where('stats.total', 1)
        );
    }

    public function test_admin_can_export_audit_logs_csv(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@test.com']);

        AuditLog::record(
            action: 'تسجيل دخول للنظام',
            description: 'دخول ناجح للمنصة',
            category: 'أمن وحماية',
            severity: 'info',
            user: $admin
        );

        $response = $this->actingAs($admin)->get(route('admin.audit-logs.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    /**
     * أول زيارة بجدول فارغ تُشغّل التعبئة الأولى (الاستيراد التاريخي) — كانت تنفجر 500
     * باستيفاء دور Enum نصاً، وكانت تختلق «تسجيل دخول» لكل مستخدم ببيانات عشوائية.
     */
    public function test_first_visit_with_empty_table_backfills_without_fabrication(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@test.com']);
        User::factory()->create(['role' => 'client', 'email' => 'client@test.com']);

        $this->assertSame(0, AuditLog::count());

        $this->actingAs($admin)->get(route('admin.audit-logs'))->assertOk();

        // لا قيود «تسجيل دخول» مختلقة للمستخدمين، ولا IP/متصفح مزيفين في أي قيد
        $this->assertSame(0, AuditLog::where('action', 'تسجيل دخول للنظام')->count());
        $this->assertSame(0, AuditLog::where('ip_address', 'like', '192.168.1.%')->count());
        $this->assertSame(0, AuditLog::where('ip_address', '127.0.0.1')->count());
    }

    /**
     * الكتابة الحية تمرّ عبر الطابور (Audit::log → RecordAuditLogJob) مع التقاط
     * سياق الطلب لحظة الحدث — تعديل نسبة الضريبة من «الإعدادات» مثال يغطي المسار كاملاً.
     */
    public function test_live_actions_write_queued_audit_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@test.com']);
        // منح صلاحية المسار — الإدارة تتجاوز عبر Gate::before لكن السطر يوثّق الاعتماد
        $this->seed(PermissionSeeder::class);

        $this->actingAs($admin)->post(route('admin.settings.update'), ['vat_rate' => 5])->assertRedirect();

        $entry = AuditLog::where('action', 'تعديل إعدادات النظام')->first();
        $this->assertNotNull($entry);
        $this->assertSame($admin->name, $entry->user_name);
        $this->assertSame('admin', $entry->user_role);
        $this->assertSame('warning', $entry->severity);
        $this->assertNotNull($entry->ip_address);       // من الطلب الحقيقي — لا اختلاق
        $this->assertSame(5, $entry->after_state['vat_rate'] ?? null);
    }

    public function test_audit_log_route_requires_its_permission_for_non_admin(): void
    {
        // موظف بلا صلاحية «سجل التدقيق الأمني» لا يصل (الإدارة تتجاوز عبر Gate::before)
        $employee = User::factory()->create(['role' => 'employee', 'email' => 'emp@test.com']);

        $this->actingAs($employee)->get('/admin/audit-logs')->assertRedirect();
    }
}
