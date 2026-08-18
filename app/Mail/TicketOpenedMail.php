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
 * فتح تذكرة جديدة (بريد) — نسخة العميل (تأكيد الاستلام) ونسخة المكتب (موظف/إدارة: طلب جديد يحتاج متابعة).
 * $audience: client | employee | admin — تضبط الصياغة ورابط اللوحة المناسب.
 */
class TicketOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $audience = 'client'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->audience === 'client'
            ? 'تم استلام طلبك بنجاح — '.$this->ticket->number
            : 'تذكرة جديدة من عميل — '.$this->ticket->number);
    }

    public function content(): Content
    {
        $portal = match ($this->audience) {
            'employee' => '/employee/tickets',
            'admin' => '/admin/tickets',
            default => '/tickets',
        };

        return new Content(
            view: 'emails.ticket-opened',
            with: [
                'ticket' => $this->ticket,
                'audience' => $this->audience,
                'portalPath' => $portal,
            ]
        );
    }
}
