<?php

namespace App\Events\Journey;

use App\Models\Consult;
use App\Models\TicketMessage;
use Illuminate\Foundation\Events\Dispatchable;

final class AppointmentPublished
{
    use Dispatchable;

    /** @param  list<string>  $changes */
    public function __construct(
        public readonly Consult $consult,
        public readonly ?int $actorId,
        public readonly ?int $proposerId,
        public readonly array $changes,
        public readonly ?TicketMessage $card,
        public readonly bool $ticketMoved,
    ) {}
}
