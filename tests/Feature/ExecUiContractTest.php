<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Models\Execution;
use Tests\TestCase;

/**
 * **شاشة التنفيذ لا تعرض ما يردّه الخادم.** (تدقيق 2026-09-12)
 *
 * عقودٌ تنكسر بصمت: بطاقة ناجز كانت تُفتح لكلّ موظّفٍ بحكم دوره لا بصلاحيّته فتظهر أزرارٌ
 * تُردّ بـ403؛ وزرّ «إنهاء الملفّ» يُعرض لمن لا يملكه؛ وعرضُ العميل يطبع ضريبةً ثابتة 15%
 * بينما الخادم يحسبها من الإعدادات؛ وشاشة العميل لدى الإدارة تُرقّم المراحل «8/10» بينما
 * شاشة التنفيذ تسمّيها. الاختبار يقيس نصّ الواجهة لأنّ هذه العقود لا يمسّها أي طلب HTTP.
 */
class ExecUiContractTest extends TestCase
{
    private function ui(string $path): string
    {
        return (string) file_get_contents(resource_path($path));
    }

    /** بطاقة ناجز: للعميل اطّلاعٌ مقفل، وللمكتب فعلٌ مشروطٌ بالصلاحيّة لا بالدور. */
    public function test_the_najiz_card_is_read_only_for_the_client_and_permissioned_for_staff(): void
    {
        $ui = $this->ui('js/pages/execflow.tsx');

        // شاشة العميل تمرّر القفل صراحةً — لا تُشتقّ من الدور داخل البطاقة
        $this->assertStringContainsString('canAct={false}', $ui);

        // شاشة المكتب: الموظّف يفعل بصلاحيّة «إجراءات المحكمة والجلسات» وحدها
        $this->assertStringContainsString("useCan()('إجراءات المحكمة والجلسات')", $ui);
        $this->assertStringContainsString("const canNajiz = (role === 'employee' ? canCourt : role !== 'client') && !r.closed;", $ui);
        $this->assertStringContainsString('canAct={canNajiz}', $ui);
        $this->assertStringNotContainsString("canAct={role !== 'client' && !r.closed}", $ui);
    }

    /** «إنهاء الملفّ» لا يُعرض للموظّف: قيدٌ مستقلّ عن باقي خطوات ناجز. */
    public function test_closing_the_file_is_never_offered_to_an_employee(): void
    {
        $page = $this->ui('js/pages/execflow.tsx');
        $card = $this->ui('js/lib/exec-najiz.tsx');

        $this->assertStringContainsString("const canCloseFile = (role === 'lawyer' || role === 'admin') && !r.closed;", $page);
        $this->assertStringContainsString('canClose={canCloseFile}', $page);

        // البطاقة نفسها لا ترسم الزرّ ولا نموذجه بغير الإذن
        $this->assertStringContainsString('{canClose && <button', $card);
        $this->assertStringContainsString("{open === 'close' && canClose && (", $card);
    }

    /** خطوات ناجز تتبع ترتيب البيانات، ولا تُعرض على ملفٍّ أُنهي. */
    public function test_the_najiz_steps_follow_the_data_order_and_vanish_on_a_closed_file(): void
    {
        $card = $this->ui('js/lib/exec-najiz.tsx');

        // مفاتيح الأزرار وحدها — لا عناوين البيانات أعلى البطاقة، فهي تسبقها في النصّ
        $order = ["'file' ? null : 'file'", "'register' ? null : 'register'", "'notify' ? null : 'notify'", "'measures' ? null : 'measures'", "'collect' ? null : 'collect'"];
        $last = -1;

        foreach ($order as $step) {
            $at = mb_strpos($card, $step);
            $this->assertNotFalse($at, $step);
            $this->assertGreaterThan($last, $at, "خطوة خارج ترتيبها: {$step}");
            $last = $at;
        }

        $this->assertStringContainsString('const locked = Boolean(najiz.closedReason) || stage >= 9;', $card);
        $this->assertStringContainsString('{canAct && !locked && (', $card);
    }

