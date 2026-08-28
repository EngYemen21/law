<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * نافذة التقويم الزمنية — مصدر واحد لشرط «داخل المدى أو بلا موعد محدَّد».
 *
 * لماذا: كانت الإغلاقة نفسها مكتوبة في ثلاثة متحكّمات (وثلاث مرّات داخل متحكّم الموظف).
 * وأخطر ما فيها `whereNull('starts_at')`: إسقاطه سهواً في نسخة واحدة يُخفي كل سجلّ بلا موعد
 * محدَّد من ذلك التقويم **صامتاً** — لا خطأ ولا أثر، فقط سجلّات تختفي.
 *
 * المدى يختلف بالدور عمداً ولا يُوحَّد: تقويم العميل سجلّه الشخصي (سنة/سنة) · المحامي
 * متابعة عمل (−30/+90) · الموظف أداة تنسيق لما هو قادم (−7/+90).
 */
class CalendarWindow
{
    /** سقف السجلّات لكل نوع — حماية من تحميل تاريخ المكتب كلّه في حمولة Inertia واحدة. */
    public const LIMIT = 300;

    /**
     * نافذة العميل: سجلّ شخصي لا أداة تنسيق.
     *
     * الماضي مسقوف بسنة (حماية الأداء — قصّ ماضٍ قديم محتمَل)، أمّا المستقبل فمفتوح
     * عملياً: كان مسقوفاً بسنة فيختفي **موعد حجزه العميل ولم يأتِ بعد** من «القادمة» —
     * وإخفاء التزام قادم أسوأ بكثير من إخفاء أرشيف قديم.
     */
    public static function forClient(): \Closure
    {
        return self::between(now()->subYear(), now()->addYears(5));
    }

    /** نافذة المحامي: يتابع جلسات مضت ونتائجها، فماضيه أوسع من ماضي الموظف. */
    public static function forLawyer(): \Closure
    {
        return self::between(now()->subDays(30), now()->addDays(90));
    }

    /** نافذة المكتب (موظف/إدارة): تنسيق ما هو قادم لا أرشيف. */
    public static function forOffice(): \Closure
    {
        return self::between(now()->subDays(7), now()->addDays(90));
    }

    /**
     * الشرط: داخل المدى **أو** بلا موعد محدَّد.
     * `whereNull` إلزامي — سجلّ لم يُجدول بعد يجب أن يبقى ظاهراً لا أن يختفي.
     */
    private static function between(\DateTimeInterface $from, \DateTimeInterface $to): \Closure
    {
        return fn (Builder $q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]);
    }
}
