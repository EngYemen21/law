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
 * تصعيد تذكرة بلا محامٍ متخصّص إلى الإدارة العليا.
 *
 * بريد **مستقلّ** عن TicketOpenedMail عمداً: الإدارة تستلم أصلاً «تذكرة جديدة من عميل» لكل
 * تذكرة، فدمج التصعيد فيه يدفنه في رسالة روتينية تُتجاهَل. العنوان هنا يطلب إجراءً صريحاً.
 */
class TicketEscalatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'يحتاج إسناداً: تذكرة بلا محامٍ متخصّص — '.$this->ticket->number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-escalated',
            with: [
                'ticket' => $this->ticket,
                'department' => $this->ticket->department ?: 'غير محدَّد',
                'portalPath' => '/admin/distribute',
            ]
        );
    }
}
