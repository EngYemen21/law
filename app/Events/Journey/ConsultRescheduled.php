<?php

namespace App\Events\Journey;

use App\Models\Consult;
use Illuminate\Foundation\Events\Dispatchable;

final class ConsultRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Consult $consult,
        public readonly string $oldWhen,
        public readonly ?string $oldMeetId,
        public readonly string $actorName,
        public readonly bool $ticketReverted,
    ) {}
}
