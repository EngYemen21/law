<?php

namespace App\Jobs;

use App\Events\TicketMessageBroadcast;
use App\Models\Ticket;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketJourney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateTicketReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'chat.reply';

    /**
     * الطابور يُحسم عند الإنشاء. `resolve` تُعيد `null` ما لم يُفعَّل
     * AI_SEPARATE_QUEUES، فيبقى السلوك على الطابور الافتراضيّ كما هو —
     * تفعيلٌ قبل تحديث أمر العامل يوقف معالجة الذكاء صامتةً.
     */
    private function routeToAiQueue(): void
    {
        $this->onQueue(AiQueue::resolve(self::PROMPT_ID));
    }

    public function __construct(
        public Ticket $ticket,
        public string $body
    ) {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        $ticket = $this->ticket->fresh();
        if (! $ticket) {
            return;
        }

        // يتوقّف الردّ التلقائيّ لـ AI بمجرّد إحالة التذكرة للقسم القانوني أو تحويلها للتعامل البشري —
        // ويُسلَّم عصا المتابعة للمستشار المسند بإشعار، فلا تضيع رسالة العميل بصمت إن لم تكن شاشته مفتوحة
        if (TicketJourney::indexOf($ticket->status) >= TicketJourney::indexOf('محالة للقسم القانوني')) {
            if ($ticket->assigned_lawyer_id) {
                Notify::send($ticket->assigned_lawyer_id, 'folder', 't-blue', "رسالة جديدة من العميل على التذكرة {$ticket->number} — الردّ الآلي متوقف بعد الإحالة، يُرجى المتابعة.");
            }

            return;
        }

        $replyRun = $ai->replyResult($ticket, $this->body);
        AiRunLogger::log('chat.reply', $replyRun['source'], $replyRun['meta'], $ticket, (string) $ticket->number);

        $aiText = $replyRun['text']
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
