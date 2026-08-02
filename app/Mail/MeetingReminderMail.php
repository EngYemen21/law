<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** تذكير باجتماع قادم — قابل لإعادة الاستخدام لأيّ دور. يُرسَل عبر الطابور. */
class MeetingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $title,
        public string $when,
        public ?string $joinUrl = null,
        public ?string $remaining = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير باجتماع: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.meeting-reminder', with: [
            'recipientName' => $this->recipientName,
            'title' => $this->title,
            'when' => $this->when,
            'joinUrl' => $this->joinUrl,
            'remaining' => $this->remaining,
        ]);
    }
}
