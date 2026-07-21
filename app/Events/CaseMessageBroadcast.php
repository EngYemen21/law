<?php

namespace App\Events;

use App\Models\CaseMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ رسالة قضية لحظياً للعميل وفريق العمل دون إعادة تحميل.
 */
class CaseMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CaseMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('case.'.$this->message->case_id)];
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
