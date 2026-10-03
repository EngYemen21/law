<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** اعتماد دفعة الفاتورة (بريد للعميل) — يُرسَل من `Finance\PaymentNotices::settled`. */
class InvoicePaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم اعتماد دفعتك — '.$this->invoice->number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-paid', with: ['invoice' => $this->invoice]);
    }
}
