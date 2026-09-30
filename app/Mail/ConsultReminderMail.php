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
 * تذكير بموعد الاستشارة (بريد) — الطبقة البعيدة قبل الموعد بـ`consult_reminder_far_minutes` (والقريبة رسالة نصّيّة).
 * للجلسة المرئية: رابط الدخول يصل في بريد منفصل قبل الموعد بـ`session_join_opens_minutes`.
 */
class ConsultReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public string $remainingLabel
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير بموعد استشارتك ('.$this->remainingLabel.') — '.$this->consult->ref);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consult-reminder',
            with: [
                'consult' => $this->consult,
                'remainingLabel' => $this->remainingLabel,
            ]
        );
    }
}
