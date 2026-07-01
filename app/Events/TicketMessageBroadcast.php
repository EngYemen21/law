<?php

namespace App\Events;

use App\Models\TicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ رسالة تذكرة لحظياً للطرفين (العميل + الموظف) دون إعادة تحميل.
 * الملاحظات الداخلية تُبثّ على قناة الموظفين فقط (لا يراها العميل).
 */
class TicketMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TicketMessage $message)
    {
    }

    public function broadcastOn(): array
    {
        $base = 'ticket.'.$this->message->ticket_id;
        // الملاحظة الداخلية → قناة الموظفين فقط
        $channel = $this->message->who === 'note' ? $base.'.staff' : $base;

        return [new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message->toMessage()];
    }
}
