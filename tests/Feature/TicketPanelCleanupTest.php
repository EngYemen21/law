<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **حرّاس تنظيف لوحة «خيارات التذكرة» وزرّ الملخّص** (تشخيصٌ من استعمال المالك 2026-09-20).
 *
 * ثلاثة عناصرٍ أُزيلت أو بُدّلت، ولكلٍّ سببٌ يختلف — فالحارس يمنع عودتها سهواً:
 *
 * 1. **«تحويل إلى لائحة»** — كان معطَّلاً بلا معالج، ولا مسار له في الخادم ولا متحكّم ولا
 *    انتقال. عنصرُ عرضٍ لا يفتح وظيفة. ولائحة الدعوى وظيفةٌ قائمة تُدار من شاشة القضية.
 * 2. **«عرض ملف القضية»** — كان مكرّراً حرفاً: النصّ نفسه والوجهة نفسها في
 *    `TicketTrackDecisionCard` المعروض فوق اللوحة مباشرةً، فيظهر زرّان متلاصقان.
 * 3. **«تحويل إلى قضية رسمية»** — كان يحوّل مباشرةً بلا تسبيبٍ ولا اعتماد إدارة، فيلتفّ على
 *    حوكمة المسارات الأربعة (`ApproveOutcomeTrack`). أُخفي من الواجهة أوّلاً بقرار المالك،
 *    **ثمّ حُذف مساره في الخادم نهائيّاً** (ADR-009) — وغيابَه يؤكّده
 *    `TicketConvertContractTest::test_legacy_convert_routes_are_completely_absent`.
 *
 * وزرّ «تعديل الملخص» يصير «عرض الملخّص المعتمد» بعد الاعتماد: الخادم يرفض التعديل بعده
 * (`Lawyer\TicketController::updateSummary` يقذف 422)، فالنصّ القديم وعدٌ كاذب.
 */
class TicketPanelCleanupTest extends TestCase
{
    private const PANEL = 'js/components/babylon/TicketActionsPanel.tsx';

    /**
     * مصدرُ الملفّ **بلا تعليقات**.
     *
     * لماذا التجريد؟ لأنّ التعليق الذي يشرح سببَ الحذف يذكر اسم المحذوف حرفاً — وهو توثيقٌ
     * مقصود يمنع إعادته سهواً. فبحثٌ نصّيّ خام يلتقط الشرح ويظنّه عودةً للزرّ. الحارس يفحص
     * الشيفرة العاملة وحدها.
     */
    private function source(string $relative): string
    {
        $src = (string) file_get_contents(resource_path($relative));

        $src = (string) preg_replace('#\{/\*.*?\*/\}#s', '', $src);   // تعليق JSX
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);       // تعليق كتلة

        return (string) preg_replace('#//[^\n]*#', '', $src);         // تعليق سطر
    }

    public function test_the_dead_pleading_button_stays_out_of_the_panel(): void
    {
        $this->assertStringNotContainsString(
            'تحويل إلى لائحة',
            $this->source(self::PANEL),
            'عاد زرُّ اللائحة المعطَّل — لا مسار له في الخادم ولا وظيفة يفتحها.'
        );
    }

    public function test_the_duplicate_case_link_stays_out_of_the_panel(): void
    {
        $this->assertStringNotContainsString(
            'عرض ملف القضية',
            $this->source(self::PANEL),
            'عاد زرُّ «عرض ملف القضية» إلى اللوحة — ونظيرُه في TicketTrackDecisionCard، فيظهران متلاصقين.'
        );

        // وهو باقٍ في البطاقة: الحذف نقلٌ إلى مكانٍ واحد لا إلغاءٌ للوظيفة
        $this->assertStringContainsString(
            'عرض ملف القضية',
            $this->source('js/components/babylon/TicketTrackDecisionCard.tsx'),
            'ضاع الرابط من البطاقة أيضاً — فلا سبيل لبلوغ ملفّ القضية من محادثة التذكرة.'
        );
    }

    public function test_the_governance_bypassing_convert_button_stays_hidden(): void
    {
        $panel = $this->source(self::PANEL);

        $this->assertStringNotContainsString('تحويل إلى قضية رسمية', $panel, 'عاد الزرّ الذي يلتفّ على حوكمة المسارات.');
        $this->assertStringNotContainsString('/convert`', $panel, 'عاد بناء مسار التحويل المباشر في اللوحة.');
    }

    /** حظر التفاف التحويل إلى استشارة (ADR-009): مآل الاستشارة يمر حصراً عبر بطاقة حوكمة المسارات */
    public function test_the_duplicate_convert_to_consult_button_stays_out_of_the_panel(): void
    {
        $panel = $this->source(self::PANEL);

        $this->assertStringNotContainsString('تحويل التذكرة إلى استشارة', $panel, 'عاد زر تحويل التذكرة إلى استشارة الالتفافي في اللوحة.');
        $this->assertStringNotContainsString('convert-consult', $panel, 'عاد استدعاء مسار convert-consult في اللوحة.');
        $this->assertFalse(Route::has('tickets.convert-consult'), 'مسار tickets.convert-consult ما زال مسجلاً.');
    }

    /** الحوكمة نفسها تبقى مدخلاً قائماً — الإخفاء لا يُلغي طريقاً مشروعاً. */
    public function test_the_governed_track_decision_remains_reachable(): void
    {
        $this->assertStringContainsString(
            'track/approve',
            $this->source('js/components/babylon/TicketTrackDecisionCard.tsx'),
            'ضاع مسار قرار المآل المعتمد — ولا بديل له بعد إخفاء التحويل المباشر.'
        );
    }

    public function test_the_summary_button_changes_its_label_once_approved(): void
    {
        $chat = $this->source('js/pages/lawyer/ticketchat.tsx');

        $this->assertStringContainsString(
            "summary.approved ? 'عرض الملخّص المعتمد' : 'تعديل الملخص'",
            $chat,
            'عاد زرُّ «تعديل الملخص» بلا تمييزٍ للمعتمد — والخادم يرفض التعديل بعده بـ422.'
        );
    }

    /** زرَّا حفظ المسودة يُعطَّلان مع الحقول لا بعدها — وإلّا أعطيا 422. */
    public function test_both_draft_save_buttons_are_disabled_after_approval(): void
    {
        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', $this->source('js/pages/lawyer/summary.tsx'))));

        $this->assertSame(
            2,
            count(array_filter($lines, fn (string $l) => $l === 'disabled={isSaving || !canEdit}')),
            'أحد زرَّي حفظ المسودة عاد فعّالاً بعد الاعتماد.'
        );
    }
}
