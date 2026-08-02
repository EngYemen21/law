<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** إشعار بموعد اجتماع — معطيات مفصولة عن النماذج (قابل لإعادة الاستخدام لأيّ دور). يُرسَل عبر الطابور. */
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
}
