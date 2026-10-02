<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **الاجتماع يملأ الشاشة الزرقاء، ونوافذ Zoom تتّسع للهاتف، وشارة البيئة في اللوحة وحدها** (ملاحظة المالك 2026-10-02).
 *
 * ثبت من صورة الخادم: الاجتماع عمودٌ ضيّق (ribbon) في زاوية الغرفة — `anchorElement`/`placement` لا يقبلهما Zoom للفيديو
 * (تعريفات SDK 6.2.0: `VideoPopperStyle` يستثنيهما) ولا نوع عرضٍ افتراضيّ. ونوافذ Zoom عرضها الأدنى 480px
 * (`.zoom-MuiDialog-paper` في الحزمة) فتتجاوز الهاتف. والشارة كانت في جذر التطبيق فتظهر على الصفحة العامّة.
 */
class RoomFullscreenAndBadgeTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }

    public function test_zoom_video_fills_the_room_in_every_view(): void
    {
        $session = $this->src('js/lib/room-session.ts');

        $this->assertStringContainsString("defaultViewType: 'speaker'", $session);
        $this->assertStringContainsString('viewSizes: size ? { default: size, ribbon: size } : undefined', $session);
        $this->assertStringContainsString('viewSizes: { default: size, ribbon: size }', $session, 'إعادة القياس تشمل عرض الشريط');
        $this->assertStringNotContainsString('anchorElement: rootEl', $session, 'خيارٌ يتجاهله Zoom للفيديو');
    }

    public function test_zoom_dialogs_fit_a_phone_and_are_not_clipped(): void
    {
        $css = $this->src('css/babylon.css');

        $this->assertMatchesRegularExpression('/\.zoom-MuiDialog-paper\{min-width:0!important;max-width:calc\(100vw - 24px\)!important/', $css);
        $this->assertStringContainsString('.mroom-zoom[data-view="full"]{top:var(--mroom-head);inset-inline:0;bottom:0;overflow:visible}', $css);
    }

    public function test_environment_badge_lives_in_the_dashboard_only(): void
    {
        $this->assertStringNotContainsString('EnvironmentBadge', $this->src('js/app.tsx'), 'في الجذر تظهر على الصفحة العامّة والدخول');
        $this->assertStringContainsString('<EnvironmentBadge />', $this->src('js/components/layouts/AppLayout.tsx'));
        $this->assertStringContainsString('body:has(.mroom-head) .env-badge{display:none}', $this->src('css/babylon.css'));
    }

    /** حاوية صفحة الملخّص بحدٍّ أدنى لا ارتفاعٍ ثابت — الثابت ضغط بطاقة العنوان فتراكب عليها ما تحتها. */
    public function test_summary_page_container_is_not_fixed_height(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.summary-page-container \{[^}]*min-height: 100dvh;[^}]*\}/',
            $this->src('css/app.css'),
        );
        $this->assertDoesNotMatchRegularExpression('/\.summary-page-container \{[^}]*[^-]height: 100dvh;/', $this->src('css/app.css'));
    }
}
