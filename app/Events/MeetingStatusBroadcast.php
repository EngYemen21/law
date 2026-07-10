<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة الاجتماع لحظياً (جارٍ/منتهٍ/معتمد + الملخص والمحضر)
 * — يتحدّث تفصيل الاجتماع لدى المكتب وصفحة الاجتماعات لدى العميل فوراً.
 */
class MeetingStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Meeting $meeting) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('meeting.'.$this->meeting->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        $approved = $this->meeting->approve === 'معتمد';

        return [
            'status' => $this->meeting->status,
            'approve' => $this->meeting->approve,
            // المحضر/الملخص يصلان العميل فقط بعد اعتماد الإدارة
            'summary' => $approved ? $this->meeting->summary : null,
            'minutes' => $approved ? $this->meeting->minutes : null,
        ];
    }
}
