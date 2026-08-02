<?php

namespace App\Events;

use App\Models\Correspondence;
use App\Support\CorrFlow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة المخاطبة لحظياً (تقدّم رحلتها لدى المكتب والعميل).
 */
class CorrStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Correspondence $correspondence) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('corr.'.$this->correspondence->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->correspondence->status,
            'tone' => $this->correspondence->tone,
            'clientStage' => CorrFlow::clientStage((int) $this->correspondence->stage, (bool) $this->correspondence->briefed),
            'briefed' => (bool) $this->correspondence->briefed,
        ];
    }
}
