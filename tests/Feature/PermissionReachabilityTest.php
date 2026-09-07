<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **صلاحيّةٌ ممنوحةٌ لدورٍ تفتح له باباً — وإلّا فهي كذبةٌ في شاشة الصلاحيّات.**
 *
 * كانت **«أرشيف الاستشارات»** ممنوحةً لدور المحامي في `ROLE_PERMISSIONS` وفي قالبه
 * الجاهز، ومساراتها الأربعة كلُّها داخل مجموعة `role:admin` — و`EnsureRole` يحجب
 * غيرَ الإدارة **بلا أيّ استثناء** (بقرارٍ موثَّق في الوسيط نفسه).
 *
 * فالمدير يفتح شاشة الصلاحيّات، يرى المربّع مؤشَّراً أمام دور المحامي، ويطمئنّ إلى
 * أنّ محاميه يبلغ الأرشيف. ولا يبلغه أبداً. وهو عطلٌ لا يُكتشف بالاستعمال: لا رسالةَ
 * خطأ ولا شكوى — المحامي لا يعرف أصلاً أنّ هناك ما يُفترض أن يراه.
 *
 * والحارس **عامّ**: يمسح جدول المسارات كلَّه فيُسقط أيّ منحةٍ لا يبلغها صاحبها، فلا
 * يقتصر على هذه الواحدة.
 */
