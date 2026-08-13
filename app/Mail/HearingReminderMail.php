<?php

namespace App\Mail;

use App\Models\CaseHearing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * تذكير بجلسة قضية (بريد) — يُرسَل للعميل والمحامي قبل الجلسة (طبقتا: نحو 24 ساعة، ونحو ساعة).
 */
class HearingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public CaseHearing $hearing,
        public string $remainingLabel
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير بجلسة قضيتك ('.$this->remainingLabel.') — '.($this->hearing->legalCase?->number ?? ''));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hearing-reminder',
            with: [
                'hearing' => $this->hearing,
                'remainingLabel' => $this->remainingLabel,
            ]
        );
    }
}
