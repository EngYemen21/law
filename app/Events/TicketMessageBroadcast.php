<?php

namespace App\Events;

use App\Models\TicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ رسالة تذكرة لحظياً للطرفين (العميل + الموظف) دون إعادة تحميل.
 * الملاحظات الداخلية تُبثّ على قناة الموظفين فقط (لا يراها العميل).
 */
class TicketMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TicketMessage $message) {}

    public function broadcastOn(): array
    {
        // الملاحظة الداخلية → قناة الموظفين فقط (`RecordsSender::broadcastChannelName`)
        return [new PrivateChannel($this->message->broadcastChannelName('ticket.'.$this->message->ticket_id))];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    /**
     * **الحمولة بتسميات العميل** (`toMessage(forClient: true)` ← `ChatSenderLabel`): القناة يستمع لها
     * العميل، فالاسم الخام كان يصله لحظةَ الإرسال. والتسمية نفسها التي تعرضها صفحاته عند التحميل.
     *
     * والطاقم يشارك القناة نفسها، فيرى التسمية في الرسالة اللحظيّة والاسم الحقيقيّ عند التحميل —
     * وهو ثمنٌ مقبول: البديل قناةٌ ثانية بحمولةٍ ثانية لكلّ رسالة.
     *
     * **ولا عنوان IP في البثّ**: الحمولة تُبنى داخل طلب المُرسِل — موظّفاً كان فيراه المشاهدُ من
     * الطاقم — ثمّ تصل قناةً يستمع إليها العميل. والطاقم يراه عند التحميل.
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message->toMessage(forClient: true)];
    }
}
