<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * رسالة رمز تحقّق عامّة قابلة لإعادة الاستخدام (تأكيد بريد، استعادة، ...).
 */
class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public ?string $name = null,
        public string $purpose = 'تأكيد بريدك الإلكتروني',
        public int $ttlMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رمز التحقّق — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify', with: [
            'code' => $this->code,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'ttlMinutes' => $this->ttlMinutes,
        ]);
    }
}
