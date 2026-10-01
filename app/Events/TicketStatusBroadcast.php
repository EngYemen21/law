<?php

namespace App\Events;

use App\Domain\Journey\Enums\TicketStatus;
use App\Models\Ticket;
use App\Support\TicketJourney;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة التذكرة لحظياً فيتقدّم مسار المعالجة لدى الطرفين دون إعادة تحميل.
 */
class TicketStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ticket.'.$this->ticket->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->ticket->status,
            'tone' => $this->ticket->tone,
            // القناة مشتركة بين العميل والطاقم: `status` داخليّ يبقى، وتسمية العميل بجواره يقرؤها العميل
            'clientStatus' => TicketStatus::labelForClient($this->ticket->status),
            // «انتهت؟» حكمُ الخادم لا مقارنةٌ بنصّ — الشاشة تقرؤه حين تتقدّم الحالة وهي مفتوحة
            'isTerminal' => $this->ticket->isTerminal(),
            // مرحلة «مسار المعالجة» — حكم الخادم (`TicketJourney::indexOf`) لا اشتقاقٌ من نصّ الحالة في الواجهة
            'step' => TicketJourney::indexOf($this->ticket->status),
            // بطاقات المآل لمحادثة العميل — تظهر لحظة اعتماد القرار بلا إعادة تحميل
            'outcomeCards' => $this->ticket->outcomeCards($this->ticket->execution()->exists()),
        ];
    }
}
