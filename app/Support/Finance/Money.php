<?php

namespace App\Support\Finance;

/**
 * وحدة المال الموحّدة: الفواتير بالريال، والبوّابات والدفتر بالهللة. مصدرٌ واحد للتحويل والعملة — كان
 * `round(x * 100)` و`'SAR'` منقوشين في خدمة ميسّر والتسوية والمصروفات كلٌّ على حدة.
 */
final class Money
{
    public const CURRENCY = 'SAR';

    /**
     * **الحدّ الأعلى الواحد للأتعاب** — أتعاب القضيّة والتنفيذ (قرار المالك 2026-09-30). كان حدّ القضيّة رقماً
     * منقوشاً في متحكّمها، وأتعاب التنفيذ بلا حدّ فتُسقط فاتورتُها الحفظ بـ500 فوق سعة العمود.
     */
    public const MAX_FEE = 10_000_000;

    /** رسالة تجاوز الحدّ — نصٌّ واحد لحارسي الأتعاب. */
    public static function feeCeilingMessage(): string
    {
        return 'الأتعاب تتجاوز الحدّ الأعلى ('.number_format(self::MAX_FEE).' ريال).';
    }

    /** ريالٌ بكسره (نصّاً أو رقماً) ← هللات. */
    public static function halalas(string|float|int|null $riyals): int
    {
        return (int) round(((float) $riyals) * 100);
    }
}
