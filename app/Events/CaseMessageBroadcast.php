<?php

namespace App\Events;

use App\Models\CaseMessage;
use App\Support\LawyerName;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يبثّ رسالة قضية لحظياً للعميل وفريق العمل دون إعادة تحميل.
 */
class CaseMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CaseMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('case.'.$this->message->case_id)];
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
     */
    public function broadcastWith(): array
    {
        return ['message' => LawyerName::inMessages([$this->message->toMessage()])[0]];
    }
}
