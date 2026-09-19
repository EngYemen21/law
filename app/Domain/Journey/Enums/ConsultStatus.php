<?php

namespace App\Domain\Journey\Enums;

/**
 * **حالات الاستشارة — الكتالوج الواحد.** يطابق `Consult::STATUSES` ويضيف «بانتظار اعتماد
 * الموعد» (قرار المالك 2026-09-14: حجزُ الموظّف لا يصل العميل قبل اعتماد الإدارة).
 */
enum ConsultStatus: string
{
    // دورة الحجز
    case AwaitingPricing = 'بانتظار التسعير';
    case AwaitingPayment = 'بانتظار السداد';
    case AwaitingSchedule = 'بانتظار تحديد الموعد';
    case AwaitingAppointmentApproval = 'بانتظار اعتماد الموعد';
    // رحلة المعالجة
    case New = 'جديدة';
    case AwaitingData = 'بانتظار استكمال البيانات';
    case AwaitingEmployeeApproval = 'بانتظار اعتماد الموظف';
    case ReadyForLawyer = 'جاهزة للمحامي';
    case ReferredToLawyer = 'محالة للمحامي';
    case InSession = 'قيد الاستشارة';
    // النهايات
    case Ended = 'منتهية';
    case NoShow = 'لم يحضر';
    case Cancelled = 'ملغاة';

    /** دورة الحجز: لم يُنشر لها موعدٌ بعد. */
    public function isPreSession(): bool
    {
        return in_array($this, [
            self::AwaitingPricing, self::AwaitingPayment,
            self::AwaitingSchedule, self::AwaitingAppointmentApproval,
        ], true);
    }

    /** لا فعلَ يبعثها. («لم يحضر» حالةُ تعافٍ لا نهاية — انظر `Consult::CLOSED_STATUSES`.) */
    public function isClosed(): bool
    {
        return $this === self::Ended || $this === self::Cancelled;
    }

    public function clientLabel(): string
    {
        return $this === self::AwaitingAppointmentApproval ? self::AwaitingSchedule->value : $this->value;
    }

    /** @return list<string> */
    public static function preSession(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->isPreSession())
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