class PermissionReachabilityTest extends TestCase
{
    /**
     * الأدوارُ التي يسمح بها مسارٌ ما — أو `null` إن لم يقيّده `role:`.
     *
     * @return array<int,string>|null
     */
    private function rolesAllowedBy(\Illuminate\Routing\Route $route): ?array
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'role:')) {
                return explode(',', substr($m, 5));
            }
        }

        return null;
    }

    /** الصلاحيّاتُ التي يشترطها مسارٌ ما. @return array<int,string> */
    private function permissionsRequiredBy(\Illuminate\Routing\Route $route): array
    {
        $out = [];
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                $out[] = substr($m, 11);
            }
        }

        return $out;
    }

    /**
     * **منحٌ ميّتةٌ قائمةٌ تنتظر قرار المالك** — موثَّقةٌ لا مسكوتٌ عنها.
     *
     * كشفها الحارس نفسه عند توسعته، وإصلاحُها **يغيّر من يقدر على ماذا** — وهو قرار
     * منتجٍ لا إصلاحُ عطل. فتُسمَّى هنا بدل أن تُحذف بصمت، ويبقى الحارس فاعلاً ضدّ
     * أيّ منحةٍ ميّتةٍ **جديدة**.
     *
     * **وأُصلحت منها واحدة:** «تشغيل تلخيص الفريق القانوني» — كان مسارا `analyze`
     * للموظّف والمحامي محروسَين بـ«استقبال الاستشارات» لا بها، فمن ينزعها عن محامٍ
     * يظنّ أنه منع التلخيص ولا يمنعه، **والموظّف لا يملكها ويشغّله**. صار الوسيط
     * عليهما، وأُضيفت إلى **سقف** الموظّف بلا منحٍ افتراضيّ (يبقى للإدارة مفتاحُ
     * استثناء). انظر `ConsultAnalyzePermissionTest`.
     *
     * @var array<string, list<string>> الدور => صلاحيّاتٌ ممنوحةٌ لا يبلغ مساراتها
     */
    private const KNOWN_DEAD = [
        'employee' => ['توزيع التذاكر', 'إشعارات العملاء', 'إدارة المواعيد والحجوزات'],
        'lawyer' => ['اعتماد الاجتماعات', 'تقارير الاجتماعات'],
    ];

    /** **الحارس الأثمن:** لا منحةَ بلا بابٍ يفتحه صاحبُها. */
    public function test_every_granted_permission_opens_at_least_one_reachable_route(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        /** @var array<string, array<int,string|null>> $gates صلاحيّة => قوائم الأدوار المسموحة لكلّ مسارٍ يشترطها */
        $gates = [];
        foreach ($routes as $route) {
            foreach ($this->permissionsRequiredBy($route) as $perm) {
                $gates[$perm][] = $this->rolesAllowedBy($route);
            }
        }

        $dead = [];

        foreach (Permissions::ROLE_PERMISSIONS as $role => $perms) {
            if ($perms === 'ALL') {
                continue;
            }

            foreach ($perms as $perm) {
                // صلاحيّةٌ لا يشترطها أيّ مسار: تُحرس في مكانٍ آخر (سياسة/واجهة) — خارج المدى
                if (! isset($gates[$perm])) {
                    continue;
                }

                $reachable = false;
                foreach ($gates[$perm] as $allowed) {
                    if ($allowed === null || in_array($role, $allowed, true)) {
                        $reachable = true;
                        break;
                    }
                }

                if ($reachable) {
                    continue;
                }

                if (in_array($perm, self::KNOWN_DEAD[$role] ?? [], true)) {
                    continue; // دَينٌ موثَّقٌ ينتظر قرار المالك — لا مفاجأةٌ جديدة
                }

                $dead[] = "«{$perm}» ممنوحةٌ لدور «{$role}» وكلُّ مساراتها محجوبةٌ عنه";
            }
        }

        $this->assertSame([], $dead, "منحةٌ لا تفتح باباً:\n".implode("\n", $dead));
    }

    /**
     * **والقوالبُ الجاهزة كذلك** — فمن يُنشأ بالقالب يرث المنحة الميتة نفسها.
     *
     * القالب يُنشئ دور spatie، والمنحةُ فيه تُعرض في شاشة الصلاحيّات كما تُعرض في
     * `ROLE_PERMISSIONS` — والخطر على من يأتي لا على القائمين.
     */
    public function test_the_lawyer_preset_carries_no_dead_grant(): void
    {
        $this->assertNotContains(
            'أرشيف الاستشارات',
            Permissions::PRESETS['محامٍ'],
            'مساراتها الأربعة داخل `role:admin` و`EnsureRole` يحجب غير الإدارة بلا استثناء'
        );

        $this->assertNotContains('أرشيف الاستشارات', Permissions::ROLE_PERMISSIONS['lawyer']);
    }

    /**
     * **والدَّين الموثَّق ما زال ديناً** — فإن أُصلح سقط هذا الاختبار ووجب شطبُه.
     *
     * حارسٌ يمنع أن تتحوّل القائمة إلى ستارٍ دائم: كلُّ اسمٍ فيها يجب أن يكون ميّتاً
     * فعلاً، وإلّا فهو تبريرٌ لعطلٍ لم يعد قائماً.
     */
    public function test_the_documented_debt_is_still_real(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        foreach (self::KNOWN_DEAD as $role => $perms) {
            foreach ($perms as $perm) {
                $reachable = false;

                foreach ($routes as $route) {
                    if (! in_array($perm, $this->permissionsRequiredBy($route), true)) {
                        continue;
                    }

                    $allowed = $this->rolesAllowedBy($route);
                    if ($allowed === null || in_array($role, $allowed, true)) {
                        $reachable = true;
                        break;
                    }
                }

                $this->assertFalse(
                    $reachable,
                    "«{$perm}» صارت تفتح باباً لدور «{$role}» — احذفها من KNOWN_DEAD"
                );
            }
        }
    }

    /** **والمنحةُ التي أُزيلت لم تكن تعمل** — وإلّا كان الحذف كسراً لا إصلاحاً. */
    public function test_the_archive_routes_are_admin_only(): void
    {
        $found = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('أرشيف الاستشارات', $this->permissionsRequiredBy($route), true)) {
                continue;
            }

            $found++;
            $allowed = $this->rolesAllowedBy($route);

            $this->assertNotNull($allowed, 'كلُّ مساراتها مقيَّدةٌ بدور');
            $this->assertNotContains('lawyer', $allowed, 'ولا يبلغها المحامي');
        }

        $this->assertSame(4, $found, 'المسارات الأربعة: الفهرس والفيديو والصوت والتفريغ');
    }
}
