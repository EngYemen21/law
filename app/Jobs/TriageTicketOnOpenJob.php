<?php

namespace App\Jobs;

use App\Events\TicketMessageBroadcast;
use App\Models\Ticket;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\TicketTriage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TriageTicketOnOpenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $details,
        public string $type
    ) {}

    public function handle(LegalAiService $ai): void
    {
        if (TicketTriage::enabled()) {
            TicketTriage::onOpened($this->ticket, $this->details);

            return;
        }

        $opening = $ai->reply($this->ticket, $this->details)
            ?? 'مرحباً بك، تم استلام طلبك بخصوص «'.$this->type.'» وإحالته إلى القسم المختص. سنتابع معك خطوة بخطوة، ويمكنك الكتابة هنا في أي وقت.';

        $m2 = $this->ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => nl2br(e($opening)),
            'time_label' => $this->clock(),
        ]);

        Live::push(new TicketMessageBroadcast($m2));
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
