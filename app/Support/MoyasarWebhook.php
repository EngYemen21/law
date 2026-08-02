<?php

namespace App\Support;

/**
 * تحقّق إشعارات Moyasar (Webhooks): يُرسل ميسّر حقل `secret_token` في جسم الحدث،
 * نطابقه بالسرّ المضبوط (مقارنة ثابتة الزمن). مصدر واحد قابل للاختبار (مثل ZoomWebhook).
 */
class MoyasarWebhook
{
    public static function secret(): ?string
    {
        $secret = config('services.moyasar.webhook_secret');

        return ($secret !== null && $secret !== '') ? (string) $secret : null;
    }

    /**
     * يتحقّق من هوية مصدر الإشعار عبر `secret_token` في جسم الحدث.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function verify(array $payload): bool
    {
        $secret = self::secret();
        if ($secret === null) {
            return false;
        }

        return hash_equals($secret, (string) ($payload['secret_token'] ?? ''));
    }
}
