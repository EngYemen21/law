<?php

namespace App\Support\Finance;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/** تنسيقٌ مشترك لسندَي القبض والصرف ولشاشة المصروفات — موضعٌ واحد فلا يختلف سندٌ عن آخر. */
final class VoucherFormat
{
    /** «29 سبتمبر 2026» — أو «—» حين لا تاريخ. */
    public static function date(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }

        // `locale()` بمعاملٍ يضبط لغة النسخة (ويُنمَّط «نسخة أو نصّ» فلا يُسلسَل)
        $date = Carbon::instance($at);
        $date->locale('ar');

        return $date->translatedFormat('d F Y');
    }

    /** هللات ← «1,150.00 ر.س». */
    public static function sar(int $halalas): string
    {
        return number_format($halalas / 100, 2).' ر.س';
    }
}
