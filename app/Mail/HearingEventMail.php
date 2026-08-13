<?php

namespace App\Mail;

use App\Models\CaseHearing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * إشعار بريدي بحدث على جلسة قضية: إنشاء | إعادة جدولة | إلغاء — يُرسَل للعميل والمحامي.
 */
class HearingEventMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public CaseHearing $hearing,
        public string $event // created | rescheduled | cancelled
    ) {}

    /** @return array{0:string,1:string} [عنوان، تمهيد] */
    private function labels(): array
    {
        return [
            'created' => ['موعد جلسة جديدة', 'تم تحديد موعد جلسة جديدة في قضيتك'],
            'rescheduled' => ['إعادة جدولة جلسة', 'أُعيدت جدولة جلسة في قضيتك'],
            'cancelled' => ['إلغاء جلسة', 'أُلغيت جلسة في قضيتك'],
        ][$this->event] ?? ['تحديث جلسة', 'تحديث على جلسة قضيتك'];
    }

    public function envelope(): Envelope
    {
        [$subject] = $this->labels();

        return new Envelope(subject: $subject.' — '.($this->hearing->legalCase?->number ?? ''));
    }

    public function content(): Content
    {
        [$subject, $intro] = $this->labels();

        return new Content(
            view: 'emails.hearing-event',
            with: [
                'hearing' => $this->hearing,
                'event' => $this->event,
                'subject' => $subject,
                'intro' => $intro,
            ]
        );
    }
}
