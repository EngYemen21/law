<?php

namespace App\Services;

use App\Support\WebTimeLimit;
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
    public function deleteMeeting(string $meetingId): bool
    {
        if ($meetingId === '' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return false;
            }
            // كانت الاستجابة تُهمَل تماماً: 401 (رمز منتهٍ) و429 (تجاوز الحدّ) و404 تمرّ
            // كأنها نجاح، فيبقى اجتماع حيّ في حساب Zoom بلا أي أثر يدلّ عليه.
            $response = Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->delete('https://api.zoom.us/v2/meetings/'.$meetingId);

            if ($response->successful() || $response->status() === 404) {
                return true; // 404 = محذوف أصلاً، والغاية متحقّقة
            }
            Log::warning('ZoomService deleteMeeting failed: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService deleteMeeting failed: '.$e->getMessage());
        }

        return false;
    }

    /**
     * تحديث اجتماع Zoom (إعادة جدولة) — أفضل-جهد لمزامنة الموعد/المدة الجديدين مع Zoom.
     * يعيد true عند نجاح PATCH (204). يسجّل الفشل ولا يرمي (القاعدة مصدر الحقيقة).
     *
     * @param  array<string,mixed>  $changes  مثل ['start_time'=>'Y-m-d\TH:i:s','duration'=>60,'topic'=>'…']
     */
    public function updateMeeting(string $meetingId, array $changes): bool
    {
        if ($meetingId === '' || $changes === [] || ! $this->isConfigured()) {
            return false;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return false;
            }
            $response = Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->patch('https://api.zoom.us/v2/meetings/'.$meetingId, $changes + ['timezone' => 'Asia/Riyadh']);

            if ($response->successful()) {
                return true;
            }
            Log::warning('ZoomService updateMeeting failed: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService updateMeeting failed: '.$e->getMessage());
        }

        return false;
    }

    /**
     * إنهاء اجتماع Zoom الجاري فعليًا (action=end) — أفضل-جهد. يعمل على الاجتماع الجاري فقط؛
     * على غيره ترفض Zoom بلطف (لا يهمّ، القاعدة مصدر الحقيقة). يسجّل ولا يرمي.
     */
    public function endMeeting(string $meetingId): bool
    {
        if ($meetingId === '' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return false;
            }
            $response = Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->put('https://api.zoom.us/v2/meetings/'.$meetingId.'/status', ['action' => 'end']);

            if ($response->successful()) {
                return true;
            }
            // 400 متوقّع على اجتماع غير جارٍ — يُسجَّل بمستوى أدنى كي لا يُغرق السجلّ
            // (التوثيق أعلاه ينصّ: على غيره ترفض Zoom بلطف والقاعدة مصدر الحقيقة).
            $response->status() === 400
                ? Log::info('ZoomService endMeeting: الاجتماع غير جارٍ ('.$meetingId.')')
                : Log::warning('ZoomService endMeeting failed: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService endMeeting failed: '.$e->getMessage());
        }

        return false;
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
     * أفضل ملف وسائط قابل للتنزيل لتسجيل اجتماع سحابي (فيديو MP4 أو صوت M4A) — [download_url + رمز] أو null.
     * play_url المخزّن صفحة مشاهدة لا ملفاً؛ التنزيل الفعلي يحتاج download_url من واجهة التسجيلات.
     *
     * @return array{url:string, token:?string}|null
     */
    public function recordingDownload(string $meetingId, string $type = 'video'): ?array
    {
        if (! $this->isConfigured() || $meetingId === '') {
            return null;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            $resp = Http::withToken($token)->timeout(20)
                ->get("https://api.zoom.us/v2/meetings/{$meetingId}/recordings");
            if (! $resp->successful()) {
                return null;
            }

            $files = (array) $resp->json('recording_files', []);
            $file = $type === 'audio'
                ? (collect($files)->firstWhere('recording_type', 'audio_only')
                    ?: collect($files)->firstWhere('file_extension', 'M4A'))
                : (collect($files)->firstWhere('recording_type', 'shared_screen_with_speaker_view')
                    ?: collect($files)->firstWhere('file_extension', 'MP4'));
            $url = $file['download_url'] ?? null;
            if (! $url) {
                return null;
            }

            // رمز التنزيل: download_access_token إن ورد، وإلا رمز S2S نفسه — بلا رمز كان
            // Zoom يعيد صفحة HTML بـ200 فتُحفَظ باسم mp4 وتنزل «سليمة» لكنها لا تعمل
            return ['url' => (string) $url, 'token' => ($resp->json('download_access_token') ?: $token)];
        } catch (\Throwable $e) {
            Log::warning('ZoomService recordingDownload failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * يجلب ملف النصّ التفريغي (VTT) من التسجيل السحابي وينظّفه إلى نصّ عربي متّصل.
     * يستخدم download_token من حدث recording.completed. يعيد null بلطف عند التعذّر.
     */
    /**
     * @param  bool  $clean  true: نصّ منظّف للعرض النصّي المختصر · false: **VTT خام كما ورد من Zoom**
     *                       (يحفظ التوقيت والمتحدث — مطلوب لعرض النصّ الحرفي «الكلام مع الوقت ومن المتحدث»)
     */
    public function downloadTranscript(string $url, string $token, bool $clean = true): ?string
    {
        if ($url === '' || $token === '') {
            return null;
        }

        WebTimeLimit::raise(120);

        try {
            $response = Http::withToken($token)->timeout(30)->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $clean ? $this->cleanVtt($response->body()) : $response->body();
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
     * يدعم الجلب بـ Meeting ID أو UUID المشفّر مباشرةً.
     *
     * @return array{overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>, uuid: ?string}|null
     */
    /**
     * كل انعقادات الاجتماع (الجلسة قد تنقطع ويُعاد الدخول ⇒ عدّة uuid لنفس المعرّف)
     * مرتّبة زمنياً تصاعدياً: [['uuid' => ..., 'start_time' => ...], ...].
     */
    public function meetingInstances(string $meetingId): array
    {
        if (! $this->isConfigured() || $meetingId === '') {
            return [];
        }

        try {
            $token = $this->token();
            if (! $token) {
                return [];
            }

            $response = Http::withToken($token)->timeout(15)
                ->get("https://api.zoom.us/v2/past_meetings/{$meetingId}/instances");
            if (! $response->successful()) {
                return [];
            }

            $instances = array_values(array_filter(
                (array) $response->json('meetings', []),
                fn ($i) => ! empty($i['uuid']),
            ));
            usort($instances, fn ($a, $b) => strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? '')));

            return array_map(fn ($i) => [
                'uuid' => (string) $i['uuid'],
                'start_time' => (string) ($i['start_time'] ?? ''),
            ], $instances);
        } catch (\Throwable $e) {
            Log::warning('ZoomService meetingInstances failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * الملخص الكامل للاجتماع عبر **كل** انعقاداته مدموجاً زمنياً — كما ورد من Zoom حرفياً.
     *
     * الجلسة المنقطعة المعاد دخولها تصير عدّة انعقادات بعدّة ملخصات، وسحب الأخير وحده
     * كان يعرض ملخص إعادة الدخول (ثوانٍ من التحيات) ويُسقط ملخص النقاش الفعلي —
     * فيبدو الملخص «محرَّفاً» عن بريد Zoom الذي يستلمه المضيف (حادثة M-26753).
     */
    public function fullMeetingSummary(string $meetingId, ?string $fallbackUuid = null): ?array
    {
        $instances = $this->meetingInstances($meetingId);

        // انعقاد واحد أو تعذّر جلب القائمة ⇒ المسار المفرد القائم
        if (count($instances) <= 1) {
            return $this->meetingSummary($meetingId, $instances[0]['uuid'] ?? $fallbackUuid);
        }

        $parts = [];
        foreach ($instances as $i) {
            $s = $this->meetingSummary('', $i['uuid']);
            if ($s !== null) {
                $parts[] = ['start_time' => $i['start_time'], 'summary' => $s];
            }
        }
        if ($parts === []) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0]['summary'];
        }

        // دمج حرفي بترتيب الانعقاد: كل جزء يُعنون بوقته المحلي، والنصوص كما وردت بلا تعديل
        $overview = '';
        $details = [];
        $steps = [];
        foreach ($parts as $n => $p) {
            $when = $p['start_time'] !== ''
                ? Carbon::parse($p['start_time'])->timezone(config('app.timezone'))->format('H:i')
                : '';
            $label = 'الجزء '.($n + 1).(count($parts) > 1 && $when !== '' ? " ({$when})" : '');
            if (trim($p['summary']['overview']) !== '') {
                $overview .= ($overview !== '' ? "\n\n" : '')."— {$label} —\n".trim($p['summary']['overview']);
            }
            foreach ($p['summary']['details'] as $d) {
                $details[] = $d;
            }
            foreach ($p['summary']['next_steps'] as $st) {
                $steps[] = $st;
            }
        }

        return [
            'uuid' => end($parts)['summary']['uuid'] ?? null,
            'overview' => $overview,
            'details' => $details,
            'next_steps' => array_values(array_unique($steps)),
        ];
    }

    /**
     * تفاصيل الجلسة عبر **كل** انعقاداتها مجمَّعة — الجلسة المنقطعة المعاد دخولها تصير
     * عدّة انعقادات، وقراءة الأخير وحده كانت تعرض «دخول أول مشارك» و«المدة الفعلية»
     * لإعادة الدخول (ثوانٍ) وتُسقط الجزء الفعلي (حادثة M-26753).
     * التجميع: أول دخول = أقدم بداية · آخر مغادرة = أحدث نهاية · المدة = مجموع الانعقادات ·
     * سجلّ الحضور مدموج · الروابط والرمز من أحدث انعقاد يحملها.
     */
    public function fullPastMeetingDetails(string $meetingId): ?array
    {
        $instances = $this->meetingInstances($meetingId);
        if (count($instances) <= 1) {
            return $this->fetchPastMeetingDetails($meetingId);
        }

        $agg = null;
        foreach ($instances as $i) {
            $d = $this->fetchPastMeetingDetails(urlencode(urlencode($i['uuid'])));
            if ($d === null) {
                continue;
            }
            if ($agg === null) {
                $agg = $d;

                continue;
            }
            if ($d['starts_at'] && (! $agg['starts_at'] || $d['starts_at']->lt($agg['starts_at']))) {
                $agg['starts_at'] = $d['starts_at'];
            }
            if ($d['ends_at'] && (! $agg['ends_at'] || $d['ends_at']->gt($agg['ends_at']))) {
                $agg['ends_at'] = $d['ends_at'];
            }
            $agg['duration_sec'] = (int) ($agg['duration_sec'] ?? 0) + (int) ($d['duration_sec'] ?? 0);
            $agg['participants_log'] = array_merge((array) ($agg['participants_log'] ?? []), (array) ($d['participants_log'] ?? []));
            foreach (['uuid', 'recording_url', 'share_url', 'audio_url', 'transcript_url', 'download_token'] as $k) {
                if (! empty($d[$k])) {
                    $agg[$k] = $d[$k];
                }
            }
        }

        return $agg;
    }

    public function meetingSummary(string $meetingId, ?string $uuid = null): ?array
    {
        if (! $this->isConfigured() || ($meetingId === '' && ! $uuid)) {
            return null;
        }

        WebTimeLimit::raise(60);

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            // الاستعلام أولاً بـ UUID المشفّر المزدوج إن وُجد (أضمن للاجتماعات الفورية/المنتهية)
            $identifiers = [];
            if ($uuid) {
                $identifiers[] = urlencode(urlencode($uuid));
            }
            if ($meetingId) {
                $identifiers[] = $meetingId;
            }

            foreach ($identifiers as $id) {
                $response = Http::withToken($token)
                    ->timeout(15)
                    ->get("https://api.zoom.us/v2/meetings/{$id}/meeting_summary");

                if ($response->successful()) {
                    $data = $response->json();

                    return [
                        'uuid' => $data['meeting_uuid'] ?? $uuid,
                        'overview' => (string) ($data['summary_overview'] ?? ''),
                        'details' => array_values(array_map(
                            fn ($d) => ['label' => (string) ($d['label'] ?? ''), 'summary' => (string) ($d['summary'] ?? '')],
                            (array) ($data['summary_details'] ?? []),
                        )),
                        'next_steps' => array_values(array_map('strval', (array) ($data['next_steps'] ?? []))),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ZoomService meetingSummary failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * جلب تفاصيل الجلسة المنتهية (المشاركون، البداية والنهاية، التسجيل المرئي والصوتي، ورابط المشاركة).
     *
     * @return array{
     *     uuid: ?string,
     *     starts_at: ?Carbon,
     *     ends_at: ?Carbon,
     *     duration_sec: ?int,
     *     participants_log: array,
     *     recording_url: ?string,
     *     share_url: ?string,
     *     audio_url: ?string,
     *     transcript_url: ?string,
     *     download_token: ?string
     * }|null
     */
    public function fetchPastMeetingDetails(string $meetingId): ?array
    {
        if (! $this->isConfigured() || $meetingId === '') {
            return null;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            $out = [
                'uuid' => null,
                'starts_at' => null,
                'ends_at' => null,
                'duration_sec' => null,
                'participants_log' => [],
                'recording_url' => null,
                'share_url' => null,
                'audio_url' => null,
                'transcript_url' => null,
                'download_token' => null,
            ];

            // 1. بيانات Past Meeting الأساسية
            $pastResp = Http::withToken($token)->timeout(15)
                ->get("https://api.zoom.us/v2/past_meetings/{$meetingId}");

            if ($pastResp->successful()) {
                $pastData = $pastResp->json();
                $out['uuid'] = $pastData['uuid'] ?? null;
                if (! empty($pastData['start_time'])) {
                    $out['starts_at'] = Carbon::parse($pastData['start_time'])->setTimezone(config('app.timezone'));
                }
                if (! empty($pastData['end_time'])) {
                    $out['ends_at'] = Carbon::parse($pastData['end_time'])->setTimezone(config('app.timezone'));
                }
                if ($out['starts_at'] && $out['ends_at']) {
                    $out['duration_sec'] = (int) abs($out['ends_at']->diffInSeconds($out['starts_at']));
                }
            }

            // 2. سجل الحضور المفصّل
            $partResp = Http::withToken($token)->timeout(15)
                ->get("https://api.zoom.us/v2/past_meetings/{$meetingId}/participants");

            if ($partResp->successful()) {
                $parts = $partResp->json('participants', []);
                $out['participants_log'] = array_map(fn ($p) => [
                    'name' => (string) ($p['name'] ?? ''),
                    'email' => (string) ($p['user_email'] ?? ''),
                    'join_time' => ! empty($p['join_time']) ? Carbon::parse($p['join_time'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
                    'leave_time' => ! empty($p['leave_time']) ? Carbon::parse($p['leave_time'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
                    'duration_sec' => (int) ($p['duration'] ?? 0),
                    'status' => (string) ($p['status'] ?? 'in_meeting'),
                ], $parts);
            }

            // 3. التسجيلات وسحابة Zoom
            $recResp = Http::withToken($token)->timeout(15)
                ->get("https://api.zoom.us/v2/meetings/{$meetingId}/recordings");

            if ($recResp->successful()) {
                $recData = $recResp->json();
                $out['share_url'] = $recData['share_url'] ?? null;
                // رمز التنزيل: download_access_token إن ورد، وإلا رمز S2S نفسه — السقوط السابق على
                // «password» (كلمة مرور الاجتماع) كان يجعل كل تنزيلات النصّ/التسجيل تفشل بصمت
                $out['download_token'] = $recData['download_access_token'] ?? $token;

                $files = (array) ($recData['recording_files'] ?? []);

                $videoFile = collect($files)->firstWhere('recording_type', 'shared_screen_with_speaker_view');
                if (! $videoFile) {
                    $videoFile = collect($files)->firstWhere('file_extension', 'MP4');
                }
                if ($videoFile) {
                    $out['recording_url'] = $videoFile['play_url'] ?? $videoFile['download_url'] ?? null;
                }

                $audioFile = collect($files)->firstWhere('recording_type', 'audio_only');
                if (! $audioFile) {
                    $audioFile = collect($files)->firstWhere('file_extension', 'M4A');
                }
                if ($audioFile) {
                    $out['audio_url'] = $audioFile['play_url'] ?? $audioFile['download_url'] ?? null;
                }

                $transcriptFile = collect($files)->firstWhere('recording_type', 'audio_transcript');
                if ($transcriptFile) {
                    $out['transcript_url'] = $transcriptFile['download_url'] ?? null;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('ZoomService fetchPastMeetingDetails failed: '.$e->getMessage());
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
