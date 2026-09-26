<?php

namespace App\Support;

/**
 * **العدد ومعدوده بالعربيّة** — «يوم واحد»، «يومين»، «3 أيام»، «14 يوماً»، «100 يوم».
 *
 * كانت الجمل التي تحمل رقماً مكتوبةً باليد عند كلّ موضع («خلال 3 أيام»، «خلال 14 يوماً»)
 * لأنّ الرقم كان ثابتاً. فلمّا صار الرقم إعداداً تضبطه الإدارة، صار المعدود يتبعه: «خلال 11 أيام»
 * خطأٌ يقرؤه العميل على فاتورته. فالقاعدة في موضعٍ واحد، والمنادي يمرّر الرقم وحده.
 *
 * القاعدة المعتمدة (بالأرقام لا بالحروف): ١ و٢ لفظان مستقلّان، و٣–١٠ جمعٌ، و١١–٩٩ مفردٌ
 * منصوب، ومضاعفات المئة وما يليها بواحدٍ أو اثنين مفردٌ مجرور.
 */
final class ArabicCount
{
    public static function days(int $n): string
    {
        return self::of($n, 'يوم واحد', 'يومين', 'أيام', 'يوماً', 'يوم');
    }

    public static function hours(int $n): string
    {
        return self::of($n, 'ساعة واحدة', 'ساعتين', 'ساعات', 'ساعة', 'ساعة');
    }

    public static function times(int $n): string
    {
        return self::of($n, 'مرّة واحدة', 'مرّتين', 'مرّات', 'مرّة', 'مرّة');
    }

    public static function minutes(int $n): string
    {
        return self::of($n, 'دقيقة واحدة', 'دقيقتين', 'دقائق', 'دقيقة', 'دقيقة');
    }

    /** صيغ الوحدة **صدراً في مدّةٍ مركّبة** — «ساعة و15 دقيقة» لا «ساعة واحدة و15 دقيقة». */
    private const MINUTE = ['دقيقة', 'دقيقتين', 'دقائق', 'دقيقة', 'دقيقة'];

    private const HOUR = ['ساعة', 'ساعتين', 'ساعات', 'ساعة', 'ساعة'];

    private const DAY = ['يوم', 'يومين', 'أيام', 'يوماً', 'يوم'];

    /**
     * **مدّةٌ بالدقائق بوحدتها الطبيعيّة** — «10 دقائق»، «ساعة واحدة»، «ساعة ونصف»، «ساعتين»،
     * «3 ساعات»، «يوم واحد»، «ساعة و15 دقيقة».
     *
     * صارت المهل إعداداتٍ بالدقائق (قرار المالك 2026-09-26) لتُضبط من ٥ أو ١٠ دقائق، لكنّ النصّ
     * الذي يقرؤه العميل أو الطاقم لا يقول «1440 دقيقة» ولا «90 دقيقة». فالقاعدة هنا وحدها، وكلُّ
     * جملةٍ مبنيّةٍ من مهلةٍ تمرّ بها. **ونظيرها في الواجهة `humanDuration`** (`resources/js/lib/
     * human-duration.ts`) يطابقها حرفاً — يحرسهما `HumanDurationTest` بجدولٍ واحد.
     *
     * القاعدة: أكبر وحدةٍ ذات معنى (دقائق < ساعة ≤ ساعات < يوم ≤ أيّام)، ثمّ بقيّتها بالوحدة التي
     * تليها، والنصف «ونصف» (٣٠ دقيقة بعد الساعات، ١٢ ساعة بعد الأيّام). ما دون الساعة بعد الأيّام
     * لا يُذكر — «يوم واحد» أوضح من «يوم و5 دقائق» لمهلةٍ تُقاس بالأيّام.
     */
    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);

        if ($minutes < 60) {
            return self::minutes($minutes);
        }
        if ($minutes < 1440) {
            return self::compound(intdiv($minutes, 60), $minutes % 60, 30, self::hours(...), self::HOUR, self::MINUTE);
        }

        return self::compound(intdiv($minutes, 1440), intdiv($minutes % 1440, 60), 12, self::days(...), self::DAY, self::HOUR);
    }

    /**
     * @param  callable(int): string  $alone  الوحدة وحدها («ساعة واحدة»)
     * @param  list<string>  $head  صيغ الوحدة صدراً
     * @param  list<string>  $rest  صيغ الوحدة التي تليها
     */
    private static function compound(int $whole, int $remainder, int $half, callable $alone, array $head, array $rest): string
    {
        if ($remainder === 0) {
            return $alone($whole);
        }

        $tail = $remainder === $half ? 'نصف' : self::of($remainder, ...$rest);

        return self::of($whole, ...$head).' و'.$tail;
    }

    /**
     * @param  string  $one  لفظ الواحد كاملاً («يوم واحد»)
     * @param  string  $two  لفظ المثنّى («يومين»)
     * @param  string  $few  الجمع بعد ٣–١٠ («أيام»)
     * @param  string  $many  المفرد المنصوب بعد ١١–٩٩ («يوماً»)
     * @param  string  $hundred  المفرد المجرور بعد المئة وأخواتها و«0» («يوم»)
     */
    /**
     * الرقم ومعدوده لأيّ اسم — عامٌّ كي لا تُلصق المعدودات بأرقامها في الجمل المبنيّة («يوجد 2 تذكرة»).
     * ونظيره في الواجهة `arabicCount` (`resources/js/lib/arabic-count.ts`).
     */
    public static function of(int $n, string $one, string $two, string $few, string $many, string $hundred): string
    {
        $tail = abs($n) % 100;

        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $tail >= 3 && $tail <= 10 => "{$n} {$few}",
            $tail >= 11 => "{$n} {$many}",
            default => "{$n} {$hundred}",
        };
    }
}
