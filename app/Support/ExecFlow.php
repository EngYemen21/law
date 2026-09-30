<?php

namespace App\Support;

use App\Domain\Journey\Enums\ExecutionStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * دورة حياة تدفّق طلب التنفيذ التجاريّ (10 مراحل) — نظير EXEC_FLOW في index (21).html.
 * مصدر واحد للمراحل والنغمة وثوابت النموذج (السندات/طرق السداد).
 */
class ExecFlow
{
    /**
     * **مجموعات قائمة التنفيذ** — تبويبات الفلترة وعدّاداتها في الأدوار كلّها (`execflow.tsx`). كانت الشروط
     * (`stage <= 1`…) مكتوبةً في الواجهة؛ والمجموعة الآن من الخادم على كلّ بطاقة (`bucket`).
     */
    public const BUCKETS = [
        'new' => 'جديدة/تحليل',
        'study' => 'دراسة/أتعاب',
        'offer' => 'عروض/سداد',
        'active' => 'قيد التنفيذ',
        'closed' => 'مغلقة',
    ];

    /**
     * أسباب إنهاء الملفّ كما ينتهي في الواقع (نظام التنفيذ): سدادٌ كامل، أو تسويةٌ بين الطرفين،
     * أو ثبوت إعسار، أو تنازل طالب التنفيذ. وكان الإغلاق بلا سبب فلا يُعرف كيف انتهى الحقّ.
     */
    public const CLOSE_REASONS = ['سداد كامل', 'تسوية', 'إعسار', 'تنازل طالب التنفيذ', 'أخرى'];

    public const SANADS = ['حكم قضائي', 'سند لأمر', 'شيك', 'عقد تنفيذي', 'محضر صلح', 'قرار تحكيم'];

    /** إجراءات عدم الوفاء بعد انقضاء مهلة أمر التنفيذ — تُسجَّل كما تُتَّخذ في ناجز. */
    public const MEASURES = ['منع السفر', 'إيقاف الخدمات الحكومية', 'إيقاف إصدار الوكالات', 'الإفصاح عن الأموال والحجز عليها', 'الحجز على المركبات والعقارات', 'البيع بالمزاد', 'الحبس التنفيذيّ'];

    /** مهلة الوفاء بعد الإبلاغ بأمر التنفيذ: خمسة أيام — الافتراض المُعلَن، والإدارة تضبطه من الإعدادات. */
    public const PAY_DAYS = 5;

    /**
     * **نهاية مهلة الوفاء.** أيامٌ تقويميّة كما هو معمولٌ به الآن، وتصير **أيام عمل** من تاريخ
     * نفاذ نظام التنفيذ الجديد (م/237 لعام 1447 — يُعمل به بعد ١٨٠ يوماً من نشره). التاريخ في
     * الإعدادات كي لا يحتاج تغييرُه نشرَ كود، والعطلة الأسبوعيّة الجمعة والسبت.
     */
    public static function payDueAfter(CarbonInterface $notifiedAt): CarbonInterface
    {
        $due = $notifiedAt->copy()->startOfDay();
        // المهلة من الإعدادات وافتراضها `PAY_DAYS` — تُحسب عند الإبلاغ فتثبت على الملفّ،
        // وتعديلُها لاحقاً لا يزحزح مهلةَ ملفٍّ أُبلغ قبله.
        $days = SettingsRegistry::int('exec_pay_days');

        if (! self::countsWorkingDays($notifiedAt)) {
            return $due->addDays($days);
        }

        for ($added = 0; $added < $days;) {
            $due = $due->addDay();
            if (! in_array($due->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
                $added++;
            }
        }

        return $due;
    }

    /**
     * هل يخضع **إبلاغٌ في هذا التاريخ** للنظام الجديد (فتُحسب مهلته بأيام العمل)؟ الحكم بتاريخ الإبلاغ لا بيوم إدخاله:
     * كان `now()` فيُعطي الإبلاغُ الواحد مهلتين بحسب يوم تسجيله (تدقيق الإعدادات 2026-09-30).
     */
    public static function countsWorkingDays(?CarbonInterface $on = null): bool
    {
        $from = SettingsRegistry::date('exec_working_days_from');

        return $from !== '' && ($on ?? now())->copy()->startOfDay()->gte(Carbon::parse($from)->startOfDay());
    }

    /** نغمة الشارة حسب المرحلة (تطابق execTone) */
    public static function tone(int $stage): string
    {
        return $stage >= 9 ? 'b-grey' : ($stage >= 7 ? 'b-green' : ($stage >= 5 ? 'b-amber' : ($stage >= 2 ? 'b-blue' : 'b-grey')));
    }

    /** اسم المرحلة — من `ExecutionStatus` وحده (كانت هنا نسخةٌ تعيد «طلب جديد» لمرحلةٍ خارجها). */
    public static function label(int $stage): string
    {
        return ExecutionStatus::fromStage($stage)->value;
    }

    /**
     * مجموعة الملفّ في القائمة (`BUCKETS`): المغلق أوّلاً، ثمّ بالمرحلة — والعرض المسدَّد قبل رفعه في
     * ناجز «قيد التنفيذ» (انفتح الملفّ لدى المكتب)، لا «عروض/سداد».
     */
    public static function bucket(int $stage, bool $paid, bool $closed): string
    {
        return match (true) {
            $closed || $stage >= 9 => 'closed',
            $stage <= 1 => 'new',
            $stage <= 4 => 'study',
            $stage <= 6 && ! $paid => 'offer',
            default => 'active',
        };
    }
}