    /** القوائم المسموحة من الخادم، والثوابت المحلّيّة احتياطٌ لا مصدر. */
    public function test_the_measure_and_close_lists_come_from_the_server_payload(): void
    {
        $card = $this->ui('js/lib/exec-najiz.tsx');
        $lib = $this->ui('js/lib/exec-flow.ts');

        $this->assertStringContainsString('najiz.measureOptions?.length ? najiz.measureOptions : EXEC_MEASURES', $card);
        $this->assertStringContainsString('najiz.closeReasons?.length ? najiz.closeReasons : EXEC_CLOSE_REASONS', $card);
        $this->assertStringContainsString('{measureOptions.map((m) => (', $card);
        $this->assertStringContainsString('{closeReasons.map((r) =>', $card);
        $this->assertStringContainsString('measureOptions?: string[];', $lib);
        $this->assertStringContainsString('closeReasons?: string[];', $lib);
    }

    /** المبلغ المحصَّل يُطبَّع قبل الإرسال ولا يُرسَل إلّا صحيحاً موجباً. */
    public function test_the_collected_amount_is_normalised_before_it_is_posted(): void
    {
        $card = $this->ui('js/lib/exec-najiz.tsx');

        $this->assertStringContainsString('inputMode="numeric"', $card);
        $this->assertStringContainsString('const collectAmount = normalizeDigits(collect.amount);', $card);
        $this->assertStringContainsString('const collectOk = /^', $card);
        $this->assertStringContainsString("act('addCollection', { amount: collectAmount, note: collect.note }", $card);
        $this->assertStringContainsString('disabled={busy || !collectOk}', $card);
        // النصّ الحرّ كان يُرسَل كما كُتب
        $this->assertStringNotContainsString("act('addCollection', collect,", $card);
    }

    /**
     * عرض العميل يطبع نسبة الضريبة التي حسبها الخادم لا 15% منقوشة.
     *
     * كان التأكيد يعدّ النصّ الحرفيّ **مرّتين بالضبط**، وهو عدٌّ يكسره كلُّ بطاقةٍ جديدة تطبع
     * الضريبة ولو طبعتها صحيحةً. والمقصود أقوى من العدد: ألّا يُكتب النصّ في الشاشة أصلاً،
     * بل يُشتقّ من مصدرٍ واحد (`execVatLabel`) يقرأ نسبة الخادم — فلا نسخةَ تتخلّف.
     */
    public function test_the_client_offer_card_no_longer_hard_codes_the_vat_rate(): void
    {
        $ui = $this->ui('js/pages/execflow.tsx');
        $lib = $this->ui('js/lib/exec-flow.ts');

        $this->assertStringNotContainsString('ضريبة القيمة المضافة (15%)', $ui);
        // ولا نسخةٌ واحدة من النصّ في الشاشة — المصدر دالّةٌ واحدة تقرأ نسبة الخادم
        $this->assertStringNotContainsString('ضريبة القيمة المضافة (', $ui);
        $this->assertStringContainsString('export function execVatLabel', $lib);
        // والنسبة إلزاميّة بلا افتراضٍ منقوش — «?? 15» كانت نسخةً من الإعداد تظهر متى غاب
        $this->assertStringContainsString('(${rate}%)', $lib);
        $this->assertStringNotContainsString('?? 15', $lib);
        // وبطاقتا المكتب والعميل كلتاهما تناديانها
        $this->assertGreaterThanOrEqual(2, substr_count($ui, 'execVatLabel(r.vatRate)'));
    }

