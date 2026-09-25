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
 * **أُلغي موعد استشارتك ويُحدَّد موعدٌ جديد** — بريدُ العميل عند إعادة الجدولة.
 *
 * كانت إعادة جدولة الاستشارة تُبلغ العميل بإشعارٍ داخل النظام وحده، بينما الاجتماع والجلسة
 * القضائيّة يصلهما بريد. والاستشارة مدفوعة وموعدها ينتظره العميل خارج النظام: من لم يفتح
 * حسابه يحضر في الموعد الملغى.
 */
class ConsultRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public string $oldWhen,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'إعادة جدولة استشارتك — '.$this->consult->ref);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.consult-rescheduled', with: [
            'consult' => $this->consult,
            'oldWhen' => $this->oldWhen,
            'reason' => $this->reason,
        ]);
    }
}
