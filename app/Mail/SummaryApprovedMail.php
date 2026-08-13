<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * اعتماد المستشار لملخّص الملف (بريد) — يُرسَل للعميل عند اعتماد المستشار الملخّص
 * وإصدار الرأي القانونيّ المبدئيّ، ويدعوه لحجز استشارة لإتمام الرأي.
 */
class SummaryApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public ?string $opinion = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'اعتُمد ملخّص ملفك وصدر الرأي القانونيّ — '.$this->ticket->number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.summary-approved',
            with: [
                'ticket' => $this->ticket,
                'opinion' => $this->opinion,
            ]
        );
    }
}
