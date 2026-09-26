<?php

namespace App\Events;

use App\Models\Consult;
use App\Models\Meeting;
use App\Support\RoomDetails;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * **حالُ غرفة الجلسة تغيّر — بثٌّ لحظيّ لصفحة الغرفة** (`room.state`).
 *
 * يُطلق من كلّ ما يغيّر الغرفة: البدء والإنهاء (انتقالات المحرّك بعد الالتزام)، وشبكة النسيان،
 * وأحداث Zoom (دخول/خروج مشارك، بدء/إيقاف التسجيل). والحمولة `RoomDetails::state` نفسها التي
 * حُمّلت بها الصفحة — مفاتيح واحدة لا تتباعد.
 *
 * **نسختان لا واحدة:** قناة الغرفة المشتركة `room.{kind}.{id}` (العميل والطاقم) بلا `recording`
 * — العميل لا يُخبَر بالتسجيل (قرار المالك)؛ وقناة الطاقم `room.{kind}.{id}.staff` بها. حدثٌ
 * واحد بحمولةٍ واحدة لقناتين كان سيحمل التسجيل إلى العميل. فاستعمل `both()` دائماً.
 */
class RoomStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Consult|Meeting $session,
        public bool $staff = false,
    ) {}

    /** @return list<self> نسخة القناة المشتركة ونسخة الطاقم */
    public static function both(Consult|Meeting $session): array
    {
        return [new self($session, false), new self($session, true)];
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->staff
            ? RoomDetails::staffChannel($this->session)
            : RoomDetails::channel($this->session))];
    }

    public function broadcastAs(): string
    {
        return 'room.state';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return RoomDetails::state($this->session, $this->staff);
    }
}
