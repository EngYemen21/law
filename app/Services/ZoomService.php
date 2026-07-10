<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تكامل Zoom (Server-to-Server OAuth) — إنشاء اجتماعات الاستشارات المرئية تلقائياً.
 * بلا مفاتيح في .env يعمل النظام بالرابط الداخلي الاحتياطي (اختبارات حتمية بلا شبكة).
 */
class ZoomService
{
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
    public function createMeeting(string $topic, int $durationMinutes = 60, bool $confidential = false): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->token();
            if (! $token) {
                return null;
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post('https://api.zoom.us/v2/users/me/meetings', [
                    'topic' => $topic,
                    'type' => 2,
                    'duration' => $durationMinutes,
                    'timezone' => 'Asia/Riyadh',
                    'settings' => $this->settings($confidential),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'id' => (string) ($data['id'] ?? ''),
                    'join_url' => $data['join_url'] ?? null,
                    'start_url' => $data['start_url'] ?? null,
                ];
            }

            Log::warning('ZoomService createMeeting failed: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            Log::warning('ZoomService createMeeting failed: '.$e->getMessage());
        }

        return null;
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
        ];
    }

    /** رمز وصول Server-to-Server OAuth (يُخبّأ ~50 دقيقة). */
    private function token(): ?string
    {
        return Cache::remember('zoom.s2s.token', now()->addMinutes(50), function () {
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
}
