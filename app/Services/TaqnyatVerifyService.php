<?php

namespace App\Services;

use App\Support\Phone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * واجهة «Verify» الرسميّة من تقنيات (taqnyat.sa) — رمز التحقّق (OTP): تقنيات تُولّد الرمز وتخزّنه وتتحقّق منه.
 * خطوتان على نفس النقطة POST /verify.php: توليد (كود 5) ثمّ تحقّق بـ activeKey (كود 10).
 * نظير خدمات المشروع: يقرأ config لا env، وأفضل-جهد (يسجّل ولا يرمي). بلا مفاتيح يعمل النظام محاكاةً (انظر OtpService).
 *
 * @see https://dev.taqnyat.sa/en/doc/verify/
 */
class TaqnyatVerifyService
{
    // أكواد النتائج الموثّقة رسميّاً
    public const SENT = 5;              // أُرسل بنجاح

    public const SENT_ALREADY = 7;      // أُرسل سابقاً (ضمن المهلة)

    public const VERIFIED = 10;         // تحقّق ناجح

    public const INCORRECT = 11;        // رمز خاطئ

    public const ATTEMPTS_EXCEEDED = 8; // استُنفدت المحاولات — يلزم requestId جديد

    public const ATTEMPTS_EXHAUSTED = 12;

    public function isConfigured(): bool
    {
        return ! empty(config('services.taqnyat.api_key')) && ! empty(config('services.taqnyat.sender'));
    }

    /** توليد رمز جديد وإرساله عبر تقنيات. يعيد true إن أُرسل (كود 5/7). */
    public function generate(string $phone, string $requestId, string $lang = 'ar'): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $code = $this->call($this->payload($phone, $requestId, $lang), 'generate', $phone);

        return in_array($code, [self::SENT, self::SENT_ALREADY], true);
    }

    /** التحقّق من الرمز المُدخَل. يعيد true فقط عند كود 10 (تحقّق ناجح). */
    public function check(string $phone, string $requestId, string $code, string $lang = 'ar'): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $result = $this->call(
            $this->payload($phone, $requestId, $lang) + ['activeKey' => $code],
            'check',
            $phone
        );

        return $result === self::VERIFIED;
    }

    /** جسم الطلب الموحّد (بلا activeKey). */
    private function payload(string $phone, string $requestId, string $lang): array
    {
        return [
            'apiKey' => (string) config('services.taqnyat.api_key'), // الترويسة أيضاً — إزالةً للّبس في التوثيق
            'numbers' => [Phone::intl($phone)],
            'sender' => (string) config('services.taqnyat.sender'),
            'method' => 'sms',
            'lang' => in_array($lang, ['ar', 'en'], true) ? $lang : 'ar',
            'requestId' => $requestId,
            'returnJson' => 1,
        ];
    }

    /** ينفّذ النداء ويُرجع كود النتيجة الرقميّ (أو null عند تعذّر التحليل/الاتصال). */
    private function call(array $payload, string $op, string $phone): ?int
    {
        try {
            // خادم verify.php بطيء الاستجابة (لوحظ ~10ث)؛ مهلة سخيّة بمحاولة واحدة (إعادة المحاولة تضاعف البطء)
            $response = Http::withToken((string) config('services.taqnyat.api_key'))
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->endpoint(), [$payload]);

            // لا نُفسّر كود النتيجة إلّا من ردّ ناجح (2xx) — تفادي تفسير صفحة خطأ وسيط تحوي رقماً عرَضاً
            if (! $response->successful()) {
                Log::warning("taqnyat.verify.{$op}.http_error", ['to' => Phone::mask($phone), 'status' => $response->status()]);

                return null;
            }

            $code = $this->resultCode($response);

            if ($code === null) {
                Log::warning("taqnyat.verify.{$op}.unparsed", [
                    'to' => Phone::mask($phone),
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);
            }

            return $code;
        } catch (\Throwable $e) {
            Log::warning("taqnyat.verify.{$op}.exception", ['to' => Phone::mask($phone), 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** استخراج كود النتيجة من الرد دفاعيّاً: JSON (الحقل «Data.result» أو «code») أو نصّ رقميّ خام (verify.php يعيد رقماً مباشرةً). */
    private function resultCode(Response $response): ?int
    {
        $json = $response->json();

        if (is_array($json)) {
            // الرد قد يكون كائناً أو مصفوفة تحوي كائناً (كما في نمط الطلب)
            if (array_is_list($json) && isset($json[0]) && is_array($json[0])) {
                $json = $json[0];
            }

            // إذا كان الرد يحمل فشلاً صريحاً أو كائن خطأ
            if ((isset($json['ResponseStatus']) && $json['ResponseStatus'] === 'fail') || ! empty($json['Error'])) {
                return null;
            }

            // فحص البيانات المتداخلة (استجابة تقنيات الحديثة: Data.result)
            if (isset($json['Data']) && is_array($json['Data'])) {
                foreach (['result', 'code', 'statusCode', 'Code'] as $key) {
                    if (isset($json['Data'][$key]) && is_numeric($json['Data'][$key])) {
                        return (int) $json['Data'][$key];
                    }
                }
            }

            foreach (['code', 'statusCode', 'Code', 'result'] as $key) {
                if (isset($json[$key]) && is_numeric($json[$key])) {
                    return (int) $json[$key];
                }
            }
        }

        // بعض ردود verify.php نصّ عاديّ يحمل الكود الرقميّ مباشرةً (مثل «5» أو «10»)
        $body = trim((string) $response->body());
        if ($body !== '' && ctype_digit($body)) {
            return (int) $body;
        }

        return null;
    }

    private function endpoint(): string
    {
        return rtrim((string) config('services.taqnyat.base_url', 'https://api.taqnyat.sa'), '/').'/verify.php';
    }
}
