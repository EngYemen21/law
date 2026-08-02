<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تكامل Zoom (Server-to-Server OAuth) — إنشاء اجتماعات الاستشارات المرئية تلقائياً.
 * بلا مفاتيح في .env يعمل النظام بالرابط الداخلي الاحتياطي (اختبارات حتمية بلا شبكة).
 */
class ZoomService
{
    /** مدّة تهدئة بعد فشل جلب رمز — تمنع عاصفة إعادة المحاولة أثناء انقطاع Zoom. */
    private const FAILURE_COOLDOWN_SECONDS = 60;

    public function isConfigured(): bool
    {
        return ! empty(config('services.zoom.account_id'))
            && ! empty(config('services.zoom.client_id'))
            && ! empty(config('services.zoom.client_secret'));
    }

    /**
     * ينشئ اجتماع Zoom للاستشارة ويعيد [id, join_url, start_url] أو null عند التعذّر.
     * التسجيل السحابي مفعّل (التسجيل والتلخيص على جانب Zoom).
     *
     * @param  bool  $confidential  للاجتماعات السرّية: غرفة انتظار + منع الدخول قبل المضيف
     *                              (يدخل المضيف عبر start_url ويأذن للمشاركين).
     */
    public function createMeeting(string $topic, int $durationMinutes = 60, bool $confidential = false, ?Carbon $startsAt = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            // اجتماع مجدول بوقت حقيقي (type 2 + start_time) فيظهر مجدولاً على Zoom، وإلا فوري (type 1)
            $body = [
                'topic' => $topic,
                'type' => $startsAt ? 2 : 1,
                'duration' => $durationMinutes,
                'timezone' => 'Asia/Riyadh',
                'settings' => $this->settings($confidential),
            ];
            if ($startsAt) {
                $body['start_time'] = $startsAt->format('Y-m-d\TH:i:s');
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post('https://api.zoom.us/v2/users/me/meetings', $body);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'id' => (string) ($data['id'] ?? ''),
                    'join_url' => $data['join_url'] ?? null,
                    'start_url' => $data['start_url'] ?? null,
                    'password' => $data['password'] ?? null,
                ];
            }

            Log::warning('ZoomService createMeeting failed: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService createMeeting failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * حذف اجتماع Zoom — أفضل-جهد لتنظيف اجتماع يتيم (مثل تعارض جدولة يُلغي السجلّ بعد إنشاء الاجتماع).
     * يسجّل الفشل ولا يرمي (نظير Live::push) — التنظيف تحسينٌ لا مصدرُ حقيقة.
     */
    public function deleteMeeting(string $meetingId): void
    {
        if ($meetingId === '' || ! $this->isConfigured()) {
            return;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return;
            }
            Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->delete('https://api.zoom.us/v2/meetings/'.$meetingId);
        } catch (\Throwable $e) {
            Log::warning('ZoomService deleteMeeting failed: '.$e->getMessage());
        }
    }

    /** هل هُيّئ Meeting SDK (تطبيق منفصل عن S2S) لتوليد توقيع التضمين داخل المنصّة؟ */
    public function sdkConfigured(): bool
    {
        return ! empty(config('services.zoom.sdk_key'))
            && ! empty(config('services.zoom.sdk_secret'));
    }

    /**
     * توقيع Meeting SDK (JWT/HS256) لتضمين الاجتماع في صفحة «غرفة الجلسة».
     * الدور: 0 مشارك (العميل)، 1 مضيف (الموظف/المحامي). يعيد null إن لم يُهيّأ الـ SDK.
     */
    public function sdkSignature(string $meetingNumber, int $role): ?string
    {
        if (! $this->sdkConfigured()) {
            return null;
        }

        $key = config('services.zoom.sdk_key');
        $secret = config('services.zoom.sdk_secret');
        $iat = time() - 30;              // هامش انحراف الساعة
        $exp = $iat + 60 * 60 * 2;       // صلاحية ساعتان (الحدّ الأدنى 1800ث)

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'appKey' => $key,
            'sdkKey' => $key,
            'mn' => $meetingNumber,
            'role' => $role,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp,
        ];

