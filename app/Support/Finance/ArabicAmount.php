<?php

namespace App\Support\Finance;

/**
 * **المبلغ كتابةً** على السندات: «فقط ألف ومائة وخمسون ريال سعودي لا غير».
 *
 * مكتوبٌ هنا لا عبر `NumberFormatter('ar', SPELLOUT)`: مخرجاته العربيّة تخطئ نحويّاً على مستندٍ
 * رسميّ («إحدى عشر ألف»، «خمسة مائة»، «إثنان مليون»). والعدد يُكتب على **المعدود المذكّر**
 * (الريال) — فالثلاثة إلى التسعة بالتاء — وتمييز الألوف والملايين يتبع قاعدة العدد:
 * واحد مفرد، اثنان مثنّى، ٣–١٠ جمع، ١١–٩٩ مفردٌ منصوب، وما عداها مفرد.
 */
final class ArabicAmount
{
    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];

    private const TEENS = [
        10 => 'عشرة', 11 => 'أحد عشر', 12 => 'اثنا عشر', 13 => 'ثلاثة عشر', 14 => 'أربعة عشر',
        15 => 'خمسة عشر', 16 => 'ستة عشر', 17 => 'سبعة عشر', 18 => 'ثمانية عشر', 19 => 'تسعة عشر',
    ];

    private const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /** [مفرد، مثنّى، جمع، مفرد منصوب] لكلّ مرتبة — من الأعلى إلى الأدنى. */
    private const SCALES = [
        1_000_000_000 => ['مليار', 'ملياران', 'مليارات', 'ملياراً'],
        1_000_000 => ['مليون', 'مليونان', 'ملايين', 'مليوناً'],
        1_000 => ['ألف', 'ألفان', 'آلاف', 'ألفاً'],
    ];

    /** المبلغ بالهللة ← نصّ السند كاملاً. */
    public static function riyals(int $halalas): string
    {
        $halalas = abs($halalas);
        $riyals = intdiv($halalas, 100);
        $rest = $halalas % 100;

        $parts = [];
        if ($riyals > 0 || $rest === 0) {
            $parts[] = self::words($riyals).' ريال سعودي';
        }
        if ($rest > 0) {
            $parts[] = self::words($rest).' هللة';
        }

        return 'فقط '.implode(' و', $parts).' لا غير';
    }

    /** العدد الصحيح كتابةً على المعدود المذكّر. */
    public static function words(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }

        $parts = [];
        foreach (self::SCALES as $scale => $forms) {
            $count = intdiv($n, $scale);
            if ($count > 0) {
                $parts[] = self::scaled($count, $forms);
                $n %= $scale;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowThousand($n);
        }

        return implode(' و', $parts);
    }

    /** @param array{0:string,1:string,2:string,3:string} $forms */
    private static function scaled(int $count, array $forms): string
    {
        if ($count === 1) {
            return $forms[0];
        }
        if ($count === 2) {
            return $forms[1];
        }

        $lastTwo = $count % 100;
        // «مائة ألف وألف» لا «مائة وواحد ألف»: الواحد والاثنان يُفصلان بتمييزهما
        if ($count > 100 && ($lastTwo === 1 || $lastTwo === 2)) {
            return self::scaled($count - $lastTwo, $forms).' و'.self::scaled($lastTwo, $forms);
        }

        $noun = match (true) {
            $lastTwo >= 3 && $lastTwo <= 10 => $forms[2],
            $lastTwo >= 11 => $forms[3],
            default => $forms[0],
        };

        $words = self::belowThousand($count);
        // المضاف يحذف نونه: «مائتا ألف» لا «مائتان ألف»
        if ($lastTwo === 0 && str_ends_with($words, 'مائتان')) {
            $words = mb_substr($words, 0, -1);
        }

        return $words.' '.$noun;
    }

    private static function belowThousand(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' و', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 10) {
            return self::ONES[$n];
        }
        if ($n < 20) {
            return self::TEENS[$n];
        }

        $ones = $n % 10;

        return $ones === 0 ? self::TENS[intdiv($n, 10)] : self::ONES[$ones].' و'.self::TENS[intdiv($n, 10)];
    }
}
