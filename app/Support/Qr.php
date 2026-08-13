<?php

namespace App\Support;

/**
 * مولّد QR زخرفي حتميّ (مربّعات ركنية + تعبئة عشوائية ثابتة حسب البذرة) — يطابق شكل qrRects/Qr
 * بالواجهة (resources/js/components/babylon/admin-charts.tsx). مشترك بين كل مصيّرات PDF
 * الخادمية (تقرير الاستشارة، بطاقة الموعد، ...) لتفادي تكرار الخوارزمية.
 */
class Qr
{
    public static function svg(string $seed, int $px = 78): string
    {
        $n = 21;
        $cell = 4;
        $size = $n * $cell;
        mt_srand(crc32($seed));

        $fin = function (int $x, int $y) use ($n): bool {
            $f = fn ($a, $b) => $x >= $a && $x < $a + 7 && $y >= $b && $y < $b + 7;

            return $f(0, 0) || $f($n - 7, 0) || $f(0, $n - 7);
        };

        $rects = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($fin($x, $y)) {
                    $ix = $x < 7 ? $x : $x - ($n - 7);
                    $iy = $y < 7 ? $y : $y - ($n - 7);
                    $on = $ix === 0 || $ix === 6 || $iy === 0 || $iy === 6 || ($ix >= 2 && $ix <= 4 && $iy >= 2 && $iy <= 4);
                } else {
                    $on = (mt_rand(0, 1000) / 1000) > 0.55;
                }
                if ($on) {
                    $rects[] = '<rect x="'.($x * $cell).'" y="'.($y * $cell).'" width="'.$cell.'" height="'.$cell.'"/>';
                }
            }
        }

        return '<svg width="'.$px.'" height="'.$px.'" viewBox="0 0 '.$size.' '.$size.'" fill="#0A2A55" xmlns="http://www.w3.org/2000/svg">'.implode('', $rects).'</svg>';
    }
}
