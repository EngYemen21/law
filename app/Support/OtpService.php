<?php

namespace App\Support;

use App\Models\User;
use App\Services\TaqnyatVerifyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * منسّق رمز التحقّق (OTP) — غلاف نحيف حول واجهة «Verify» الرسميّة من تقنيات.
 * تقنيات تُولّد الرمز وتخزّنه وتتحقّق منه؛ نحفظ محليّاً معرّف العمليّة (requestId) في الجلسة فقط.
 * لا يُولَّد رمز ولا يُخزَّن لدينا إطلاقاً.
 */
class OtpService
{
    public const RESEND_SECONDS = 60;

    /** طلب رمز دخول لمستخدم قائم — يرسله لجواله المسجّل عبر تقنيات. */
    public function request(User $user): array
    {
        return $this->issue((string) $user->phone);
    }

    /** طلب رمز لتأكيد جوال أثناء تسجيل عميل جديد (بلا مستخدم بعد). */
    public function requestForRegistration(string $phone): array
    {
        return $this->issue($phone);
    }

    /** التحقّق من الرمز المُدخَل عبر تقنيات (كود 10 = ناجح). */
    public function verify(string $requestId, string $phone, string $code): bool
    {
        if ($this->devBypass()) {
            return hash_equals((string) config('services.auth_dev_otp'), $code);
        }

        return app(TaqnyatVerifyService::class)->check($phone, $requestId, $code);
    }

    /** تجاوز تطويريّ مؤقّت (رمز ثابت) — بيئتا local/testing حصراً (لا staging/إنتاج) وحين ضبط AUTH_DEV_OTP. */
    public function devBypass(): bool
    {
        return app()->environment('local', 'testing') && filled(config('services.auth_dev_otp'));
    }

    /** توليد معرّف عمليّة وإطلاق إرسال الرمز عبر تقنيات — يعيد بيانات الجلسة/العرض. */
    private function issue(string $phone): array
    {
        // تجاوز تطويريّ: بلا نداء لتقنيات — الرمز ثابت من AUTH_DEV_OTP
        if ($this->devBypass()) {
            Log::warning('otp.dev_bypass_active', ['to' => Phone::mask($phone)]);

            return [
                'request_id' => 'dev-'.Str::uuid(),
                'phone' => $phone,
                'masked_phone' => Phone::mask($phone),
                'sent' => true,
            ];
        }

        $requestId = (string) Str::uuid();

        $sent = app(TaqnyatVerifyService::class)->generate($phone, $requestId);

        return [
            'request_id' => $requestId,
            'phone' => $phone,
            'masked_phone' => Phone::mask($phone),
            'sent' => $sent,
        ];
    }
}
