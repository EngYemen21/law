<?php

namespace App\Services;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إرسال رسالة نصّية (SMS) عبر واجهة الرسائل من «تقنيات».
 *
 * مستقلّة عن TaqnyatVerifyService عمداً: تلك تخاطب `verify.php` وهي واجهة رمز التحقّق
 * (تقنيات تُولّد الرمز وتتحقّق منه)، ولا تصلح لنصّ حرّ. هذه أوّل قناة SMS نصّية في المشروع.
 *
 * الأعراف نفسها المتّبعة في خدمات المشروع: تقرأ config لا env · أفضل-جهد (تسجّل ولا ترمي،
 * فتأخّر مزوّد لا يُسقط طلب المستخدم ولا تشغيل المجدول) · تُقنّع الجوال في السجلّ.
 *
 * @see https://dev.taqnyat.sa/en/doc/sms/
 */
class TaqnyatSmsService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.taqnyat.api_key')) && ! empty(config('services.taqnyat.sender'));
    }

    /**
     * يرسل نصّاً إلى جوال المستخدم. يعيد true إن قبِلت تقنيات الرسالة.
     *
     * التوقيع يطابق MailService::send(User, ...) عمداً فيتماثل التبديل بين القناتين
     * ولا يحتاج المستدعي معرفة تفاصيل المزوّد.
     */
    public function send(User $user, string $body): bool
    {
        $phone = (string) ($user->phone ?? '');

        if (! $this->isConfigured() || $phone === '' || ! Phone::isSendable($phone)) {
            return false;
        }

        return $this->dispatch(Phone::intl($phone), $body);
    }

    /** الإرسال الخام لرقم دوليّ جاهز — يستعمله SendSmsJob بعد التسلسل. */
    public function sendTo(string $intlPhone, string $body): bool
    {
        if (! $this->isConfigured() || $intlPhone === '') {
            return false;
        }

        return $this->dispatch($intlPhone, $body);
    }

    private function dispatch(string $intlPhone, string $body): bool
    {
        try {
            $response = Http::withToken((string) config('services.taqnyat.api_key'))
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->endpoint(), [
                    'recipients' => $intlPhone,
                    'body' => $body,
                    'sender' => (string) config('services.taqnyat.sender'),
                ]);

            if (! $response->successful()) {
                Log::warning('taqnyat.sms.http_error', [
                    'to' => Phone::mask($intlPhone),
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);

                return false;
            }

            // تقنيات تعيد statusCode 201 عند القبول؛ نقبل أي 2xx بلا خطأ معلن دفاعياً
            $status = (int) ($response->json('statusCode') ?? 201);
            $accepted = $status >= 200 && $status < 300;

            if (! $accepted) {
                Log::warning('taqnyat.sms.rejected', [
                    'to' => Phone::mask($intlPhone),
                    'statusCode' => $status,
                    'message' => $response->json('message'),
                ]);
            }

            return $accepted;
        } catch (\Throwable $e) {
            Log::warning('taqnyat.sms.exception', [
                'to' => Phone::mask($intlPhone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.taqnyat.base_url'), '/');
        $path = '/'.ltrim((string) config('services.taqnyat.sms_endpoint'), '/');

        return $base.$path;
    }
}
