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
 * بريد «رابط الجلسة جاهز» — يُرسَل قبل الموعد بـ5 دقائق مع تفعيل زر الدخول في المنصّة.
 */
class MeetingLinkReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public bool $forLawyer = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->forLawyer
            ? 'رابط جلسة الاستشارة جاهز برقم — '.$this->consult->ref
            : 'رابط جلسة استشارتك جاهز — '.$this->consult->ref;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.meeting-link-ready',
            with: [
                'consult' => $this->consult,
                'forLawyer' => $this->forLawyer,
            ]
        );
    }
}
