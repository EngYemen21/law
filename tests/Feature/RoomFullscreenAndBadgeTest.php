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

    /** صفحات المحادثة (القضيّة · التذكرة): المحادثة تثبت والقائمة الجانبيّة تنزل مع الصفحة — على الحاسوب وحده. */
    public function test_chat_pages_pin_the_chat_and_let_the_side_list_scroll(): void
    {
        foreach (['admin/case', 'employee/case', 'lawyer/case', 'employee/ticketchat', 'lawyer/ticketchat'] as $page) {
            $this->assertStringContainsString('className="tf-grid tf-chat"', $this->src("js/pages/{$page}.tsx"), $page);
        }

        $css = $this->src('css/babylon.css');
        $this->assertStringContainsString('.tflow .tf-grid.tf-chat>:first-child{position:sticky;top:84px;max-height:calc(100vh - 100px);overflow-y:auto}', $css);
        $this->assertStringContainsString('.tflow .tf-grid.tf-chat>.tf-aside{position:static}', $css);
        $this->assertMatchesRegularExpression('/@media\(min-width:1081px\)\{\s*\.tflow \.tf-grid\.tf-chat/', $css, 'الضيّق عمودٌ واحد بلا تثبيت');
    }

    /** بنود دراسة التنفيذ جملٌ طويلة — تلتفّ داخل الإطار لا تتجاوزه (909px في إطارٍ 651px قبلُ). */
    public function test_exec_study_items_wrap_inside_their_card(): void
    {
        $this->assertStringContainsString('className="chip chip-wrap"', $this->src('js/pages/execflow.tsx'));
        $this->assertStringContainsString('.chip.chip-wrap{white-space:normal;max-width:100%', $this->src('css/babylon.css'));
    }

    /**
     * محادثة التنفيذ عند الطاقم تعرض الملاحظات الداخليّة وتستقبلها لحظيّاً من قناة `.staff` — والعميل لا
     * (كانت مخفيّةً عن الطاقم نفسه لا لحظيّاً ولا بعد التحديث).
     */
    public function test_exec_chat_shows_internal_notes_to_staff_only(): void
    {
        $thread = $this->src('js/components/babylon/ChatThread.tsx');
        $this->assertStringContainsString('if (!staffNotes) return null;', $thread, 'العميل وصفحاته بلا ملاحظات');
        $this->assertStringContainsString('echo.private(staffChannel).listen(\'.message\', append);', $thread);
        $this->assertStringContainsString('<MsgRow key={i} m={m} staffNotes={staffNotes} />', $thread);

        $exec = $this->src('js/pages/execflow.tsx');
        $this->assertSame(1, substr_count($exec, 'staffNotes'), 'تبويب محادثة الطاقم وحده — لا صفحة العميل (`ClientExecDetail`)');
        $client = substr($exec, strpos($exec, 'const ClientExecDetail'), 6000);
        $this->assertStringNotContainsString('staffNotes', $client);
    }

    /**
     * **صندوق المحادثة مضغوطٌ على الهاتف** — كان الملتصق بأسفل الشاشة يأخذ نصفها (عنوان + ثلاثة أسطر + أزرار + سطر
     * الصيغ) فتمرّ الرسائل تحته مقصوصة؛ وشارة البيئة تغطّي زرّ الإرسال. ثبت بالمتصفّح على 390px: 420px ⇒ 69px.
     */
    public function test_the_chat_composer_is_compact_on_phones(): void
    {
        $thread = $this->src('js/components/babylon/ChatThread.tsx');
        $this->assertStringContainsString('className="composer chat-composer"', $thread);
        $this->assertStringContainsString('<span className="btn-txt">إرسال</span>', $thread);
        $this->assertStringContainsString('title={hint}', $thread, 'الصيغ المسموحة تبقى في تلميح زرّ الإرفاق');

        $css = $this->src('css/babylon.css');
        $this->assertStringContainsString('.composer.chat-composer{display:flex;align-items:flex-end', $css);
        $this->assertStringContainsString('.chat-composer .composer-label,.chat-composer .composer-hint{display:none}', $css);
        $this->assertStringContainsString('.env-badge{bottom:auto;top:4px', $css, 'الشارة أعلى الشاشة على الهاتف لا فوق زرّ الإرسال');
    }

    /**
     * **تبويبات ملفّ التنفيذ تلتفّ بعرض حاويتها** — كانت صفّاً واحداً بتمريرٍ أفقيّ مخفيّ، فعلى الهاتف وفي عمود
     * الـ1024 يخرج التبويب النشط «المحادثة» من الإطار (ثبت بالمتصفّح على 320–414 و1024 للمحامي والإدارة).
     */
    public function test_exec_tabs_wrap_to_their_container(): void
    {
        $css = $this->src('css/babylon.css');
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));', $css);
        $tabs = substr($css, strpos($css, '.exec-tabs {'), 400);
        $this->assertStringNotContainsString('overflow-x: auto', $tabs, 'لا تمرير أفقيّ يخفي التبويب النشط');

        $page = $this->src('js/pages/execflow.tsx');
        $this->assertStringContainsString("<bdi style={{ whiteSpace: 'nowrap' }}>#{r.id}</bdi>", $page, 'رقم الملفّ لا ينكسر');
    }

    /**
     * **ترويسة ملفّ التنفيذ تلتفّ** — من صورة المالك (393px، ملفٌّ مغلق له رقم تنفيذ): سطر «رجوع + الرقم + الشارات +
     * رقم التنفيذ» لا يلتفّ فتعرض الصفحة كلّها أعرض من الهاتف، و«مغلق» مرّتين (شارة المرحلة وشارة الإغلاق).
     */
    public function test_the_exec_header_wraps_and_says_closed_once(): void
    {
        $page = $this->src('js/pages/execflow.tsx');
        $this->assertStringContainsString("<div style={{ flex: '1 1 260px', minWidth: 0 }}>", $page);
        $this->assertStringContainsString("marginBottom: 6, flexWrap: 'wrap' }}>", $page);
        $this->assertStringContainsString('{r.closedBadge && <Badge text="مغلق"', $page);
        $this->assertStringNotContainsString('{r.closed && <Badge text="مغلق"', $page);
    }

    /**
     * **بنود «الملخّص الذكيّ» تلتفّ كبنود «دراسة التنفيذ»** — من صورة المالك (`tab=docs`): الإجراءات المقترحة في بطاقة
     * الملخّص نسخةٌ ثانية بـ`chip` لا يلتفّ، فتخرج الجملة الطويلة من البطاقة. الآن مكوّنٌ واحد (`StudyChips`).
     */
    public function test_the_ai_summary_procedures_use_the_wrapping_component(): void
    {
        $page = $this->src('js/pages/execflow.tsx');
        $this->assertStringContainsString('<StudyChips label="الإجراءات المقترحة" items={r.aiProcedures} />', $page);
        $this->assertStringNotContainsString('r.aiProcedures.map((p) => <span key={p} className="chip">', $page);
    }
}
