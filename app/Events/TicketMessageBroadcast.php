<?php

namespace App\Events;

use App\Models\TicketMessage;
use App\Support\LawyerName;
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
        $base = 'ticket.'.$this->message->ticket_id;
        // الملاحظة الداخلية → قناة الموظفين فقط
        $channel = $this->message->who === 'note' ? $base.'.staff' : $base;

        return [new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    /**
     * **اسم المحامي مقنَّعٌ في البثّ.** القناة يستمع لها العميل، فالحمولة الخام كانت توصله الاسم
     * كاملاً لحظةَ الإرسال — ثمّ يراه مختصراً عند أوّل تحميل (`LawyerName::inMessages` عند
     * المتحكّم). قرار المالك 2026-09-11: العميل يرى «محمد. ب».
     *
     * والطاقم يشارك القناة نفسها، فيرى الاسم مختصراً في الرسالة اللحظيّة وكاملاً عند التحميل —
     * وهو ثمنٌ مقبول: البديل قناةٌ ثانية بحمولةٍ ثانية لكلّ رسالة.
     *
     * **ولا عنوان IP في البثّ** (`forClient: true`): الحمولة تُبنى داخل طلب المُرسِل — موظّفاً
     * كان فيراه المشاهدُ من الطاقم — ثمّ تصل قناةً يستمع إليها العميل. والطاقم يراه عند التحميل.
     */
    public function broadcastWith(): array
    {
        return ['message' => LawyerName::inMessages([$this->message->toMessage(forClient: true)])[0]];
    }
}
