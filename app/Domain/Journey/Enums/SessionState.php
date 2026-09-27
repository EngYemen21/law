<?php

namespace App\Domain\Journey\Enums;

/** حالة جلسة الاستشارة — يطابق `Consult::SESSIONS`. */
enum SessionState: string
{
    case Waiting = 'بانتظار الجلسة';
    case Live = 'جلسة جارية';
    case Ended = 'منتهية';
    case NotHeld = 'لم تُعقد';

    /**
     * **انعقدت فعلاً؟** «لم تُعقد» نهايةٌ لكنها ليست انعقاداً — وكان `SESSION_ENDED` يجمعهما
     * فيُكتب لعميلٍ لم يحضر «انعقدت الجلسة» (ع٢١).
     */
    public function wasHeld(): bool
    {
        return $this === self::Ended;
    }

    /**
     * **لون شارة الجلسة — المصدر الواحد** (كان `sessTone` في الواجهة): الجارية كهرمانيّة، والمنعقدة
     * خضراء، و«لم تُعقد» حمراء (فوتٌ لا نجاح)، والمنتظرة محايدة.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Waiting => 'b-grey',
            self::Live => 'b-amber',
            self::Ended => 'b-green',
            self::NotHeld => 'b-red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
