<?php

namespace App\Events\Journey;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketStatusCorrected
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $from,
        public readonly string $to,
        public readonly string $reason,
        public readonly string $actorName,
    ) {}
}
