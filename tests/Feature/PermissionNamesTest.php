<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **كلّ اسم صلاحيّةٍ مستعمَل موجودٌ في الكتالوج، والخادم يكتبه بثابته** (خطّة «إزالة التعارض» — المرحلة ٣).
 *
 * `can()` لاسمٍ غير موجود تُرجع false ولا تشكو، ووسيط المسار يصدّ الجميع صامتاً — فخطأٌ إملائيّ
 * واحد يُغلق باباً أو يُخفي زرّاً بلا أثر. الكتالوج (`Permissions`) المصدر الواحد: الخادم يقرأ
 * ثوابته، والواجهة تكتب الاسم نصّاً (قرار المالك: لا نسخة ثانية) ويحرسها هذا الفحص.
 * والاتّجاه المعاكس (كلّ صلاحيّةٍ معرَّفة تحرس شيئاً) في `TechnicalDebtTest`.
 */
class PermissionNamesTest extends TestCase
{
    public function test_every_route_permission_exists_in_the_catalogue(): void
    {
        $unknown = collect(app('router')->getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($mw) => is_string($mw) && str_starts_with($mw, 'permission:'))
            ->flatMap(fn ($mw) => explode(',', substr($mw, strlen('permission:'))))
            ->reject(fn ($name) => in_array($name, Permissions::all(), true))
            ->unique()->values()->all();

        $this->assertSame([], $unknown, 'مسارٌ يشترط صلاحيّةً لا وجود لها — يُصدّ الجميع');
    }

    public function test_every_frontend_permission_check_names_a_real_permission(): void
    {
        $found = 0;
        $unknown = [];

        $files = Finder::create()->files()->in(resource_path('js'))->exclude(['actions', 'routes'])->name(['*.ts', '*.tsx']);
        foreach ($files as $file) {
            // `can('…')` من `useCan()` · `useCan()('…')` · `userCan('…')`
            preg_match_all("/(?:\\bcan|useCan\\(\\)|userCan)\\(\\s*'([^']+)'\\s*\\)/u", $file->getContents(), $m);
            foreach ($m[1] as $name) {
                $found++;
                if (! in_array($name, Permissions::all(), true)) {
                    $unknown[] = $file->getRelativePathname().": {$name}";
                }
            }
        }

        $this->assertGreaterThan(15, $found, 'تغيّر شكل الفحص في الواجهة — حدِّث النمط لا تُسقطه');
        $this->assertSame([], $unknown, 'زرٌّ يفحص صلاحيّةً لا وجود لها — يختفي عن الجميع');
    }

    public function test_the_server_names_permissions_by_constant_only(): void
    {
        $names = array_map(fn ($n) => preg_quote($n, '/'), Permissions::all());
        $literal = "'(?:".implode('|', $names).")'";
        $offenders = [];

        // المسارات: لا `'permission:…'` حرفيّاً (السطور المعلَّقة مستثناة)
        foreach (file(base_path('routes/web.php')) as $i => $line) {
            if (! str_starts_with(ltrim($line), '//') && str_contains($line, "'permission:")) {
                $offenders[] = 'routes/web.php:'.($i + 1);
            }
        }

        // الكود: لا اسمَ صلاحيّةٍ حرفيّاً في فحص (`can(` · `hasPermissionTo(` · `permission(` · `canAny(`)
        $files = Finder::create()->files()->in(app_path())->name('*.php')->notPath('Support/Permissions.php');
        foreach ($files as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match("/(?:->can|hasPermissionTo|::permission|->permission|canAny)\\([^)]*{$literal}/u", $line)) {
                    $offenders[] = 'app/'.$file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'اسم صلاحيّةٍ حرفيّاً — استعمل ثابتها من Permissions');
    }
}
