<?php

namespace App\Mail;

use App\Services\IcalendarService;
use App\Support\MeetingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** إشعار بموعد اجتماع — معطيات مفصولة عن النماذج مع مرفق iCalendar القياسي وترميز Google Schema JSON-LD. */
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
        $startsAt = MeetingTime::parse($this->when, '') ?: now();
        $joinUrl = $this->joinUrl ?: url('/meetings');
        $googleSchema = IcalendarService::googleSchemaJsonLd(
            reservationNumber: 'MEET-'.substr(md5($this->title.$this->when), 0, 8),
            recipientName: $this->recipientName,
            title: $this->title,
            description: "اجتماع رسمي بالمنصة — {$this->when}",
            startsAt: $startsAt,
            durationMinutes: 60,
            locationUrl: $joinUrl
        );

        return new Content(view: 'emails.meeting-scheduled', with: [
            'recipientName' => $this->recipientName,
            'title' => $this->title,
            'when' => $this->when,
            'joinUrl' => $this->joinUrl,
            'location' => $this->location,
            'note' => $this->note,
            'googleSchema' => $googleSchema,
        ]);
    }

    public function attachments(): array
    {
        $startsAt = MeetingTime::parse($this->when, '') ?: now();
        $ics = IcalendarService::generate(
            uid: 'MEET-'.md5($this->title.$this->when),
            title: $this->title,
            description: "اجتماع رسمي عبر المنصة.\nالموعد: {$this->when}\nرابط الدخول: ".($this->joinUrl ?: url('/meetings')),
            startsAt: $startsAt,
            durationMinutes: 60,
            locationUrl: $this->joinUrl ?: url('/meetings')
        );

        return [
            Attachment::fromData(fn () => $ics, 'invite.ics')
                ->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }
}
