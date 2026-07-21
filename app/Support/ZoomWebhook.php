<?php

namespace App\Support;

/**
 * تحقّق أحداث Zoom (Event Subscriptions): توقيع HMAC وردّ تحقّق نقطة النهاية.
 * مصدر واحد قابل للاختبار (نفس مبدأ ChannelAccess).
 */
class ZoomWebhook
{
    public static function secret(): ?string
    {
        $secret = config('services.zoom.webhook_secret');

        return $secret !== '' ? $secret : null;
    }

    /**
     * يتحقّق من توقيع Zoom: x-zm-signature = "v0={hmac_sha256(secret, 'v0:{ts}:{rawBody}')}".
     */
    public static function verify(string $timestamp, string $rawBody, string $signature): bool
    {
        $secret = self::secret();
        if ($secret === null || $timestamp === '' || $signature === '') {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$rawBody}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * يرفض الطلبات القديمة (منع إعادة الإرسال replay): يقبل ختماً ضمن ±المهلة (5د افتراضاً).
     * الختم داخل التوقيع فلا يُزوَّر، لكن طلباً ملتقَطاً صالحاً كان قابلاً لإعادة الإرسال دون هذا الحدّ.
     */
    public static function fresh(string $timestamp, int $toleranceSeconds = 300): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        return abs(now()->timestamp - (int) $timestamp) <= $toleranceSeconds;
    }

    /**
     * ردّ تحقّق نقطة النهاية (endpoint.url_validation): يُعيد الرمز مشفّراً بالسرّ.
     *
     * @return array{plainToken: string, encryptedToken: string}
     */
    public static function validationResponse(string $plainToken): array
    {
        return [
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, (string) self::secret()),
        ];
    }
}
