<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **لا صفحة بلا مسارٍ يعرضها** (قرار المالك 2026-09-28): كانت `admin/summaries` صفحةً قائمةً بمسارها
 * لا يربط إليها شيء وتكرّر تبويب «سجلّ المعتمد» في مركز الاعتمادات. كلّ ملفٍّ في `pages/` يُعرض من
 * `Inertia::render` أو `Route::inertia` — حرفيّاً، أو ببادئة الدور (`$this->prefix($request).'/x'`).
 */
class NoOrphanPagesTest extends TestCase
{
    public function test_every_page_is_rendered_by_a_controller_or_route(): void
    {
        $rendered = [];
        $php = '';
        foreach ((new Finder)->files()->in([app_path(), base_path('routes')])->name('*.php') as $f) {
            $php .= $f->getContents()."\n";
        }
        // Inertia::render('x') · Inertia::render($cond ? 'a' : 'b', …) · Route::inertia('/p', 'x')
        preg_match_all('/Inertia::render\\(([^;]*?)(?:,\\s*\\[|\\);)/s', $php, $calls);
        foreach ($calls[1] as $arg) {
            preg_match_all("/'([a-z][\\w\\/-]*)'/", $arg, $names);
            array_push($rendered, ...$names[1]);
            // بادئة الدور تُبنى في المتحكّم: `$this->prefix($request).'/consults'`
            if (preg_match("/prefix\\(\\\$request\\)\\s*\\.\\s*'\\/([\\w-]+)'/", $arg, $m)) {
                foreach (['admin', 'employee', 'lawyer'] as $role) {
                    $rendered[] = "{$role}/{$m[1]}";
                }
            }
        }
        preg_match_all("/Route::inertia\\([^,]+,\\s*'([^']+)'/", $php, $inertia);
        array_push($rendered, ...$inertia[1]);

        $orphans = [];
        foreach ((new Finder)->files()->in(resource_path('js/pages'))->name('/\\.tsx?$/') as $f) {
            $name = preg_replace('/\\.tsx?$/', '', str_replace('\\\\', '/', $f->getRelativePathname()));
            if (! in_array($name, $rendered, true)) {
                $orphans[] = $name;
            }
        }

        $this->assertSame([], $orphans, 'صفحةٌ لا يعرضها أيّ مسار — احذفها أو اربطها بمسار.');
    }
}
