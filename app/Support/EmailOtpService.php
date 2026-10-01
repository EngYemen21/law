<?php

namespace App\Support;

use App\Mail\VerificationCodeMail;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * رمز تحقّق البريد (OTP) — Resend خدمة إرسال فقط (لا واجهة verify)، فالرمز يُدار ذاتيّاً:
 * نُولّده ونرسله عبر MailService ونعيد حمولة الجلسة (مُجزّأة + انتهاء + محاولات). لا يُخزَّن الرمز نصّاً.
 */
class EmailOtpService
{
    public const MAX_ATTEMPTS = 5;

    // أقصى عدد إصدارات لرمز البريد في تسجيل واحد (يحدّ التخمين عبر إعادة الإرسال — 4×5=20 محاولة كحدّ)
    public const MAX_ISSUES = 4;

    /** تجاوز تطويريّ مؤقّت (رمز ثابت) — بيئتا local/testing حصراً (لا staging/إنتاج) وحين ضبط AUTH_DEV_OTP. */
    public function devBypass(): bool
    {
        // مصدر واحد للقرار مع OtpService — لا يكفي APP_ENV وحده (راجع OtpService::productionLike)
        return OtpService::isDevOtpConfigured()
            && app()->environment('local', 'testing')
            && ! OtpService::productionLike();
    }

    /** توليد رمز بريد وإرساله — يعيد حمولة الجلسة. */
    public function issue(string $email, ?string $name = null): array
    {
        // تجاوز تطويريّ: رمز ثابت بلا إرسال فعليّ عبر Resend
        if ($this->devBypass()) {
            $code = (string) config('services.auth_dev_otp');

            return [
                'channel' => 'email',
                'email' => $email,
                'code_hash' => Hash::make($code),
                'expires_at' => OtpService::expiresAt(),
                'attempts' => 0,
                'masked' => $this->mask($email),
                'sent' => true,
            ];
        }

        $code = (string) random_int(1000, 9999);

        $sent = app(MailService::class)->send(
            $email,
            new VerificationCodeMail($code, $name, 'تأكيد بريدك الإلكتروني', OtpService::ttlMinutes())
        );

        return [
            'channel' => 'email',
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => OtpService::expiresAt(),
            'attempts' => 0,
            'masked' => $this->mask($email),
            'sent' => $sent,
        ];
    }

    /** التحقّق من رمز البريد المُدخَل مقابل حمولة الجلسة (انتهاء + محاولات + تجزئة). */
    public function verify(array $otp, string $code): bool
    {
        if (($otp['channel'] ?? null) !== 'email') {
            return false;
        }

        if (($otp['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (empty($otp['expires_at']) || Carbon::parse($otp['expires_at'])->isPast()) {
            return false;
        }

        return Hash::check($code, (string) ($otp['code_hash'] ?? ''));
    }

    /** تقنيع البريد للعرض (يُبقي أوّل حرفين + النطاق) — «ab•••@domain». */
    private function mask(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $head = mb_substr($local, 0, 2);

        return $head.'•••@'.$domain;
    }
}
