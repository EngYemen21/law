<?php

namespace App\Events;

use App\Domain\Journey\Enums\MeetingStatus;
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
        $approved = $this->meeting->isApproved();
        [, $liveStatus, $tone] = $this->meeting->liveState();

        return [
            'status' => $this->meeting->status,
            // الحالة الحيّة المشتقّة + زر الدخول — كان canJoin لقطة جامدة لا تتحدّث فيبقى الزر
            // معطّلاً بعد بدء الجلسة مبكراً أو مفعّلاً بعد انتهائها حتى إعادة تحميل يدوية
            'liveStatus' => $liveStatus,
            'tone' => $tone,
            'up' => $this->meeting->isUpcoming(),
            'canJoin' => $this->meeting->canJoin(),
            // أزرار البدء/الإنهاء/الإلغاء تتبع الحالة لحظيّاً — بحراس الانتقالات لا بقائمةٍ في الواجهة
            'actions' => $this->meeting->lifecycleActions(),
            'approve' => $this->meeting->approve,
            // مفتاح الحالة وعلَما الاعتماد — الصفحة تشرط أزرارها بها لا بالنصّ العربيّ (نظير `toFullCard`)
            'statusKey' => MeetingStatus::keyOf($liveStatus),
            'approved' => $approved,
            'canApprove' => $this->meeting->canApprove(),
            // المحضر/الملخص البشري المعتمَد فقط (لا يُبثّ ملخّص AI للعميل)
            'summary' => $approved ? $this->meeting->summary : null,
            'minutes' => $approved ? $this->meeting->minutes : null,
        ];
    }
}
