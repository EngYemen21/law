<?php

namespace App\Support;

/**
 * مراحل المخاطبة الرسميّة (يطابق CORR_FLOW في التصميم) — مصدر موحّد للتسميات والنغمات.
 */
class CorrFlow
{
    /** مراحل المكتب (فهرس = stage). */
    public const FLOW = [
        'إنشاء المخاطبة',       // 0
        'المراجعة القانونية',   // 1
        'اعتماد الإدارة',       // 2
        'الإرسال للجهة',        // 3
        'بانتظار الرد',         // 4
        'ورود الرد',            // 5
        'الإغلاق والأرشفة',     // 6
    ];

    /** مراحل رحلة العميل المبسّطة (يطابق CLIENT_CORR_FLOW). */
    public const CLIENT_FLOW = [
        'إعداد المخاطبة في المكتب',
        'الإرسال للجهة',
        'بانتظار رد الجهة',
        'ورود الرد',
        'إفادتك بالنتيجة',
    ];

    /** حالات النظام الخارجيّ (مستقلّة عن مرحلة المكتب). */
    public const EXT_STAGES = [
        'تم الإرسال',
        'تم الاستلام لدى الجهة',
        'قيد المعالجة لدى الجهة',
        'صدر الرد من الجهة',
    ];

    public static function label(int $stage): string
    {
        return self::FLOW[$stage] ?? self::FLOW[0];
    }

    public static function tone(int $stage): string
    {
        return match (true) {
            $stage >= 6 => 'b-green',
            $stage >= 4 => 'b-amber',
            $stage >= 2 => 'b-blue',
            default => 'b-grey',
        };
    }

    /** تحويل مرحلة المكتب إلى مرحلة رحلة العميل (يطابق clientCorrStage). */
    public static function clientStage(int $stage, bool $briefed): int
    {
        if ($stage < 3) {
            return 0;
        }
        if ($stage === 3) {
            return 1;
        }
        if ($stage === 4) {
            return 2;
        }
        if ($stage === 5) {
            return $briefed ? 4 : 3;
        }

        return 4;
    }
}
