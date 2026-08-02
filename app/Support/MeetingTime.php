<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * تحليل دفاعيّ لموعد الاجتماع من نصّي اليوم/الوقت الحرّين إلى datetime — لجدولة التذكير.
 * يعيد null عند تعذّر التحليل (تواريخ عربيّة حرّة مثل «الاثنين 29 يونيو») فلا يُبنى تذكير هشّ.
 */
class MeetingTime
{
    public static function parse(?string $day, ?string $time): ?Carbon
    {
        $day = trim((string) $day);
        $time = trim((string) $time);

        if ($day === '' || $day === '—') {
            return null;
        }

        // تطبيع ص/م → AM/PM لدعم صيغ الوقت العربيّة مع تاريخ رقميّ
        $time = str_replace(['ص', 'م'], [' AM', ' PM'], $time);

        try {
            $dt = Carbon::parse(trim($day.' '.$time));

            return $dt->isValid() ? $dt : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
