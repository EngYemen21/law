<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حارس دائم لقرار 2026-08-28: الإدارة العليا مقصورة على لوحتها.
 * يمسح **كل** مسارات GET المحمية ببوابة دور غير الإدارة (client/employee/lawyer)
 * آليًا من جدول المسارات نفسه — فأي مسار جديد يُضاف مستقبلًا يدخل المسح تلقائيًا.
 */
class AdminPanelLockdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_never_renders_any_other_role_page(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $swept = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $roleGate = null;
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'role:') && ! str_contains($mw, 'admin')) {
                    $roleGate = $mw;
                    break;
                }
            }
            if ($roleGate === null) {
                continue;
            }

            // مسار بمُعامِلات: نعوّض قيمة وهمية — النتيجة إما 404 (قبل أي عرض) أو تحويل للوحة الإدارة.
            // كلاهما يعني: الأدمن لا يرى الصفحة أبدًا. الممنوع الوحيد هو 200.
            $uri = '/'.preg_replace('/\{[^}]+\}/', 'SWEEP-NONE', $route->uri());

            $response = $this->actingAs($admin)->get($uri);
            $status = $response->getStatusCode();

            if ($status === 302) {
                $this->assertSame(
                    url('/admin/dashboard'),
                    $response->headers->get('Location'),
                    "المسار [{$uri}] ({$roleGate}) حوّل الأدمن لغير لوحة الإدارة"
                );
            } else {
                $this->assertSame(404, $status, "المسار [{$uri}] ({$roleGate}) ردّ {$status} — يجب ألا يصل الأدمن لأي صفحة من لوحة دور آخر");
            }

            $swept++;
        }

        // شبكة أمان: لو تبخرت البوابات من المسارات لسقط المسح صامتًا
        $this->assertGreaterThan(70, $swept, 'عدد المسارات الممسوحة أقل من المتوقع — تحقق من بوابات role:');
    }

    public function test_admin_blocked_even_with_ajax_and_inertia_requests(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        // نداء XHR يُرفض 403 صراحةً (لا تحويل يقرؤه الفرونت 200)
        $this->actingAs($admin)
            ->get('/employee/dashboard', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertForbidden();

        // الزيارة العادية تُحوَّل للوحة الإدارة ومعها رسالة الخطأ في الجلسة (تظهر توستًا)
        $this->actingAs($admin)
            ->get('/lawyer/dashboard')
            ->assertRedirect('/admin/dashboard')
            ->assertSessionHas('error', 'لوحات الأدوار الأخرى غير متاحة للإدارة العليا.');
    }
}
