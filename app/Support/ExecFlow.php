<?php

namespace App\Support;

/**
 * دورة حياة تدفّق طلب التنفيذ التجاريّ (10 مراحل) — نظير EXEC_FLOW في index (21).html.
 * مصدر واحد للمراحل والنغمة وثوابت النموذج (السندات/طرق السداد).
 */
class ExecFlow
{
    /** المراحل بالترتيب (الفهرس = stage) */
    public const FLOW = [
        'طلب جديد', 'تحليل ذكي', 'قيد الدراسة', 'تحديد الأتعاب', 'اعتماد الإدارة',
        'عرض الخدمة', 'السداد', 'ملف تنفيذ', 'قيد التنفيذ', 'مغلق',
    ];

    public const SANADS = ['حكم قضائي', 'سند لأمر', 'شيك', 'عقد تنفيذي', 'محضر صلح', 'قرار تحكيم'];

    public const PAYM = ['دفعة واحدة', 'دفعات', 'حسب مراحل التنفيذ', 'نسبة من المحصّل'];

    /** نغمة الشارة حسب المرحلة (تطابق execTone) */
    public static function tone(int $stage): string
    {
        return $stage >= 9 ? 'b-grey' : ($stage >= 7 ? 'b-green' : ($stage >= 5 ? 'b-amber' : ($stage >= 2 ? 'b-blue' : 'b-grey')));
    }

    /** اسم المرحلة */
    public static function label(int $stage): string
    {
        return self::FLOW[$stage] ?? self::FLOW[0];
    }
}
