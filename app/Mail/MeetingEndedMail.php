<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** إشعار بانتهاء اجتماع + ملخّص/محضر — قابل لإعادة الاستخدام لأيّ دور. يُرسَل عبر الطابور. */
class MeetingEndedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $title,
        public ?string $summary = null,
        public ?string $minutesUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'انتهى الاجتماع: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.meeting-ended', with: [
            'recipientName' => $this->recipientName,
            'title' => $this->title,
            'summary' => $this->summary,
            'minutesUrl' => $this->minutesUrl,
        ]);
    }
}
