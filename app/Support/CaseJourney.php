<?php

namespace App\Support;

/**
 * مراحل دورة حياة القضية النشطة (تطابق CF_RAIL للجزء بعد التفعيل + الواجهة CASE_LIFE).
 * خريطة واحدة تُغذّي المرحلة والنغمة معاً، فلا تتباعد قائمتان.
 */
class CaseJourney
{
    // مراحل الـ FlowLine المعروضة للعميل/المحامي
    public const LIFE = ['تفعيل القضية', 'خطة العمل واللائحة', 'رفع الدعوى ومتابعة الجلسات', 'الحكم', 'الإغلاق والأرشفة'];

    /** الحالة => مرحلتها على المسار + نغمة شارتها (مطابقة لما كانت تكتبه المتحكّمات حرفياً) */
    public const STATUSES = [
        'بانتظار اعتماد الأتعاب' => ['at' => 0, 'tone' => 'b-amber'],
        'بانتظار سداد الأتعاب' => ['at' => 0, 'tone' => 'b-amber'],
        'قيد التحضير' => ['at' => 1, 'tone' => 'b-blue'],
        'منظورة' => ['at' => 2, 'tone' => 'b-blue'],
        'صدر الحكم' => ['at' => 3, 'tone' => 'b-cyan'],
        'مغلقة' => ['at' => 4, 'tone' => 'b-grey'],
        'مؤرشفة' => ['at' => 4, 'tone' => 'b-grey'],
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
