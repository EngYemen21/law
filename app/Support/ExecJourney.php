<?php

namespace App\Support;

/**
 * مراحل دورة حياة طلب التنفيذ (نظير CaseJourney + الواجهة EXEC_LIFE).
 */
class ExecJourney
{
    public const LIFE = ['فتح الطلب', 'تجهيز السند التنفيذي', 'القيد لدى محكمة التنفيذ', 'إجراءات التنفيذ', 'التحصيل والإغلاق'];

    public static function stage(string $status): int
    {
        return match ($status) {
            'جديد', 'قيد الفتح' => 0,
            'تجهيز السند التنفيذي' => 1,
            'مقيّد لدى محكمة التنفيذ' => 2,
            'جارٍ' => 3,
            'مكتمل', 'مغلق' => 4,
            default => 0,
        };
    }
}
