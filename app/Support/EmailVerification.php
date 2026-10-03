<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * **تأكيد بريد العميل بعد دخوله** (قرار المالك 2026-10-03، الخيار «أ» إلزاميّاً).
 *
 * الحساب الذي تُنشئه الإدارة يصل بريده غير مؤكَّد؛ فبعد الدخول برمز الجوال يُطلب رمزٌ يصل إلى البريد
 * (`EmailOtpService` — رسالة التسجيل الذاتيّ نفسها ومدّتها ومحاولاتها). الرمز المعلّق يُحفظ **لكلّ مستخدم**
 * لا في الجلسة، فيُرسله العميل من صفحته أو الإدارة من ملفّه، ويُدخله العميل في الحالين. لا يُخزَّن الرمز
 * نصّاً (تجزئة)، والإرسال محدودٌ بالساعة، والمحاولات محدودةٌ لكلّ رمز.
 */
final class EmailVerification
{
    private const CACHE_PREFIX = 'email-verify:';

    private const SEND_LIMIT_PREFIX = 'email-verify-send:';

    /** هل يلزم صاحب الحساب تأكيد بريده قبل استعمال حسابه؟ — للعميل وحده؛ الطاقم يُنشأ بريده مؤكَّداً. */
    public static function required(?User $user): bool
    {
        return $user !== null && $user->isClient() && $user->email_verified_at === null;
    }

    /**
     * يُصدر رمزاً جديداً إلى بريد الحساب الحاليّ ويحفظه معلّقاً.
     *
     * @return array{sent: bool, error: ?string}
     */
    public static function send(User $user): array
    {
        $limit = self::SEND_LIMIT_PREFIX.$user->id;
        if (RateLimiter::tooManyAttempts($limit, EmailOtpService::MAX_ISSUES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limit) / 60);

            return ['sent' => false, 'error' => 'تجاوزت حدّ إرسال رمز البريد — حاول بعد '.ArabicCount::duration(max(1, $minutes)).'.'];
        }

        $payload = app(EmailOtpService::class)->issue((string) $user->email, $user->name);
        if (! $payload['sent']) {
            return ['sent' => false, 'error' => 'تعذّر إرسال الرمز إلى بريدك حالياً — حاول بعد قليل أو صحّح البريد.'];
        }

        RateLimiter::hit($limit, 3600);
        Cache::put(self::CACHE_PREFIX.$user->id, $payload + ['sent_at' => now()->toIso8601String()], Carbon::parse($payload['expires_at']));

        return ['sent' => true, 'error' => null];
    }

    /**
     * الرمز المعلّق لبريد الحساب **الحاليّ** — رمزٌ صدر لبريدٍ قبل تصحيحه لا يُعتدّ به.
     *
     * @return array<string, mixed>|null حمولة `EmailOtpService::issue` + `sent_at`
     */
    public static function pending(User $user): ?array
    {
        $payload = Cache::get(self::CACHE_PREFIX.$user->id);

        return is_array($payload) && ($payload['email'] ?? null) === $user->email ? $payload : null;
    }

    /** ثوانٍ باقية قبل أن تُتاح إعادة الإرسال (نظير مؤقّت شاشة الدخول). */
    public static function resendIn(User $user): int
    {
        $sentAt = self::pending($user)['sent_at'] ?? null;

        return $sentAt === null ? 0 : max(0, OtpService::RESEND_SECONDS - (int) Carbon::parse($sentAt)->diffInSeconds(now()));
    }

    /** يتحقّق من الرمز؛ النجاح يؤكّد البريد ويُسقط الرمز، والفشل يُحتسب محاولةً على الرمز نفسه. */
    public static function confirm(User $user, string $code): bool
    {
        $payload = self::pending($user);
        if ($payload === null) {
            return false;
        }

        if (! app(EmailOtpService::class)->verify($payload, $code)) {
            $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
            Cache::put(self::CACHE_PREFIX.$user->id, $payload, Carbon::parse($payload['expires_at']));

            return false;
        }

        Cache::forget(self::CACHE_PREFIX.$user->id);
        $user->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    /** تصحيح البريد قبل تأكيده — يبقى غير مؤكَّد، ويسقط أيّ رمزٍ صدر للقديم. */
    public static function changeEmail(User $user, string $email): void
    {
        Cache::forget(self::CACHE_PREFIX.$user->id);
        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
    }
}
