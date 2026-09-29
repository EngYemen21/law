<?php

namespace App\Support;

use App\Domain\Journey\Enums\CaseStatus;

/**
 * مراحل دورة حياة القضية النشطة (تطابق CF_RAIL للجزء بعد التفعيل + الواجهة CASE_LIFE).
 * خريطة واحدة تُغذّي المرحلة والنغمة معاً، فلا تتباعد قائمتان.
 */
class CaseJourney
{
    // مراحل الـ FlowLine المعروضة للعميل/المحامي
    public const LIFE = ['تفعيل القضية', 'خطة العمل واللائحة', 'رفع الدعوى ومتابعة الجلسات', 'الحكم', 'الإغلاق والأرشفة'];

    /** الحالة => مرحلتها على المسار. (اللون من `CaseStatus::tone` — كانت الخريطة تحمل نسخةً ثانية منه) */
    public const STATUSES = [
        'بانتظار اعتماد الأتعاب' => ['at' => 0],
        'بانتظار سداد الأتعاب' => ['at' => 0],
        'قيد التحضير' => ['at' => 1],
        // رُفعت صحيفتها في ناجز وتنتظر قيد المحكمة (الخطّة ب — 2026-09-11)
        'بانتظار القيد' => ['at' => 2],
        'منظورة' => ['at' => 2],
        'صدر الحكم' => ['at' => 3],
        'مغلقة' => ['at' => 4],
        'مؤرشفة' => ['at' => 4],
    ];

    /*
     * **مجموعاتٌ من المصدر نفسه.** كانت تبويبات العميل والإدارة قوائمَ مكتوبةً باليد تعدّ
     * حالاتٍ لا يكتبها أيّ مسار («قيد الترافع»، «محكومة»، «جلسة قادمة»…) — فمؤشّرٌ صفرٌ أبداً،
     * وقضايا حقيقيّة لا تظهر إلا في «الكل». كلّ مجموعةٍ هنا من مفاتيح `STATUSES`.
     */
    public const FEE_PENDING = ['بانتظار اعتماد الأتعاب', 'بانتظار سداد الأتعاب'];

    public const ACTIVE = ['قيد التحضير', 'بانتظار القيد', 'منظورة'];

    public const JUDGED = ['صدر الحكم'];

    public const CLOSED = ['مغلقة', 'مؤرشفة'];

    /**
     * **بعد الحكم وقبل الأرشفة** — ما يقبل تصحيح الحكم والاستئناف وفتح ملفّ التنفيذ.
     * كانت القائمة مكتوبةً يدويّاً في ثمانية حرّاس.
     */
    public const POST_JUDGMENT = [...self::JUDGED, 'مغلقة'];

    /**
     * تبويبات العميل — كلّ حالةٍ في تبويبٍ واحد. «نشطة» تضمّ «صدر الحكم»: الملفّ ما زال
     * مفتوحاً للتنفيذ والإغلاق، ولا يُعدّ «مكتملاً» قبل أن يُغلق.
     *
     * @return array{active: list<string>, fees: list<string>, completed: list<string>}
     */
    public static function clientTabs(): array
    {
        return [
            'active' => [...self::ACTIVE, ...self::JUDGED],
            'fees' => self::FEE_PENDING,
            'completed' => self::CLOSED,
        ];
    }

    /**
     * تبويبات الإدارة — قسمةٌ على الحالات السبع.
     *
     * @return array{pendingFee: list<string>, active: list<string>, judged: list<string>, closed: list<string>}
     */
    public static function adminTabs(): array
    {
        return [
            'pendingFee' => self::FEE_PENDING,
            'active' => self::ACTIVE,
            'judged' => self::JUDGED,
            'closed' => self::CLOSED,
        ];
    }

    public static function stage(string $status): int
    {
        return self::STATUSES[$status]['at'] ?? 0;
    }

    /** النغمة القانونية للحالة — من الـEnum (`CaseStatus::tone`) وحده؛ كانت هنا خريطةٌ ثانية مطابقة */
    public static function toneFor(string $status): string
    {
        return CaseStatus::tryFrom($status)?->tone() ?? 'b-blue';
    }

    /** تسمية العميل المنفصلة للحالة (المرحلة ج) */
    public static function clientLabel(string $status): string
    {
        return CaseStatus::tryFrom($status)?->clientLabel() ?? $status;
    }
}
