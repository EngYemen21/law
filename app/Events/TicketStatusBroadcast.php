<?php

namespace App\Events;

use App\Models\Ticket;
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

    public function __construct(public Ticket $ticket)
    {
    }

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
        return ['status' => $this->ticket->status, 'tone' => $this->ticket->tone];
    }
}
