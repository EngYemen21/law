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
 * سداد أتعاب القضية (بريد) — يُرسَل للعميل عند تأكيد سداد الأتعاب عبر ميسّر وتفعيل القضية.
 */
class CaseFeePaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام أتعاب قضيتك وتفعيلها — '.$this->case->number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.case-fee-paid',
            with: [
                'case' => $this->case,
            ]
        );
    }
}
