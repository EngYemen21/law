<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Services\Ai\AiPromptRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **ما يكتبه النموذج يصل الشاشة مقروءاً — لا بنجومه.**
 *
 * تعليمة `consultSummarySystem` كانت مكتوبةً هي نفسها بـMarkdown (`**حصراً**`)، فالنموذج
 * يحاكي أسلوب تعليمته ويردّ بـ`**ملخص استشارة قانونية**`. والمشروع **بلا مُصيِّر
 * Markdown**، وكلّ مواضع العرض `white-space: pre-wrap` وحدها — فتصل النجوم حرفيّاً،
 * **وشاشة العميل منها** (`myconsults`). قِيس ذلك على استشارةٍ حقيقيّة في درج 360°.
 *
 * وعولج من طرفيه: نهيٌ في التعليمة (v3)، ومكوّن `RichText` عند العرض.
 */
class ConsultSummaryRenderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,string> الشاشات التي تعرض ملخّصاً، بلا تعليقات */
    private function screens(): array
    {
        $files = [
            'lib/consult-ui.tsx' => 'js/lib/consult-ui.tsx',
            'employee/consults.tsx' => 'js/pages/employee/consults.tsx',
            'lawyer/consults.tsx' => 'js/pages/lawyer/consults.tsx',
            'admin/consults.tsx' => 'js/pages/admin/consults.tsx',
            'myconsults.tsx' => 'js/pages/myconsults.tsx',
        ];

        $out = [];
        foreach ($files as $name => $rel) {
            $path = resource_path($rel);
            if (is_file($path)) {
                $out[$name] = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', (string) file_get_contents($path));
            }
        }

        return $out;
    }

    /** **الحارس الأثمن:** لا ملخّصَ يُعرض نصّاً خامّاً. */
    public function test_no_screen_renders_a_summary_raw(): void
    {
        $offenders = [];

        foreach ($this->screens() as $name => $code) {
            /*
             * يُلتقط عرضٌ خامّ: `{x.summary}` مفتوحاً بقوس JSX — ويُستثنى ما جاء خاصّةً
             * لمكوّنٍ (`text={x.summary}`)، فذاك هو الاستعمال الصحيح لا المخالفة.
             */
            preg_match_all(
                '/(?<!=)\{\s*(?:drawerConsult|consult|summaryOf|c)\.(?:summary|aiSummary|zoomSummary)\s*(?:\}|\|\|)/u',
                $code,
                $m
            );
            foreach ($m[0] as $hit) {
                $offenders[] = $name.': '.trim($hit);
            }
        }

        $this->assertSame([], $offenders, "ملخّصٌ يُعرض خامّاً بلا `RichText`:\n".implode("\n", $offenders));
    }

    /**
     * **ولا يُفتح باب الحقن.**
     *
     * المصدر نموذجٌ توليديّ؛ فإدراج مخرجه HTML خامّاً أسوأ من النجوم. `RichText` يبني
     * التوكيد عقداً (`<strong>`) و React يُهرِّب النصّ.
     */
    public function test_the_renderer_never_injects_raw_html(): void
    {
        $lib = $this->screens()['lib/consult-ui.tsx'];

        $this->assertStringContainsString('export const RichText', $lib, 'المُصيِّر موجود');
        $this->assertStringNotContainsString('dangerouslySetInnerHTML', $lib, 'لا إدراجَ HTML خام');
    }

    /** **وسُدّ المنبع:** التعليمة تنهى عن رموز التنسيق. */
    public function test_the_prompt_forbids_formatting_marks(): void
    {
        $prompt = AiPromptRegistry::consultSummarySystem();

        $this->assertStringContainsString('رموز تنسيق', $prompt);
        $this->assertSame('v3', AiPromptRegistry::PROMPTS['consult.summary']['version']);
    }

    /**
     * **ولا يُدعى المستخدم إلى فعلٍ يُصدّ عنه.**
     *
     * `refer` و`requestDocs` يمنعان `CLOSED_STATUSES` على الخادم، والدرج كان يعرض زرّ
     * الإسناد ونموذج طلب المستندات على استشارةٍ «منتهية» — فالضغط ٤٢٢ حتماً.
     */
    public function test_closed_files_hide_the_actions_the_server_refuses(): void
    {
        $code = $this->screens()['employee/consults.tsx'];

        $this->assertStringContainsString('CONSULT_CLOSED_STATUSES', $code, 'الكتالوج مستورد');
        $this->assertStringContainsString('const isClosed', $code);
        $this->assertStringContainsString('isClosed ?', $code, 'نموذج طلب المستندات مشروط');
        $this->assertStringContainsString('isClosed ||', $code, 'زرّ الإسناد معطَّل');
    }

    /**
     * **درجٌ يفيض يُمرَّر، لا يُقصّ.**
     *
     * أدراج 360° مبنيّةٌ `flex-direction: column` بارتفاع `100vh`، وحاوية محتواها
     * `flex: 1` مع `overflow-y: auto`. وهذا **لا يكفي**: العنصر المرن افتراضُه
     * `min-height: auto` فلا ينكمش دون حجم محتواه — فيتمدّد ويفيض، ويُقصّ ما زاد
     * **بلا شريط تمرير**.
     *
     * وأثرُه مقيسٌ على `CN-2026-7173`: سجلُّ تدقيقٍ من ستّة قيود يُقرأ منه أربعةٌ ونصف،
     * ولا سبيل إلى الباقي. والعطل كان في **خمسة أدراج** لا واحد.
     */
    public function test_every_drawer_body_can_actually_scroll(): void
    {
        $drawers = [
            'employee/consults.tsx' => 'js/pages/employee/consults.tsx',
            'admin/consults.tsx' => 'js/pages/admin/consults.tsx',
            'admin/consultrecv.tsx' => 'js/pages/admin/consultrecv.tsx',
            'admin/consult-requests.tsx' => 'js/pages/admin/consult-requests.tsx',
            'admin/archive.tsx' => 'js/pages/admin/archive.tsx',
            // سجلّ التدقيق الأمنيّ — أطول محتوى في اللوحة، وفيه العطل نفسه حرفيّاً
            'admin/audit-logs.tsx' => 'js/pages/admin/audit-logs.tsx',
        ];

        $offenders = [];

        foreach ($drawers as $name => $rel) {
            $path = resource_path($rel);
            if (! is_file($path)) {
                continue;
            }

            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', (string) file_get_contents($path));

            // كلّ حاوية `flex: 1` تُعلن `overflowY` يجب أن تُعلن `minHeight: 0` معها
            preg_match_all("/flex:\s*1,[^}]*overflowY:\s*'auto'/u", $code, $m);
            foreach ($m[0] as $hit) {
                if (! str_contains($hit, 'minHeight: 0')) {
                    $offenders[] = $name.': '.preg_replace('/\s+/u', ' ', $hit);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "حاوية مرنة تفيض بلا `minHeight: 0` — تُقصّ ولا تُمرَّر:\n".implode("\n", $offenders)
        );
    }

    /**
     * **وأبناء الجسم لا تنكمش دون محتواها.**
     *
     * `minHeight: 0` وحده لا يكفي: الجسم عمودٌ مرن وأبناؤه بطاقاتٌ افتراضُها
     * `flex-shrink: 1`، فتنكمش حين يضيق الارتفاع — و`.card` فيه `overflow: hidden`
     * (لاستدارة الحوافّ) فيُبتلع ما زاد **داخل البطاقة**. والأسوأ أنّ الجسم عندئذٍ لا
     * يفيض أصلاً فلا يظهر شريط تمرير ولا سبيل إلى المحتوى: **قِيس ملخّصٌ خُبِّئ منه
     * ٣٧٨ بكسلاً** في درج `CN-2026-7173`.
     */
    public function test_drawer_children_never_shrink_below_their_content(): void
    {
        $css = (string) file_get_contents(resource_path('css/babylon.css'));

        $this->assertMatchesRegularExpression(
            '/\.c360-drawer-body\s*>\s*\*\s*\{[^}]*flex-shrink:\s*0/u',
            $css,
            'القاعدة العامّة غائبة — البطاقات ستنكمش وتبتلع محتواها'
        );

        $missing = [];
        foreach ([
            'employee/consults.tsx' => 'js/pages/employee/consults.tsx',
            'admin/consults.tsx' => 'js/pages/admin/consults.tsx',
            'admin/consultrecv.tsx' => 'js/pages/admin/consultrecv.tsx',
            'admin/consult-requests.tsx' => 'js/pages/admin/consult-requests.tsx',
            'admin/archive.tsx' => 'js/pages/admin/archive.tsx',
            'admin/audit-logs.tsx' => 'js/pages/admin/audit-logs.tsx',
        ] as $name => $rel) {
            $path = resource_path($rel);
            if (is_file($path) && ! str_contains((string) file_get_contents($path), 'c360-drawer-body')) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, "جسمُ درجٍ بلا الصنف الحارس:\n".implode("\n", $missing));
    }

    /**
     * **وجدولٌ أعرضُ من حاويته يُمرَّر، لا يُقصّ.**
     *
     * للمشروع غلافُه: `.t-wrap { overflow-x: auto }` ([babylon.css:162](resources/css/babylon.css))،
     * ويستعمله `admin/tickets` وغيره. لكنّ **سبعة جداول** بعرضٍ أدنى ٧٢٠ بكسلاً كانت
     * بلا غلاف في `client-detail` و`clients` — فتفيض على النافذة الضيّقة وتُقصّ أعمدتُها
     * الأخيرة بلا سبيلٍ إليها.
     */
    public function test_wide_tables_are_wrapped_in_a_scroller(): void
    {
        $offenders = [];

        foreach (glob(resource_path('js/pages/**/*.tsx')) as $path) {
            $lines = explode("\n", (string) file_get_contents($path));

            foreach ($lines as $i => $line) {
                if (! preg_match('/<table className="tbl" style=\{\{ minWidth: \d+ \}\}>/u', $line)) {
                    continue;
                }

                // الغلاف يسبق الجدول بأسطرٍ قليلة
                $window = implode("\n", array_slice($lines, max(0, $i - 12), 13));
                if (! str_contains($window, 't-wrap') && ! str_contains($window, 'overflowX')) {
                    $offenders[] = basename(dirname($path)).'/'.basename($path).':'.($i + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "جدولٌ عريضٌ بلا غلافٍ يمرّر — تُقصّ أعمدتُه:\n".implode("\n", $offenders)
        );
    }

    /**
     * **خطُّ الرحلة يصف الرحلة التي سلكها هذا الملفّ.**
     *
     * `CONSULT_FLOW` رحلةُ **الاستقبال**: استشارةٌ تُفتح عند الموظّف فيراجعها ويحلّلها
     * ويعتمدها. أمّا القادمة من **حجزٍ على تذكرة** فلا تدخلها قطّ — رحلتُها تسعيرٌ
     * وسدادٌ وموعدٌ وجلسة.
     *
     * وكانت الصفحة تعرض الخطّ الأوّل للجميع، فتُبرَز «جاهزة/محالة للمحامي» لجلسةٍ
     * **انتهت واعتُمد ملخّصها وسُدّد ثمنها**. قِيس على `CN-2026-7173`: سجلّ تدقيقها يقول
     * تسعير ← سداد ← موعد ← جلسة، والشاشة تقول «معالجة الفريق القانوني».
     */
    public function test_the_journey_line_matches_the_file_origin(): void
    {
        $lib = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'))
        );

        $this->assertStringContainsString('CONSULT_BOOKING_FLOW', $lib, 'رحلة الحجز معروضة');
        $this->assertStringContainsString('cBookingStage(c.status, c.session)', $lib, 'المرحلة تُقاس بالحالة والجلسة معاً');
        $this->assertStringContainsString('const isBooking = !!c.ticketNo', $lib, 'المصدر يُميَّز');

        // ولا يُعرض خطُّ الاستقبال بلا شرطٍ كما كان
        $this->assertStringNotContainsString(
            '{cHasStage(c.status) && (',
            $lib,
            'خطُّ الاستقبال كان يُعرض للجميع بلا تمييز مصدر'
        );

        $data = (string) file_get_contents(resource_path('js/lib/employee-data.ts'));
        $this->assertStringContainsString('export const CONSULT_BOOKING_FLOW', $data);
        $this->assertStringContainsString('export function cBookingStage', $data);
    }

    /**
     * **ومخرجات الجلسة تُعرض بعد أن كانت محفوظةً ومخفيّة.**
     *
     * قِيس على `CN-2026-7173` بعد `zoom-sync`: المدّة والتسجيل والصوت والمشاركة وسجلّ
     * الحضور **خمستها في البطاقة**، والصفحة لا تعرض منها إلّا ملخّص Zoom.
     */
    public function test_session_outputs_are_displayed(): void
    {
        $lib = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'))
        );

        foreach (['c.duration', 'c.recording', 'c.zoomAudioUrl', 'c.zoomShareUrl', 'zoomParticipantsLog'] as $field) {
            $this->assertStringContainsString($field, $lib, "«{$field}» يصل البطاقة ولا يُعرض");
        }

        // ولا يُعرض قسمٌ فارغ يُوهم بجلسةٍ لم تُسجَّل
        $this->assertStringContainsString('hasSessionOutputs &&', $lib);
    }

    /**
     * **ووقتُ السجلّ لا يلتبس.**
     *
     * `format('Y/m/d h:i')` بلا ص/م يجعل ٠٠:٤٧ تُعرض «12:47» فتُقرأ ظهراً، ويبدو ترتيب
     * السجلّ مقلوباً وهو سليم.
     */
    public function test_the_audit_stamp_carries_a_meridiem(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-STAMP-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);

        $consult->logAudit('الإدارة', 'اختبار', '—', 'قيد');

        $stamp = $consult->audit[0]['time'];
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2} (ص|م)$#u', $stamp, "طابعٌ ملتبس: {$stamp}");
    }
}
