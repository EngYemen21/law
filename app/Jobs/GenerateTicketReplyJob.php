<?php

namespace App\Jobs;

use App\Events\TicketMessageBroadcast;
use App\Models\Ticket;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\TicketJourney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateTicketReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $body
    ) {}

    public function handle(LegalAiService $ai): void
    {
        $ticket = $this->ticket->fresh();
        if (! $ticket) {
            return;
        }

        // يتوقّف الردّ التلقائيّ لـ AI بمجرّد إحالة التذكرة للقسم القانوني أو تحويلها للتعامل البشري
        if (TicketJourney::indexOf($ticket->status) >= TicketJourney::indexOf('محالة للقسم القانوني')) {
            return;
        }

        $aiText = $ai->reply($ticket, $this->body)
            ?? 'تم استلام رسالتك، وسيوافيك المختص بالرد في أقرب وقت.';

        $aiMsg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => nl2br(e($aiText)),
            'time_label' => $this->clock(),
        ]);

        Live::push(new TicketMessageBroadcast($aiMsg));
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
