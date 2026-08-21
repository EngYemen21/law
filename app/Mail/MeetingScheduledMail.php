<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** إشعار بموعد اجتماع — معطيات مفصولة عن النماذج، مع ترميز Google Schema JSON-LD. */
class MeetingScheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $title,
        public string $when,
        public ?string $joinUrl = null,
        public ?string $location = null,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'موعد اجتماع: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.meeting-scheduled', with: [
            'recipientName' => $this->recipientName,
            'title' => $this->title,
            'when' => $this->when,
            'joinUrl' => $this->joinUrl,
            'location' => $this->location,
            'note' => $this->note,
        ]);
    }

    /**
     * بلا مرفقات عمداً (fe55756): مرفق ‎.ics كان يُحجب أو يُعرض ملفّاً غامضاً لدى عملاء
     * بريد كثيرين. البديل الحيّ هو زرّ «أضِف إلى تقويمك» وتغذية Webcal في شاشة التقويم
     * (IcalendarService::googleUrl / feedForUser). لا تُعِدها إلا بقرار صريح.
     */
    public function attachments(): array
    {
        return [];
    }
}
