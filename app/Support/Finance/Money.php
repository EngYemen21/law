<?php

namespace App\Support\Finance;

/**
 * وحدة المال الموحّدة: الفواتير بالريال، والبوّابات والدفتر بالهللة. مصدرٌ واحد للتحويل والعملة — كان
 * `round(x * 100)` و`'SAR'` منقوشين في خدمة ميسّر والتسوية والمصروفات كلٌّ على حدة.
 */
final class Money
{
    public const CURRENCY = 'SAR';

    /** ريالٌ بكسره (نصّاً أو رقماً) ← هللات. */
    public static function halalas(string|float|int|null $riyals): int
    {
        return (int) round(((float) $riyals) * 100);
    }
}