        $segments = [
            $this->b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signing = implode('.', $segments);
        $segments[] = $this->b64url(hash_hmac('sha256', $signing, $secret, true));

        return implode('.', $segments);
    }

    /**
     * رمز ZAK للمضيف (Server-to-Server) — يسمح للموظف/المحامي بالاستضافة داخل التضمين.
     * يُخبّأ ~55 دقيقة؛ يعيد null إن تعذّر (بلا مفاتيح S2S) فينضمّ الموظف كمشارك.
     */
    public function zakToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return $this->cachedToken('zoom.zak', 55, function () {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            // مهلة قصيرة: هذا النداء في مسار «دخول الجلسة» التفاعلي، فلا يُعلّق العامل طويلاً
            // أثناء بطء Zoom؛ الاحتياط تخفيض الدور لمشارك (role 0) في المتحكّم.
            $response = Http::withToken($token)
                ->timeout(8)
                ->get('https://api.zoom.us/v2/users/me/token', ['type' => 'zak']);

            if ($response->successful()) {
                return $response->json('token');
            }

            Log::warning('ZoomService zakToken failed: '.$response->status().' '.$response->body());

            return null;
        });
    }

    /**
     * يجلب ملف النصّ التفريغي (VTT) من التسجيل السحابي وينظّفه إلى نصّ عربي متّصل.
     * يستخدم download_token من حدث recording.completed. يعيد null بلطف عند التعذّر.
     */
    public function downloadTranscript(string $url, string $token): ?string
    {
        if ($url === '' || $token === '') {
            return null;
        }

        @set_time_limit(120);

        try {
            $response = Http::withToken($token)->timeout(30)->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $this->cleanVtt($response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService downloadTranscript failed: '.$e->getMessage());

            return null;
        }
    }

    /** يزيل ترويسة WEBVTT وأرقام المقاطع وأسطر التوقيت، ويُبقي الكلام فقط. */
    private function cleanVtt(string $vtt): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $vtt) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || $t === 'WEBVTT' || ctype_digit($t)) {
                continue;
            }
            if (str_contains($t, '-->')) { // سطر توقيت 00:00:01.000 --> 00:00:03.000
                continue;
            }
            $out[] = $t;
        }

        return trim(implode("\n", $out));
    }

    /** ترميز base64url بلا حشو (لأجزاء JWT). */
    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** إعدادات الاجتماع؛ السرّي يفرض غرفة انتظار ودخول المضيف أولاً. */
    private function settings(bool $confidential): array
    {
        return [
            'join_before_host' => ! $confidential,
            'waiting_room' => $confidential,
            'participant_video' => true,
            'host_video' => true,
            'auto_recording' => 'cloud',
            // تشغيل ملخّص AI Companion تلقائياً (خطة Pro) — يبدأ فور بدء الاجتماع دون حاجة لحضور المضيف
            'auto_start_meeting_summary' => true,
        ];
    }

    /**
     * يستخرج الملخّص من حمولة حدث meeting.summary_completed مباشرةً (بلا نداء API).
     * الحمولة تحمل الملخّص كاملاً بنفس حقول نقطة meeting_summary، فقراءتها أضمن من
     * إعادة طلبه بمعرّف رقمي ترفضه Zoom للاجتماعات المنتهية (خطأ 400 «Invalid meeting id»).
     *
     * @param  array<string, mixed>|null  $object  محتوى payload.object من الحدث
     * @return array{overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>}|null
     */
    public static function summaryFromPayload(?array $object): ?array
    {
        if (! is_array($object)) {
            return null;
        }

        $parsed = [
            'overview' => (string) ($object['summary_overview'] ?? ''),
            'details' => array_values(array_map(
                fn ($d) => ['label' => (string) ($d['label'] ?? ''), 'summary' => (string) ($d['summary'] ?? '')],
                (array) ($object['summary_details'] ?? []),
            )),
            'next_steps' => array_values(array_map('strval', (array) ($object['next_steps'] ?? []))),
        ];

        // لا نُرجع ملخّصاً فارغاً (كي لا يُختم zoom_summary_at ويُحجب جلب حقيقي لاحق)
        $hasContent = trim($parsed['overview']) !== ''
            || $parsed['details'] !== []
            || $parsed['next_steps'] !== [];

        return $hasContent ? $parsed : null;
    }

    /**
     * ملخّص AI Companion من Zoom لاجتماع منتهٍ (خطة Pro) — نظرة عامّة + تفاصيل + خطوات تالية.
     * غير متزامن: يجهز بعد دقائق من الانتهاء؛ يعيد null قبل الجهوزية أو بلا نطاق meeting:read:summary.
     * مسار بديل للأمر المجدول فقط؛ مسار الويبهوك يقرأ من الحمولة عبر summaryFromPayload().
     *
     * @return array{overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>}|null
     */
    public function meetingSummary(string $meetingId): ?array
    {
        if (! $this->isConfigured() || $meetingId === '') {
            return null;
        }

        @set_time_limit(60);

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://api.zoom.us/v2/meetings/{$meetingId}/meeting_summary");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'overview' => (string) ($data['summary_overview'] ?? ''),
                    'details' => array_values(array_map(
                        fn ($d) => ['label' => (string) ($d['label'] ?? ''), 'summary' => (string) ($d['summary'] ?? '')],
                        (array) ($data['summary_details'] ?? []),
                    )),
                    'next_steps' => array_values(array_map('strval', (array) ($data['next_steps'] ?? []))),
                ];
            }

            // 400/404 شائعان: الملخّص لم يجهز بعد أو النطاق غير مُفعّل — لا نُسجّل ضجيجاً لهذين
            if (! in_array($response->status(), [400, 404], true)) {
                Log::warning('ZoomService meetingSummary failed: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('ZoomService meetingSummary failed: '.$e->getMessage());
        }

        return null;
    }

    /** رمز وصول Server-to-Server OAuth (يُخبّأ ~50 دقيقة). */
    private function token(): ?string
    {
        return $this->cachedToken('zoom.s2s.token', 50, function () {
            $response = Http::asForm()
                ->withBasicAuth(config('services.zoom.client_id'), config('services.zoom.client_secret'))
                ->timeout(15)
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('services.zoom.account_id'),
                ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::warning('ZoomService token failed: '.$response->status().' '.$response->body());

            return null;
        });
    }

    /**
     * تخبئة رمز مع كبح الفشل: يُخزَّن عند النجاح فقط، ويُوضع مفتاح «تهدئة» قصير عند الفشل
     * فلا يُعاد النداء فوراً. يُصحّح أن Cache::remember يعيد تنفيذ الـclosure عند إرجاعه null
     * (لا يخزّنه)، فكان انقطاع Zoom يُطلق عاصفة إعادة محاولة بلا كبح.
     *
     * @param  callable(): ?string  $fetch
     */
    private function cachedToken(string $key, int $ttlMinutes, callable $fetch): ?string
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // تهدئة بعد فشل حديث — تُرجع null فوراً بلا نداء شبكة
        if (Cache::get($key.'.cooldown')) {
            return null;
        }

        try {
            $value = $fetch();
        } catch (\Throwable $e) {
            Log::warning('ZoomService token fetch failed ('.$key.'): '.$e->getMessage());
            $value = null;
        }

        if ($value !== null) {
            Cache::put($key, $value, now()->addMinutes($ttlMinutes));

            return $value;
        }

        Cache::put($key.'.cooldown', true, now()->addSeconds(self::FAILURE_COOLDOWN_SECONDS));

        return null;
    }
}
