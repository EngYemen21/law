<?php

namespace App\Events;

use App\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ إشعار مستخدم لحظياً على قناته الخاصّة `notifications.{userId}`
 * — فتظهر نقطة الجرس/القائمة والتنبيه فوراً دون إعادة تحميل.
 */
class UserNotificationBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public UserNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notify';
    }

    public function broadcastWith(): array
    {
        return $this->notification->toData();
    }
}
