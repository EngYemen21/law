<?php

namespace App\Mail;

use App\Models\Consult;
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
 * تأكيد فوري بحجز الاستشارة (بريد) — يُرسَل عند إتمام الحجز مع مرفق iCalendar المباشر وترميز Google Schema JSON-LD.
 */
class ConsultBooked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public bool $forLawyer = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->forLawyer
            ? 'موعد استشارة جديد مُسند إليك — '.$this->consult->ref
            : 'تأكيد حجز استشارتك — '.$this->consult->ref;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $startsAt = $this->consult->starts_at ?: (MeetingTime::parse($this->consult->day ?? '', $this->consult->time ?? '') ?: now());
        $link = $this->consult->joinLink();
        $recipientName = $this->forLawyer ? $this->consult->lawyer : ($this->consult->user?->name ?? 'العميل');

        $googleSchema = IcalendarService::googleSchemaJsonLd(
            reservationNumber: $this->consult->ref,
            recipientName: $recipientName,
            title: 'استشارة: '.$this->consult->subject,
            description: "استشارة قانونية ({$this->consult->channel}) — المستشار: {$this->consult->lawyer}",
            startsAt: $startsAt,
            durationMinutes: $this->consult->duration_minutes ?: 45,
            locationUrl: $link
        );

        return new Content(
            view: 'emails.consult-booked',
            with: [
                'consult' => $this->consult,
                'forLawyer' => $this->forLawyer,
                'googleSchema' => $googleSchema,
            ]
        );
    }

    public function attachments(): array
    {
        $startsAt = $this->consult->starts_at ?: (MeetingTime::parse($this->consult->day ?? '', $this->consult->time ?? '') ?: now());
        $link = $this->consult->joinLink();
        $ics = IcalendarService::generate(
            uid: 'CONSULT-'.$this->consult->id,
            title: 'استشارة: '.$this->consult->subject.' ('.$this->consult->ref.')',
            description: "استشارة قانونية ({$this->consult->channel})\nالمستشار: {$this->consult->lawyer}\nرابط الجلسة: {$link}",
            startsAt: $startsAt,
            durationMinutes: $this->consult->duration_minutes ?: 45,
            locationUrl: $this->consult->channel === 'حضورية' ? ($this->consult->branch ?: 'مكتب المحاماة') : $link
        );

        return [
            Attachment::fromData(fn () => $ics, 'invite.ics')
                ->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }
}
