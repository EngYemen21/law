<?php

namespace App\Mail;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * إشعار بريدي بحدث على اجتماع: إعادة جدولة | إلغاء — يُرسَل للعميل والمحامي.
 */
class MeetingEventMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Meeting $meeting,
        public string $event // rescheduled | cancelled
    ) {}

    /** @return array{0:string,1:string} [عنوان، تمهيد] */
    private function labels(): array
    {
        return [
            'rescheduled' => ['إعادة جدولة اجتماع', 'أُعيدت جدولة اجتماعك'],
            'cancelled' => ['إلغاء اجتماع', 'أُلغي اجتماعك'],
        ][$this->event] ?? ['تحديث اجتماع', 'تحديث على اجتماعك'];
    }

    public function envelope(): Envelope
    {
        [$subject] = $this->labels();

        return new Envelope(subject: $subject.' — '.$this->meeting->ref);
    }

    public function content(): Content
    {
        [$subject, $intro] = $this->labels();

        return new Content(
            view: 'emails.meeting-event',
            with: [
                'meeting' => $this->meeting,
                'event' => $this->event,
                'subject' => $subject,
                'intro' => $intro,
            ]
        );
    }
}
