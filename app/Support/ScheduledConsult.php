<?php

namespace App\Support;

use App\Models\Consult;

/**
 * **نتيجة حجز موعد الاستشارة** (`ConsultAppointments::propose/publish`): الاستشارة بعد الحجز، وهل قُبل
 * فوق انشغالٍ آخر للمحامي (خيار الحجز المتداخل) — من الانتقال نفسه الذي قرّره، لا من قراءةٍ لاحقة.
 */
final class ScheduledConsult
{
    public function __construct(
        public readonly Consult $consult,
        public readonly bool $overlap,
    ) {}

    /** تُلحق برسالة النجاح: التنبيه حين قُبل الموعد فوق انشغالٍ آخر، وإلا لا شيء. */
    public function notice(): string
    {
        return $this->overlap ? ' '.ConsultBooking::OVERLAP_NOTICE : '';
    }
}
