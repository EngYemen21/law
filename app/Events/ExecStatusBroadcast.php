<?php

namespace App\Events;

use App\Models\Execution;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة طلب التنفيذ لحظياً (يتقدّم مسار دورة الحياة لدى الجميع).
 */
class ExecStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Execution $execution) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('exec.'.$this->execution->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->execution->status,
            'tone' => $this->execution->tone,
            'next' => $this->execution->last_action,
        ];
    }
}
