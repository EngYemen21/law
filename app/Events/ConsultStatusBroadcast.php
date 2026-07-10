<?php

namespace App\Events;

use App\Models\Consult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ تغيّر حالة جلسة الاستشارة لحظياً (بدء/إنهاء الجلسة والملخص)
 * — يظهر «جارية الآن» فوراً في «استشاراتي» لدى العميل.
 */
class ConsultStatusBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Consult $consult) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('consult.'.$this->consult->id)];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return [
            'session' => $this->consult->session,
            'status' => $this->consult->status,
            'summary' => $this->consult->summary,
            'duration' => $this->consult->duration_label,
        ];
    }
}
