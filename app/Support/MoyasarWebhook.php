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
     * يتحقّق من هوية مصدر الإشعار عبر `secret_token` من جسم الحدث أو الترويسات.
     */
    public static function verify(mixed $requestOrPayload): bool
    {
        $secret = self::secret();
        if ($secret === null) {
            return false;
        }

        $token = '';
        if ($requestOrPayload instanceof \Illuminate\Http\Request) {
            $token = (string) (
                $requestOrPayload->input('secret_token')
                ?: $requestOrPayload->header('X-Moyasar-Secret-Token')
                ?: $requestOrPayload->header('X-Secret-Token')
                ?: $requestOrPayload->header('secret-token')
                ?: $requestOrPayload->bearerToken()
                ?: $requestOrPayload->query('secret_token', '')
            );
        } elseif (is_array($requestOrPayload)) {
            $token = (string) ($requestOrPayload['secret_token'] ?? '');
        }

        return hash_equals($secret, $token);
    }
}
