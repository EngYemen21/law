<?php

namespace App\Events;

use App\Models\ExecutionMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ رسالة طلب تنفيذ لحظياً للعميل وفريق العمل دون إعادة تحميل.
 */
class ExecMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ExecutionMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('exec.'.$this->message->execution_id)];
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
