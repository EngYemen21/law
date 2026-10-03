<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لوحات Zoom المجاورة للفيديو تبقى داخل الغرفة (ملاحظة المالك 2026-10-03).**
 *
 * Zoom 6.2.0 يضع لوحاته (منها «Meeting Summary has been enabled») على `body` بـ popper.js وموضعٍ ثابت
 * `left-start` بجوار الفيديو، ولا يُضبط من `customize`. والفيديو يملأ الغرفة، فخرجت النافذة خارج الشاشة —
 * وفيها زرّا «Got it» و«Leave meeting» — ومدّت الصفحة العربيّة أفقيّاً بمساحةٍ فاتحة. القاعدة في `babylon.css`
 * تثبّتها داخل الغرفة؛ هذا الحارس يفشل إن حُذفت أو فقدت ما يغلب أنماط popper.js المضمّنة.
 */
class RoomZoomPanelsInsideTest extends TestCase
{
    public function test_zoom_side_panels_are_pinned_inside_the_room(): void
    {
        $css = (string) file_get_contents(resource_path('css/babylon.css'));

        $found = preg_match(
            '#body:has\(\.mroom-page\)>\.zoom-MuiPopper-root\[role="dialog"\]:is\(\[data-popper-placement\^="left"\],\[data-popper-placement\^="right"\]\)\{([^}]*)\}#',
            $css,
            $m,
        );
        $this->assertSame(1, $found, 'لا قاعدة تثبّت لوحات Zoom المجاورة للفيديو داخل الغرفة');
        $rule = $m[1];

        // popper.js يكتب position/inset/transform مضمّنةً — بلا `!important` تعود اللوحة خارج الشاشة
        foreach (['position:fixed!important', 'transform:none!important', 'inset:', 'max-width:calc(100vw', 'max-height:', 'overflow:auto'] as $part) {
            $this->assertStringContainsString($part, $rule, "قاعدة لوحات Zoom فقدت «{$part}»");
        }
        // بلا z-index: نوافذ Zoom الإلزاميّة (موافقة التسجيل) تبقى فوقها
        $this->assertStringNotContainsString('z-index', $rule);
    }
}
