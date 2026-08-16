<?php

namespace App\Mail;

use App\Models\MeetRequest;
use App\Services\IcalendarService;
use App\Support\MeetingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * بريد دعوة اجتماع للعميل — مع ملف iCalendar الرسمي وترميز Google Schema JSON-LD.
 */
class MeetInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MeetRequest $req) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'دعوة اجتماع — '.$this->req->ref);
    }

    public function content(): Content
    {
        $startsAt = MeetingTime::parse($this->req->day, $this->req->time) ?: now();
        $link = url('/meetreqs');
        $googleSchema = IcalendarService::googleSchemaJsonLd(
            reservationNumber: $this->req->ref,
            recipientName: $this->req->client_name ?: 'العميل',
            title: 'دعوة اجتماع: '.$this->req->service,
            description: "دعوة اجتماع رسمي — {$this->req->day} · {$this->req->time}",
            startsAt: $startsAt,
            durationMinutes: $this->req->duration_min ?: 60,
            locationUrl: $link
        );

        return new Content(
            view: 'emails.meet-invite',
            with: [
                'req' => $this->req,
                'googleSchema' => $googleSchema,
            ]
        );
    }

    public function attachments(): array
    {
        $startsAt = MeetingTime::parse($this->req->day, $this->req->time) ?: now();
        $link = url('/meetreqs');
        $ics = IcalendarService::generate(
            uid: 'MEETREQ-'.$this->req->id,
            title: 'دعوة اجتماع: '.$this->req->service.' ('.$this->req->ref.')',
            description: "دعوة اجتماع رسمي من النظام الإداري لمكاتب المحاماة.\nالموعد: {$this->req->day} · {$this->req->time}\nرابط التأكيد والحضور: {$link}",
            startsAt: $startsAt,
            durationMinutes: $this->req->duration_min ?: 60,
            locationUrl: $link
        );

        return [
            Attachment::fromData(fn () => $ics, 'invite.ics')
                ->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }
}
