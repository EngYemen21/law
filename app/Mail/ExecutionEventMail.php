<?php

namespace App\Mail;

use App\Models\Execution;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * إشعار بريدي بحدث على طلب/ملف التنفيذ — بجانب إشعار النظام (`Notify::send`) لا بدلاً عنه.
 *
 * **الجمهور جزءٌ من الرسالة** (قرار المالك 2026-09-12): كانت كلّ رسائل التنفيذ للعميل وحده،
 * فلا يعلم المكتب بطلبٍ جديد ولا بأتعابٍ تنتظر اعتماده إلا بدخوله. ولكلّ فئةٍ رابطُ لوحتها،
 * فلا يُرسَل للموظّف رابطُ الإدارة الذي يردّه حارس الصلاحية.
 */
class ExecutionEventMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Execution $execution,
        public string $event,
        /** client | lawyer | admin | employee */
        public string $audience = 'client',
        /**
         * الفاتورة المعنيّة — للتذكير وحده. صار لملفّ التنفيذ فواتيرُ عدّة (ثلاث دفعات،
         * وفاتورة أتعابٍ مع كلّ تحصيل)، فبريدٌ مربوطٌ بالطلب وحده يُنتج رسائل متطابقة
         * حرفاً بحرف بلا رقمٍ ولا مبلغ — لا يعرف قارئها أيّ فاتورةٍ يسدّد.
         */
        public ?Invoice $invoice = null,
    ) {}

    /** @return array{0:string,1:string} [عنوان، تمهيد] */
    private function labels(): array
    {
        $no = $this->execution->number;

        return [
            // ── العميل ──
            'feeSet' => ['أتعاب طلب التنفيذ', 'حُدِّدت أتعاب طلب التنفيذ الخاص بك'],
            'feeApproved' => ['عرض خدمة التنفيذ', 'اعتمدت الإدارة أتعاب طلب التنفيذ وأُرسل العرض'],
            'paid' => ['فُتح ملف التنفيذ', 'سُدِّدت الأتعاب وفُتح ملف التنفيذ'],
            // الملفّ يُفتح بثلاثة أسباب، ولكلٍّ خبرُه: نسبةٌ بلا مقدَّم، ودفعةٌ أولى من ثلاث، وسدادٌ كامل
            'fileOpened' => ['فُتح ملف التنفيذ', 'قُبل العرض وفُتح ملف التنفيذ — لا مبلغ مقدَّم'],
            'firstInstallmentPaid' => ['سُدِّدت الدفعة الأولى وفُتح ملف التنفيذ', 'سُدِّدت الدفعة الأولى من أتعاب التنفيذ وفُتح الملف'],
            'closed' => ['إغلاق ملف التنفيذ', 'أُغلق ملف التنفيذ'],
            'paymentReminder' => ['تذكير بسداد أتعاب التنفيذ', 'فاتورة أتعاب طلب التنفيذ لا تزال بانتظار السداد'],
            'rejected' => ['تعذّر قبول طلب التنفيذ', 'بعد دراسة الطلب ومستنداته تعذّر قبوله'],
            'collection' => ['تحصيل على ملف التنفيذ', 'حُصّل مبلغ على ملفّ تنفيذك'],
            // نموذج «نسبة من المحصّل»: فاتورةٌ مع كلّ تحصيل، لا مبلغ مقدَّم
            'feeInvoice' => ['فاتورة أتعاب على ملف التنفيذ', 'صدرت فاتورة أتعاب مقابل مبلغ حُصّل على ملفّك'],
            'feePaidInFull' => ['اكتمل سداد أتعاب التنفيذ', 'سُدِّدت الدفعة الأخيرة واكتملت أتعاب التنفيذ'],
            // ── المكتب ──
            'feeAwaitingApproval' => ['أتعاب تنفيذ بانتظار اعتماد الإدارة', "حدّد المستشار أتعاب التنفيذ للطلب {$no} — بانتظار اعتماد الإدارة"],
            'offerInquiry' => ['استفسار العميل عن عرض التنفيذ', "استفسر العميل عن عرض خدمة التنفيذ للطلب {$no}"],
            'offerRejected' => ['رفض العميل عرض التنفيذ', "رفض العميل عرض خدمة التنفيذ للطلب {$no} — يلزم إعادة التسعير"],
            'assigned' => ['أُسند إليك ملف تنفيذ', "أُسند إليك ملفّ التنفيذ {$no}"],
            'payDueOverdue' => ['انقضت مهلة الوفاء', "انقضت مهلة الوفاء على ملفّ التنفيذ {$no} ولم تُسجَّل إجراءات عدم الوفاء"],
        ][$this->event] ?? ['تحديث على طلب التنفيذ', 'تحديث على طلب التنفيذ'];
    }

    /** لوحة المستلِم — رابطٌ يفتح له فعلاً لا يردّه حارس الدور. */
    public function panelUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.match ($this->audience) {
            'admin' => '/admin/execs',
            'employee' => '/employee/execs',
            'lawyer' => '/lawyer/execs',
            default => '/execs',
        };
    }

    public function envelope(): Envelope
    {
        [$subject] = $this->labels();

        return new Envelope(subject: $subject.' — '.$this->execution->number);
    }

    public function content(): Content
    {
        [$subject, $intro] = $this->labels();

        return new Content(
            view: 'emails.execution-event',
            with: [
                'execution' => $this->execution,
                'event' => $this->event,
                'audience' => $this->audience,
                'subject' => $subject,
                'intro' => $intro,
                'invoice' => $this->invoice,
                'panelUrl' => $this->panelUrl(),
            ]
        );
    }
}
