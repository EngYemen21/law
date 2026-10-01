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

    /**
     * **صلاحيّة رمز التحقّق — الافتراض المُعلَن** (`otp_ttl_minutes`، قرار المالك 2026-10-01: ٥ دقائق بدل ١٠).
     * واحدةٌ لرمز الجوال (دخول · تسجيل · تغيير الجوال) ورمز البريد. ورمز الجوال تولّده «تقنيات» وتتحقّق منه
     * بلا مدّةٍ نضبطها في واجهتها، فالحدّ نفرضه نحن من وقت الإرسال المحفوظ في الجلسة.
     */
    public const TTL_MINUTES = 5;

    /** سقف محاولات الرمز على حمولة الجلسة الواحدة — نظير EmailOtpService::MAX_ATTEMPTS. */
    public const MAX_ATTEMPTS = 5;

    /**
     * سقف إصدارات رمز الجوال في عمليّة دخول/تسجيل واحدة (الأوّل + ثلاث إعادات) — نظير
     * EmailOtpService::MAX_ISSUES. كلّ إعادة تُصفّر المحاولات الخمس، فبلا سقفٍ كان التخمين
     * التراكميّ وقصف الجوال بالرسائل مفتوحين بلا نهاية.
     */
    public const MAX_ISSUES = 4;

    /** طلب رمز دخول لمستخدم قائم — يرسله لجواله المسجّل عبر تقنيات. */
    /** الصلاحيّة النافذة بالدقائق — من الإعدادات. */
    public static function ttlMinutes(): int
    {
        return SettingsRegistry::int('otp_ttl_minutes');
    }

    /** لحظة انتهاء رمزٍ يُرسل الآن — تُحفظ في حمولة الجلسة. */
    public static function expiresAt(): string
    {
        return now()->addMinutes(self::ttlMinutes())->toIso8601String();
    }

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
     * تجاوز مؤقّت (رمز ثابت) — **في صندوق التجربة وحده** (`AppEnvironment::isSandbox`: التطوير والاختبارات
     * والتجريبيّة؛ قرار المالك 2026-09-29 بفصل البيئات). كان يعمل أينما ضُبط `AUTH_DEV_OTP`، ويقبل الدخول بأيّ
     * رقم هويّة بالرمز نفسه وصفحة الدخول تعرضه — فمضبوطٌ منسيٌّ في الإنتاج كان دخولاً لأيّ زائرٍ مديراً.
     * في الإنتاج يُتجاهَل (رسائل تقنيات وحدها)، ويكشفه `php artisan env:check`.
     * تجاوز البريد (`EmailOtpService::devBypass`) باقٍ محصوراً في التطوير كما كان.
     */
    public function devBypass(): bool
    {
        return self::isDevOtpConfigured() && AppEnvironment::isSandbox();
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
