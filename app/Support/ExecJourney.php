<?php

namespace App\Support;

/**
 * مراحل دورة حياة طلب التنفيذ (نظير CaseJourney + الواجهة EXEC_LIFE).
 * خريطة واحدة تُغذّي المرحلة والنغمة معاً، فلا تتباعد قائمتان.
 */
class ExecJourney
{
    public const LIFE = ['فتح الطلب', 'تجهيز السند التنفيذي', 'القيد لدى محكمة التنفيذ', 'إجراءات التنفيذ', 'التحصيل والإغلاق'];

    /** الحالة => مرحلتها على المسار + نغمة شارتها (مطابقة لما كانت تكتبه المتحكّمات حرفياً) */
    public const STATUSES = [
        'جديد' => ['at' => 0, 'tone' => 'b-blue'],
        'قيد الفتح' => ['at' => 0, 'tone' => 'b-blue'],
        'تجهيز السند التنفيذي' => ['at' => 1, 'tone' => 'b-blue'],
        'مقيّد لدى محكمة التنفيذ' => ['at' => 2, 'tone' => 'b-blue'],
        'جارٍ' => ['at' => 3, 'tone' => 'b-blue'],
        'مكتمل' => ['at' => 4, 'tone' => 'b-green'],
        'مغلق' => ['at' => 4, 'tone' => 'b-grey'],
    ];

    public static function stage(string $status): int
    {
        return self::STATUSES[$status]['at'] ?? 0;
    }

    /** النغمة القانونية للحالة — مصدر وحيد يمنع تلوين الحالة نفسها لونين باختلاف كاتبها */
    public static function toneFor(string $status): string
    {
        return self::STATUSES[$status]['tone'] ?? 'b-blue';
    }
}
