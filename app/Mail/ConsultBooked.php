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
 * تأكيد فوري بحجز الاستشارة (بريد) — يُرسَل عند إتمام الحجز، مع ترميز Google Schema JSON-LD.
 */
class ConsultBooked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public bool $forLawyer = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->forLawyer
            ? 'موعد استشارة جديد مُسند إليك — '.$this->consult->ref
            : 'تأكيد حجز استشارتك — '.$this->consult->ref;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consult-booked',
            with: [
                'consult' => $this->consult,
                'forLawyer' => $this->forLawyer,
            ]
        );
    }

    /**
     * بلا مرفقات عمداً (fe55756): مرفق ‎.ics كان يُحجب أو يُعرض ملفّاً غامضاً لدى عملاء
     * بريد كثيرين. البديل الحيّ هو زرّ «أضِف إلى تقويمك» وتغذية Webcal في شاشة التقويم
     * (IcalendarService::googleUrl / feedForUser). لا تُعِدها إلا بقرار صريح.
     */
    public function attachments(): array
    {
        return [];
    }
}
