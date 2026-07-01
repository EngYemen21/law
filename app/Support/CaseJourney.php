<?php

namespace App\Support;

/**
 * مراحل دورة حياة القضية النشطة (تطابق CF_RAIL للجزء بعد التفعيل + الواجهة CASE_LIFE).
 */
class CaseJourney
{
    // مراحل الـ FlowLine المعروضة للعميل/المحامي
    public const LIFE = ['تفعيل القضية', 'خطة العمل واللائحة', 'رفع الدعوى ومتابعة الجلسات', 'الحكم', 'الإغلاق والأرشفة'];

    public static function stage(string $status): int
    {
        return match ($status) {
            'بانتظار اعتماد الأتعاب', 'بانتظار سداد الأتعاب' => 0,
            'قيد التحضير' => 1,
            'منظورة' => 2,
            'صدر الحكم' => 3,
            'مغلقة', 'مؤرشفة' => 4,
            default => 0,
        };
    }
}
