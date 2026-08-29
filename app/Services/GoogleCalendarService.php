<?php

namespace App\Services;

use App\Models\Consult;
use App\Models\Meeting;
use App\Support\MeetingTime;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * خدمة Google Calendar السحابية المباشرة (Service Account REST API v3).
 *
 * تتولى المزامنة اللحظية الحقيقية مع خوادم Google دون أي إعادة توجيه للعميل.
 */
class GoogleCalendarService
{
    /** المسار الافتراضيّ لملف الاعتماد — يُتجاوَز بـ`services.google_calendar.credentials_path`. */
    private const CREDENTIALS_PATH = 'storage/app/google-credentials.json';

    /** المسار المطلق لملف الاعتماد، أو `null` حين تُفرَّغ التهيئة (الاختبارات). */
    private static function credentialsPath(): ?string
    {
        $configured = (string) config('services.google_calendar.credentials_path', self::CREDENTIALS_PATH);

        return $configured === '' ? null : base_path($configured);
    }

    private const TOKEN_CACHE_KEY = 'google_service_account_access_token';

    private const TOKEN_CACHE_SECONDS = 3300; // 55 دقيقة (جوجل تمنح 60 دقيقة)

    // النداءات تجري داخل دورة طلب الحجز المتزامنة — مهلة قصيرة كي لا يعلّق بطء جوجل حجز العميل
    private const HTTP_TIMEOUT_SECONDS = 5;

    /**
     * تقويم الوجهة: 'primary' لحساب الخدمة هو تقويمه الخاص الذي لا يفتحه أي إنسان —
     * يُضبط GOOGLE_CALENDAR_ID على تقويم المكتب المُشارَك مع بريد حساب الخدمة (صلاحية تعديل).
     */
    private static function defaultCalendarId(): string
    {
        return (string) (config('services.google_calendar.calendar_id') ?: 'primary');
    }

    /**
     * هل ملف بيانات الاعتماد موجود وصالح؟
     */
    public static function isConfigured(): bool
    {
        $path = self::credentialsPath();

        return $path !== null && file_exists($path) && is_readable($path);
    }

