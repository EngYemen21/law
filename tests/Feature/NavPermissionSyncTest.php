<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * يضمن تطابق تصفية القائمة الجانبية (viewMap) مع إنفاذ الصلاحيات على المسارات،
 * فلا يظهر تبويب لا يعمل عند النقر. viewMap مشتقّ من المسارات نفسها.
 */
class NavPermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_view_map_includes_previously_missing_routes(): void
    {
        $map = Permissions::viewMap();

        $this->assertSame('إدارة القضايا والأتعاب', $map['/employee/cases'] ?? null);
        $this->assertSame('إدارة القضايا والأتعاب', $map['/employee/execs'] ?? null);
        $this->assertSame('إدارة القضايا والأتعاب', $map['/lawyer/execs'] ?? null);
        // ومسارات كانت مغطّاة تبقى صحيحة
        $this->assertSame('إدارة التذاكر', $map['/employee/tickets'] ?? null);
        $this->assertSame('اعتماد الملخصات', $map['/lawyer/summaries'] ?? null);
    }

    public function test_catalog_shares_derived_view_map(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->get(route('admin.staff'))
            ->assertInertia(fn ($p) => $p
                ->where('permCatalog.viewMap./employee/cases', 'إدارة القضايا والأتعاب'));
    }

    /**
     * حارس التزامن: كل مسار صفحة (GET بلا مُعامِل) لدى الموظف/المحامي يحمل middleware
     * «permission:X» يجب أن يكون مفتاحه في viewMap بنفس الصلاحية — يمنع الانحراف مستقبلاً.
     */
    public function test_every_permission_gated_page_route_is_in_view_map(): void
    {
        $map = Permissions::viewMap();
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_contains($uri, '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (! (str_starts_with($uri, 'employee/') || str_starts_with($uri, 'lawyer/'))) {
                continue;
            }
            $perm = null;
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                    // **القائمة كاملةً لا أوّلها.** الوسيط `permission:أ,ب` يعني «أيّهما يكفي»،
                    // و`Permissions::viewMap()` يخزّنها كما هي، و`canViewRoute` في الواجهة
                    // يقسّمها على الفاصلة ويقبل أيّها. فاقتطاعُ الأوّل هنا كان يخالف الثلاثة،
                    // ولم يظهر إلّا حين صار لمسارٍ صلاحيّتان (2026-09-24).
                    $perm = substr($mw, 11);
                    break;
                }
            }
            if ($perm !== null) {
                $this->assertSame($perm, $map['/'.$uri] ?? null, "المسار /{$uri} غير متزامن في viewMap");
                $checked++;
            }
        }

        $this->assertGreaterThan(5, $checked); // تأكيد أنّ الفحص فعّال
    }

    public function test_employee_without_case_permission_is_blocked_from_cases_tab(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());

        // الوصول المباشر ممنوع (والقائمة تُخفيه لأن viewMap يغطّيه الآن)
        $this->actingAs($employee)->get(route('employee.cases'))
            ->assertRedirect(route('employee.dashboard', absolute: false));
    }
}
