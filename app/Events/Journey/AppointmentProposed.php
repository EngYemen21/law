<?php

namespace App\Events\Journey;

use App\Models\Appointment;
use App\Models\Consult;
use Illuminate\Foundation\Events\Dispatchable;

final class AppointmentProposed
{
    use Dispatchable;

    public function __construct(
        public readonly Consult $consult,
        public readonly Appointment $appointment,
        public readonly string $actorName,
    ) {}
}