    /** الإجراء التالي يصف مطلب المرحلة فعلاً، ويصمت عن ملفٍّ أُغلق. */
    public function test_the_next_action_hint_matches_the_stage_and_the_closed_file(): void
    {
        $ui = $this->ui('js/pages/execflow.tsx');
        // الدالّة وحدها — `ActionCard` بعدها تشترط `stage >= 7` لأسبابها
        $fn = (string) mb_strstr(mb_strstr($ui, 'const nextAction = '), 'const ExecList', true);

        $this->assertStringContainsString('nextAction(role, r, canCourt)', $ui);
        $this->assertStringContainsString('ارفع الطلب في ناجز وسجّل رقمه', $fn);
        $this->assertStringContainsString('if (r.closed) {', $fn);
        // كان `stage >= 7` يطلب إضافة الإجراءات حتى في مرحلة الرفع، ويأمر الموظّف بمتابعة ملفٍّ مغلق
        $this->assertStringNotContainsString('r.stage >= 7', $fn);
    }

    /** حدُّ رفع المستند المطلوب معلَنٌ في الشاشة — يطابق ExecFlowController::uploadDocument. */
    public function test_the_requested_document_upload_states_its_limits(): void
    {
        $lib = $this->ui('js/lib/exec-flow.ts');
        $ui = $this->ui('js/pages/execflow.tsx');

        $this->assertStringContainsString('2MB', $lib);
        $this->assertStringContainsString("EXEC_REQ_DOC_ACCEPT = '.pdf,.jpg,.jpeg,.png,.docx'", $lib);
        $this->assertStringContainsString('{EXEC_REQ_DOC_HINT}', $ui);
        $this->assertStringContainsString('accept={EXEC_REQ_DOC_ACCEPT}', $ui);
        // مرفقات المحادثة أوسع — ولا تُمسّ
        $this->assertStringContainsString('hint={EXEC_DOC_HINT}', $ui);
    }

    /** شاشتا الإدارة والتنفيذ تسمّيان المرحلة نفسها، ولوحة العميل لا تُسقط ما يصلها. */
    public function test_the_admin_and_client_screens_speak_the_same_stage_language(): void
    {
        $admin = $this->ui('js/pages/admin/client-detail.tsx');
        $dash = $this->ui('js/pages/dashboard.tsx');

        $this->assertStringNotContainsString('{ex.stage + 1}/10', $admin);
        // اسم المرحلة من الخادم (`Execution::stageLabel`) — لا فهرسة نسخة الواجهة فتخرج الشارة فارغة
        $this->assertStringContainsString('{ex.stageLabel}', $admin);
        $this->assertStringContainsString('e.stageLabel', $dash);
        foreach ([$admin, $dash, $this->ui('js/pages/execflow.tsx')] as $src) {
            $this->assertStringNotContainsString('EXEC_FLOW[', $src, 'الشارة لا تفهرس EXEC_FLOW');
        }
        $this->assertStringContainsString('{e.court && ', $dash);
    }

    /**
     * **شريط الخطوات يطابق الـEnum** — `EXEC_FLOW` باقٍ لـ`FlowLine` وحده، فلا ينجرف عن `ExecutionStatus`
     * بترتيب مراحلها؛ والشارة تقرأ `stageLabel` فتُعطي «مغلق» لمرحلةٍ قديمة خارج الشريط.
     */
    public function test_the_steps_line_matches_the_status_enum_and_labels_come_from_the_server(): void
    {
        preg_match('/export const EXEC_FLOW = \[(.*?)\];/s', $this->ui('js/lib/exec-flow.ts'), $m);
        preg_match_all("/'([^']+)'/u", $m[1] ?? '', $labels);
        $expected = array_map(fn (int $stage) => ExecutionStatus::fromStage($stage)->value, range(0, 9));
        $this->assertSame($expected, $labels[1]);

        $exec = new Execution;
        $exec->stage = 10; // بيانات قديمة: «منفّذ»
        $exec->status = 'منفّذ';
        $this->assertSame(ExecutionStatus::Closed->value, $exec->stageLabel());
    }
}
