<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Support\TicketTriage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TriageDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketDocument $doc
    ) {}

    public function handle(): void
    {
        TicketTriage::onDocumentAttached($this->ticket, $this->doc);
    }
}
