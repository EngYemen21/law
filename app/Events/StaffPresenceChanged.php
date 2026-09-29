<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * **تغيّرت حالة أحد الطاقم: دخل جلسة Zoom أو خرج منها** (`RoomPresence`) — يُبثّ على قناة الطاقم
 * `staff.presence` فتُحدِّث كلّ صفحةٍ مفتوحة خاصّيّتها `inSession` بلا تنقّل (`AppLayout`).
 * الحمولة الخريطة كاملةً [معرّف ⇒ رقم الجلسة] — صغيرةٌ، وتُغني عن طلبٍ ثانٍ.
 */
class StaffPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<int,string>  $inSession */
    public function __construct(public array $inSession) {}

    public const CHANNEL = 'staff.presence';

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'presence';
    }

    public function broadcastWith(): array
    {
        return ['inSession' => (object) $this->inSession];
    }
}
