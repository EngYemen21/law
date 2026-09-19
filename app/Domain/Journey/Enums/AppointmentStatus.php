<?php

namespace App\Domain\Journey\Enums;

/** حالة الموعد — «بانتظار الاعتماد» لا يراه العميل ولا تُطلق له تذكيرات. */
enum AppointmentStatus: string
{
    case PendingApproval = 'بانتظار الاعتماد';
    case Confirmed = 'مؤكد';
    case Attended = 'تم الحضور';
    case NoShow = 'لم يحضر';
    case Cancelled = 'ملغي';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
