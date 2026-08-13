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
 * بريد دعوة اجتماع للعميل — يُرسَل عند إنشاء الدعوة من المكتب ليؤكّد العميل حضوره.
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
}
