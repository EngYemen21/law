<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * **تصفية «من يوم … إلى يوم» بمدىً يقبل الفهرس** (المجموعة ج).
 *
 * `whereDate('created_at', …)` يلفّ العمود بدالّة فلا يستعمل فهرسه. هنا يصير اليوم مدىً
 * `[بداية اليوم، بداية الغد)` على العمود نفسه — والنتيجة هي نفسها. وما ليس «YYYY-MM-DD»
 * يمرّ بـ`whereDate` كما كان، فلا يتغيّر سلوك مدخلٍ غير متوقَّع.
 */
final class DayRange
{
    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function apply(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if ($from) {
            ($day = self::day($from)) !== null
                ? $query->where($column, '>=', $day)
                : $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            ($day = self::day($to)) !== null
                ? $query->where($column, '<', $day->addDay())
                : $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    /** يومٌ واحد كاملاً (اليوم الحاليّ في عدّاد «اليوم»). */
    public static function on(Builder $query, string $column, CarbonImmutable $day): Builder
    {
        return $query->where($column, '>=', $day->startOfDay())->where($column, '<', $day->startOfDay()->addDay());
    }

    private static function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }
}
