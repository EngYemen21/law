<?php

namespace App\Support;

use App\Models\Consult;

/**
 * **نتيجة حجز موعد الاستشارة** (`ConsultAppointments::propose/publish`): الاستشارة بعد الحجز، وهل قُبل
 * فوق انشغالٍ آخر للمحامي (خيار الحجز المتداخل — من الانتقال نفسه الذي قرّره) أو خارج الدوام.
 */
final class ScheduledConsult
{
    public function __construct(
        public readonly Consult $consult,
        public readonly bool $overlap,
        public readonly bool $offHours,
    ) {}

    /** تُلحق برسالة النجاح: تنبيه التداخل و/أو خارج الدوام حين قُبل الموعد بهما، وإلا لا شيء. */
    public function notice(): string
    {
        return ($this->overlap ? ' '.ConsultBooking::OVERLAP_NOTICE : '')
            .($this->offHours ? ' '.ConsultBooking::OFF_HOURS_NOTICE : '');
    }
}
