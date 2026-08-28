<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * أصناف CSS المستعملة في الواجهة يجب أن تكون معرَّفة فعلاً.
 *
 * `className="inp"` كان مستعملاً في سبعة مواضع (tickets · cases) وفي شريط الترشيح الذي
 * كتبتُه — و`.inp` **غير معرَّف في أي ملفّ CSS**؛ الصنف الصحيح `.input`. صنف غير موجود لا
 * يُسقط بناءً ولا يُنتج تحذيراً: الحقل يُصيَّر بلا تنسيق ويمرّ صامتاً حتى يراه مستخدم.
 */
class CssClassesExistTest extends TestCase
{
    /**
     * أصناف رُصدت مستعملة وهي غير معرَّفة — الحارس يمنع عودتها.
     *
     * `btn-group` أُضيف بعد مراجعة تبويب «التقويم والمواعيد»: كان على غلاف مبدّل
     * العرض في شاشة جدولة المواعيد بصفر تعريفات في app.css وbabylon.css. أثره
     * البصري كان معدوماً لأن التنسيق كلّه في style المجاور — أي صنف ميّت تماماً.
     */
    private const FORBIDDEN = ['inp', 'btn-group'];

    public function test_no_component_uses_an_undefined_css_class(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/resources/js'));
        foreach ($it as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $class) {
                if (str_contains($src, 'className="'.$class.'"')) {
                    $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).' ← "'.$class.'"';
                }
            }
        }

        $this->assertSame([], $offenders, "أصناف CSS غير معرَّفة:\n".implode("\n", $offenders));
    }

    /** والصنف البديل الصحيح موجود فعلاً في الأنماط. */
    public function test_the_real_input_class_is_defined(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/babylon.css');

        $this->assertStringContainsString('.input', $css);
    }
}
