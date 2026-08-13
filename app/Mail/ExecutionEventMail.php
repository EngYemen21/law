<?php

namespace App\Mail;

use App\Models\Execution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * إشعار بريدي بحدث على طلب/ملف التنفيذ: تحديد الأتعاب | اعتماد الأتعاب | السداد | الإغلاق —
 * يُرسَل للعميل بجانب إشعار النظام (Notify::send).
 */
class ExecutionEventMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Execution $execution,
        public string $event // feeSet | feeApproved | paid | closed
    ) {}

    /** @return array{0:string,1:string} [عنوان، تمهيد] */
    private function labels(): array
    {
        return [
            'feeSet' => ['أتعاب طلب التنفيذ', 'حُدِّدت أتعاب طلب التنفيذ الخاص بك'],
            'feeApproved' => ['عرض خدمة التنفيذ', 'اعتمدت الإدارة أتعاب طلب التنفيذ وأُرسل العرض'],
            'paid' => ['فُتح ملف التنفيذ', 'سُدِّدت الأتعاب وفُتح ملف التنفيذ'],
            'closed' => ['إغلاق ملف التنفيذ', 'أُغلق ملف التنفيذ'],
            'paymentReminder' => ['تذكير بسداد أتعاب التنفيذ', 'فاتورة أتعاب طلب التنفيذ لا تزال بانتظار السداد'],
        ][$this->event] ?? ['تحديث على طلب التنفيذ', 'تحديث على طلب التنفيذ الخاص بك'];
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
                'subject' => $subject,
                'intro' => $intro,
            ]
        );
    }
}
