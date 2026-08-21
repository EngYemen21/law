<?php

namespace App\Mail;

use App\Models\MeetRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * بريد دعوة اجتماع للعميل — مع ترميز Google Schema JSON-LD.
 */
class MeetInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MeetRequest $req) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'دعوة اجتماع — '.$this->req->ref);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.meet-invite',
            with: [
                'req' => $this->req,
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
