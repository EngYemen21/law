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

    /** سقف محاولات الرمز على حمولة الجلسة الواحدة — نظير EmailOtpService::MAX_ATTEMPTS. */
    public const MAX_ATTEMPTS = 5;

    /**
     * سقف إصدارات رمز الجوال في عمليّة دخول/تسجيل واحدة (الأوّل + ثلاث إعادات) — نظير
     * EmailOtpService::MAX_ISSUES. كلّ إعادة تُصفّر المحاولات الخمس، فبلا سقفٍ كان التخمين
     * التراكميّ وقصف الجوال بالرسائل مفتوحين بلا نهاية.
     */
    public const MAX_ISSUES = 4;

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

    /**
     * هل AUTH_DEV_OTP مضبوط؟ — `filled` لا truthiness: القيمة "0" رمز صالح يقبله التجاوز،
     * وكان الحارس في AppServiceProvider يفحص truthiness فيمرّرها بينما يقبلها التجاوز.
     */
    public static function isDevOtpConfigured(): bool
    {
        return filled(config('services.auth_dev_otp'));
    }

    /**
     * هل تبدو البيئة إنتاجيّة بمؤشّرات **مستقلّة عن APP_ENV**؟
     *
     * لماذا: الحارس القديم كان شرطه `! environment('local','testing')` — أي أنه يستند إلى
     * المتغيّر نفسه الذي بُني ليحرس منه. فخادم إنتاجيّ وصله APP_ENV=local يصمت فيه الحارس
     * ويعمل التجاوز، فيدخل أي أحد بأي رقم هويّة بالرمز الثابت. مؤشّران مستقلّان:
     * إطفاء APP_DEBUG، أو مضيف APP_URL ليس نطاق تطوير محلّيّاً.
     */
    public static function productionLike(): bool
    {
        // الاختبارات حتميّة — لا تُستنتج بيئة من إعدادات المضيف
        if (app()->environment('testing')) {
            return false;
        }

        if (! config('app.debug')) {
            return true;
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        return ! Str::endsWith($host, ['.test', '.local', '.localhost', 'localhost'])
            && ! in_array($host, ['127.0.0.1', '::1'], true);
    }

    /**
     * تجاوز تطويريّ مؤقّت (رمز ثابت) — بيئتا local/testing حصراً وحين ضبط AUTH_DEV_OTP،
     * ولا يعمل على مضيفٍ يبدو إنتاجيّاً ولو قال APP_ENV غير ذلك (راجع `productionLike`).
     * الشرط نفسه في `EmailOtpService::devBypass` — القناتان تُفتحان معاً أو تُغلقان معاً.
     */
    public function devBypass(): bool
    {
        return self::isDevOtpConfigured()
            && app()->environment('local', 'testing')
            && ! self::productionLike();
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
