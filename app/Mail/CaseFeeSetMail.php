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
 * تحديد أتعاب القضية (بريد) — يُرسَل للعميل عند تحديد الإدارة العليا للأتعاب وإصدار الفاتورة،
 * ويدعوه لسدادها لتفعيل القضية.
 */
class CaseFeeSetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case,
        public int $total
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'صدرت فاتورة أتعاب قضيتك — '.$this->case->number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.case-fee-set',
            with: [
                'case' => $this->case,
                'total' => $this->total,
            ]
        );
    }
}
