<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** إثبات تحويل رفعه العميل (بريد للإدارة العليا) — يُرسَل من `Finance\PaymentNotices::proofSubmitted`. */
class PaymentProofSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'إثبات تحويل بانتظار المراجعة — '.$this->invoice->number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-proof-submitted', with: ['invoice' => $this->invoice]);
    }
}
