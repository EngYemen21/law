<?php

namespace App\Mail;

use App\Support\OtpService;
use App\Support\SettingsRegistry;
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
        public int $ttlMinutes = OtpService::TTL_MINUTES,
    ) {}

    public function envelope(): Envelope
    {
        // اسم المكتب من الإعدادات لا `APP_NAME` (اسمٌ تقنيّ في البيئة بتهجئةٍ غير تهجئة المستندات)
        return new Envelope(subject: 'رمز التحقّق — '.SettingsRegistry::str('office_name'));
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
