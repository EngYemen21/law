<?php

namespace App\Mail;

use App\Models\LegalCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * تحويل التذكرة إلى قضية (بريد) — يُرسَل للعميل عند تحويل استشارته المكتملة إلى قضية قانونية.
 */
class CaseConvertedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case,
        public string $ticketNumber
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم تحويل تذكرتك إلى قضية قانونية — '.$this->case->number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.case-converted',
            with: [
                'case' => $this->case,
                'ticketNumber' => $this->ticketNumber,
            ]
        );
    }
}
