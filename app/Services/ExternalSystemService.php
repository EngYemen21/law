<?php

namespace App\Services;

use App\Models\Correspondence;
use App\Support\CorrFlow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * محوّل «النظام الخارجيّ» لإرسال المخاطبات الرسميّة للجهات/المحاكم ومتابعة حالتها والردّ.
 * نظير MoyasarService: يعمل **محاكاةً** بلا مفاتيح (يولّد مرجعاً وينقل الحالة ونصّ ردّ قالبيّ)،
 * وكود النداء الحقيقيّ جاهز (يُفعَّل بضبط services.external_corr في .env). أفضل-جهد: يسجّل ولا يرمي.
 */
class ExternalSystemService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.external_corr.base_url')) && ! empty(config('services.external_corr.api_key'));
    }

    /** إرسال المخاطبة للجهة عبر النظام الخارجيّ — يعيد ['ref'=>..., 'status'=>...]. */
    public function send(Correspondence $corr): array
    {
        $simulated = ['ref' => $this->refPrefix().'-'.now()->year.'-'.random_int(10000, 99999), 'status' => CorrFlow::EXT_STAGES[0]];

        if (! $this->isConfigured()) {
            return $simulated;
        }

        try {
            $res = $this->client()->post($this->endpoint('send'), [
                'subject' => $corr->subject, 'body' => $corr->body, 'entity' => $corr->entity,
                'ref' => $corr->number, 'client_code' => 'CL-'.str_pad((string) $corr->user_id, 6, '0', STR_PAD_LEFT),
            ]);
            if ($res->successful() && ($ref = $res->json('referenceNumber'))) {
                return ['ref' => (string) $ref, 'status' => CorrFlow::EXT_STAGES[0]];
            }
            Log::warning('external_corr.send.failed', ['corr' => $corr->number, 'status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::warning('external_corr.send.exception', ['corr' => $corr->number, 'message' => $e->getMessage()]);
        }

        return $simulated;
    }

    /** يقدّم حالة النظام الخارجيّ خطوةً (محاكاة: التالية في EXT_STAGES). */
    public function status(Correspondence $corr): string
    {
        $current = (string) ($corr->ext_status ?? '');
        $i = array_search($current, CorrFlow::EXT_STAGES, true);
        $next = ($i === false) ? CorrFlow::EXT_STAGES[0] : CorrFlow::EXT_STAGES[min($i + 1, count(CorrFlow::EXT_STAGES) - 1)];

        if (! $this->isConfigured() || ! $corr->ext_ref) {
            return $next;
        }

        try {
            $res = $this->client()->get($this->endpoint('status', $corr->ext_ref));
            if ($res->successful() && ($s = $res->json('status'))) {
                return (string) $s;
            }
        } catch (\Throwable $e) {
            Log::warning('external_corr.status.exception', ['corr' => $corr->number, 'message' => $e->getMessage()]);
        }

        return $next;
    }

    /** يجلب نصّ ردّ الجهة (محاكاة: نصّ قالبيّ رسميّ). */
    public function reply(Correspondence $corr): string
    {
        $simulated = 'بالإشارة إلى مخاطبتكم رقم '.($corr->ext_ref ?: $corr->number).'، تفيدكم '.$corr->entity
            .' بالموافقة على الإجراء المطلوب واستكمال متطلّباته وفق الأنظمة.';

        if (! $this->isConfigured() || ! $corr->ext_ref) {
            return $simulated;
        }

        try {
            $res = $this->client()->get($this->endpoint('reply', $corr->ext_ref));
            if ($res->successful() && ($body = $res->json('replyBody'))) {
                return (string) $body;
            }
        } catch (\Throwable $e) {
            Log::warning('external_corr.reply.exception', ['corr' => $corr->number, 'message' => $e->getMessage()]);
        }

        return $simulated;
    }

    private function client()
    {
        $key = (string) config('services.external_corr.api_key');
        $auth = (string) config('services.external_corr.auth_type', 'Bearer');

        return Http::withHeaders(['Authorization' => trim($auth.' '.$key)])
            ->acceptJson()->timeout(15)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function endpoint(string $key, string $ref = ''): string
    {
        $base = rtrim((string) config('services.external_corr.base_url'), '/');
        $paths = [
            'send' => (string) config('services.external_corr.ep_send', '/v1/correspondence/send'),
            'status' => (string) config('services.external_corr.ep_status', '/v1/correspondence/{ref}/status'),
            'reply' => (string) config('services.external_corr.ep_reply', '/v1/correspondence/{ref}/reply'),
        ];

        return $base.str_replace('{ref}', $ref, $paths[$key]);
    }

    private function refPrefix(): string
    {
        return (string) config('services.external_corr.ref_prefix', 'EXT');
    }
}