    /**
     * استخراج وتوليد Google OAuth Access Token الموثوق عبر JWT RSA256
     */
    public static function getAccessToken(): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_SECONDS, function () {
            try {
                $path = (string) self::credentialsPath();
                $creds = json_decode(file_get_contents($path), true);

                if (empty($creds['client_email']) || empty($creds['private_key'])) {
                    Log::error('[GoogleCalendarService] ملف الاعتماد يفتقر للبيانات الأساسية.');

                    return null;
                }

                $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
                $now = time();
                $claims = self::base64UrlEncode(json_encode([
                    'iss' => $creds['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/calendar',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'exp' => $now + 3600,
                    'iat' => $now,
                ]));

                $binarySignature = '';
                $privateKey = openssl_pkey_get_private($creds['private_key']);
                if (! $privateKey) {
                    Log::error('[GoogleCalendarService] مفتاح RSA الخاص غير صالح.');

                    return null;
                }

                openssl_sign("{$header}.{$claims}", $binarySignature, $privateKey, OPENSSL_ALGO_SHA256);
                $jwt = "{$header}.{$claims}.".self::base64UrlEncode($binarySignature);

                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('[GoogleCalendarService] فشل تبادل التوكن مع جوجل: '.$response->body());

                return null;
            } catch (\Throwable $e) {
                Log::error('[GoogleCalendarService] خطأ أثناء إنشاء التوكن: '.$e->getMessage());

                return null;
            }
        });
    }

    /**
     * إنشاء حدث جديد في تقويم جوجل السحابي
     */
    public static function createEvent(
        string $title,
        string $description,
        DateTimeInterface|CarbonInterface|string $startsAt,
        int $durationMinutes = 45,
        ?string $location = null,
        array $attendeeEmails = [],
        ?string $calendarId = null
    ): ?array {
        $token = self::getAccessToken();
        if (! $token) {
            return null;
        }

        $calendarId = $calendarId ?: self::defaultCalendarId();
        $startCarbon = Carbon::parse($startsAt)->utc();
        $endCarbon = $startCarbon->copy()->addMinutes(max(15, $durationMinutes));

        $attendees = [];
        foreach (array_filter($attendeeEmails) as $email) {
            $attendees[] = ['email' => $email];
        }

        $payload = [
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $startCarbon->toIso8601String(),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $endCarbon->toIso8601String(),
                'timeZone' => 'UTC',
            ],
            'location' => $location ?: 'غرفة الاجتماعات الرقمية بالمنصة',
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 15],
                    ['method' => 'popup', 'minutes' => 60],
                ],
            ],
        ];

        if (! empty($attendees)) {
            $payload['attendees'] = $attendees;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->withToken($token)
                ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?sendUpdates=all", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            // حساب خدمة بلا Domain-Wide Delegation ترفض جوجل دعوته للحضور (403) —
            // نعيد المحاولة بلا attendees كي لا يضيع الحدث كله بسبب الدعوات
            if (! empty($attendees)) {
                Log::warning('[GoogleCalendarService] فشل الإنشاء مع الحضور، إعادة محاولة بدونهم: '.$response->body());
                unset($payload['attendees']);
                $retry = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->withToken($token)
                    ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", $payload);

                if ($retry->successful()) {
                    return $retry->json();
                }
            }

            Log::warning('[GoogleCalendarService] فشل إنشاء الحدث: '.$response->body());

            return null;
        } catch (\Throwable $e) {
            Log::error('[GoogleCalendarService] خطأ في إنشاء حدث تقويم جوجل: '.$e->getMessage());

            return null;
        }
    }

    /**
     * تحديث موعد أو تفاصيل حدث موجود مسبقاً
     */
    public static function updateEvent(
        string $eventId,
        string $title,
        string $description,
        DateTimeInterface|CarbonInterface|string $startsAt,
        int $durationMinutes = 45,
        ?string $location = null,
        array $attendeeEmails = [],
        ?string $calendarId = null
    ): ?array {
        $token = self::getAccessToken();
        if (! $token || ! $eventId) {
            return null;
        }

        $calendarId = $calendarId ?: self::defaultCalendarId();
        $startCarbon = Carbon::parse($startsAt)->utc();
        $endCarbon = $startCarbon->copy()->addMinutes(max(15, $durationMinutes));

        $attendees = [];
        foreach (array_filter($attendeeEmails) as $email) {
            $attendees[] = ['email' => $email];
        }

        $payload = [
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $startCarbon->toIso8601String(),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $endCarbon->toIso8601String(),
                'timeZone' => 'UTC',
            ],
            'location' => $location ?: 'غرفة الاجتماعات الرقمية بالمنصة',
        ];

        if (! empty($attendees)) {
            $payload['attendees'] = $attendees;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->withToken($token)
                ->patch("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}?sendUpdates=all", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            // نفس fallback الإنشاء: التحديث مع حضورٍ قد يرفضه حساب خدمة بلا تفويض نطاق
            if (! empty($attendees)) {
                Log::warning('[GoogleCalendarService] فشل التحديث مع الحضور، إعادة محاولة بدونهم: '.$response->body());
                unset($payload['attendees']);
                $retry = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->withToken($token)
                    ->patch("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}", $payload);

                if ($retry->successful()) {
                    return $retry->json();
                }
            }

            Log::warning('[GoogleCalendarService] فشل تحديث الحدث: '.$response->body());

            return null;
        } catch (\Throwable $e) {
            Log::error('[GoogleCalendarService] خطأ في تحديث حدث تقويم جوجل: '.$e->getMessage());

            return null;
        }
    }

    /**
     * حذف حدث من تقويم جوجل
     */
    public static function deleteEvent(string $eventId, ?string $calendarId = null): bool
    {
        $token = self::getAccessToken();
        if (! $token || ! $eventId) {
            return false;
        }

        $calendarId = $calendarId ?: self::defaultCalendarId();

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->withToken($token)
                ->delete("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}?sendUpdates=all");

            return $response->successful() || $response->status() === 404 || $response->status() === 410;
        } catch (\Throwable $e) {
            Log::error('[GoogleCalendarService] خطأ في حذف حدث تقويم جوجل: '.$e->getMessage());

            return false;
        }
    }

    /**
     * مزامنة استشارة قانونية (إنشاء أو تحديث تلقائي)
     */
    public static function syncConsult(Consult $consult): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        $startsAt = $consult->starts_at ?: MeetingTime::parse($consult->day ?? '', $consult->time ?? '');
        if (! $startsAt) {
            return null;
        }

        $title = "استشارة قانونية: {$consult->subject} ({$consult->ref})";
        $joinLink = $consult->slink ?: url("/consults/{$consult->id}");
        $description = "استشارة قانونية ({$consult->channel})\nالرقم المرجعي: {$consult->ref}\nالمستشار: {$consult->lawyer}\nرابط الدخول: {$joinLink}";
        $duration = (int) ($consult->duration_min ?: 45);
        $location = $consult->channel === 'حضورية' ? ($consult->place ?: 'مقر مكتب المحاماة') : $joinLink;

        $attendees = array_filter([
            $consult->user?->email,
            $consult->assignedLawyer?->email,
        ]);

        if ($consult->google_event_id) {
            $updated = self::updateEvent($consult->google_event_id, $title, $description, $startsAt, $duration, $location, $attendees);
            if ($updated && isset($updated['id'])) {
                return $updated['id'];
            }
        }

        $created = self::createEvent($title, $description, $startsAt, $duration, $location, $attendees);
        if ($created && isset($created['id'])) {
            $consult->updateQuietly(['google_event_id' => $created['id']]);

            return $created['id'];
        }

        return null;
    }

    /**
     * مزامنة اجتماع (إنشاء أو تحديث تلقائي)
     */
    public static function syncMeeting(Meeting $meeting): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        $startsAt = $meeting->starts_at ?: now();
        $title = "اجتماع رسمي: {$meeting->title} ({$meeting->ref})";
        $joinLink = $meeting->slink ?: url("/meeting?id={$meeting->ref}");
        $description = "اجتماع رسمي عبر المنصة\nالرقم المرجعي: {$meeting->ref}\nرابط الاجتماع: {$joinLink}";
        $duration = 60;
        $location = $joinLink;

        $attendees = array_filter([
            $meeting->user?->email,
            $meeting->assignedLawyer?->email,
        ]);

        if ($meeting->google_event_id) {
            $updated = self::updateEvent($meeting->google_event_id, $title, $description, $startsAt, $duration, $location, $attendees);
            if ($updated && isset($updated['id'])) {
                return $updated['id'];
            }
        }

        $created = self::createEvent($title, $description, $startsAt, $duration, $location, $attendees);
        if ($created && isset($created['id'])) {
            $meeting->updateQuietly(['google_event_id' => $created['id']]);

            return $created['id'];
        }

        return null;
    }

    /**
     * حذف موعد استشارة ملغاة من تقويم جوجل
     */
    public static function deleteConsultEvent(Consult $consult): bool
    {
        if ($consult->google_event_id) {
            $ok = self::deleteEvent($consult->google_event_id);
            $consult->updateQuietly(['google_event_id' => null]);

            return $ok;
        }

        return true;
    }

    /**
     * حذف موعد اجتماع ملغى من تقويم جوجل
     */
    public static function deleteMeetingEvent(Meeting $meeting): bool
    {
        if ($meeting->google_event_id) {
            $ok = self::deleteEvent($meeting->google_event_id);
            $meeting->updateQuietly(['google_event_id' => null]);

            return $ok;
        }

        return true;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
