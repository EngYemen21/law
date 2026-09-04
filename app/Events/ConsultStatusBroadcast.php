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
            // **الملخّص المعتمَد فقط** — نظير `MeetingStatusBroadcast`. القناة
            // `consult.{id}` مخوَّلٌ عليها العميل، فبثُّ الملخّص بلا شرط كان يتجاوز
            // الحجب الخادميّ في `Consult::toClientCard`: يُحجب في الحمولة الأولى
            // ثم يصل عبر البثّ لحظة كتابته بالنموذج قبل أن يمرّ به إنسان.
            'summary' => $this->consult->summaryApproved() ? $this->consult->summary : null,
            'summaryPending' => $this->consult->summary !== null && ! $this->consult->summaryApproved(),
            'summaryApproved' => $this->consult->summaryApproved(),
            'summaryEdited' => $this->consult->summary_edited_at !== null,
            // مشتقّاتٌ كانت البطاقة تحملها والبثّ لا — فتبقى بائتةً حتى إعادة التحميل:
            // موعدٌ فات يبقى «قابلاً للبدء»، وجلسةٌ فائتة لا تُعلَن فائتة.
            'missed' => $this->consult->isMissed(),
            'startable' => $this->consult->isStartable(),
            'duration' => $this->consult->duration_label,
            'canJoin' => $this->consult->canJoin(),
            // دورة الحجز/الدفع — تُمكّن الواجهة من التقدّم لحظياً (فاتورة → دفع → موعد)
            'price' => $this->consult->price,
            'vat' => $this->consult->vat,
            'total' => $this->consult->total,
            'priced' => $this->consult->priced_at !== null,
            'paid' => $this->consult->paid_at !== null,
            'invoiceNo' => $this->consult->invoice?->number,
            // **الصيغة نفسها التي ترسلها البطاقة.** كان يُبثّ العمود الخام
            // `when_label` بينما `toCard()` يشتقّ `whenLabel()`؛ والشاشة تنسخ حمولة
            // البثّ فوق البطاقة، فيتبدّل عمود الموعد صيغةً عند أوّل بثّ.
            'when' => $this->consult->whenLabel(),
            'channel' => $this->consult->channel,
        ];
    }
}
