<?php

namespace App\Mail;

use App\Models\Consult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * تأكيد سداد فاتورة الاستشارة (بريد) — يُرسَل للعميل عند نجاح الدفع عبر ميسّر،
 * ويُبلغه أنّ المكتب سيحدّد موعد جلسته ويُخطره به (قرار المالك 2026-09-14).
 */
class ConsultPaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام دفعتك — استشارة '.$this->consult->ref);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consult-paid',
            with: [
                'consult' => $this->consult,
            ]
        );
    }
}
