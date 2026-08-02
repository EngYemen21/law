<?php

namespace App\Events;

use App\Models\LegalCase;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة القضية لحظياً (يتقدّم مسار دورة الحياة لدى الجميع).
 */
class CaseStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LegalCase $case) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('case.'.$this->case->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->case->status,
            'tone' => $this->case->tone,
        ];
    }
}
