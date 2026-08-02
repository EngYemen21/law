<?php

namespace App\Support;

use App\Events\UserNotificationBroadcast;
use App\Models\UserNotification;

/**
 * مصدر موحّد لإنشاء إشعار مستخدم وبثّه لحظياً (Reverb) — يستبدل UserNotification::create المباشر
 * كي تصبح كل الإشعارات لحظية عبر قناة المستخدم الخاصّة.
 */
class Notify
{
    public static function send(int $userId, string $icon, string $tone, string $body, string $timeLabel = 'الآن'): UserNotification
    {
        $notification = UserNotification::create([
            'user_id' => $userId,
            'icon' => $icon,
            'tone' => $tone,
            'body' => $body,
            'time_label' => $timeLabel,
            'is_read' => false,
        ]);

        Live::push(new UserNotificationBroadcast($notification));

        return $notification;
    }
}
