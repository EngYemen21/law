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

    /** سابقة المرجع المحاكى — تُميّزه عمّا يصدر عن جهةٍ حقيقيّة. */
    public const SIMULATED_PREFIX = 'محاكاة';

    /**
     * إرسال المخاطبة للجهة عبر النظام الخارجيّ — يعيد `['ref'=>…, 'status'=>…]`
     * أو `null` حين يتعذّر الإرسال فعلاً.
     *
     * ⚠️ **فشل النداء الحقيقيّ لا يُنتج مرجعاً.** كان `catch` ثم `return $simulated`:
     * تُضبط قناة المخاطبة على «النظام الخارجيّ»، ويُخزَّن مرجعٌ مُختلَق، ويُشعَر العميل
     * بأن مخاطبته «أُرسلت إلى محكمة التنفيذ» — ولم تُرسل. أي أن العطل الشبكيّ يتحوّل
     * إلى إثبات إرسالٍ في سجلّ الملفّ، وهو أسوأ من الفشل الظاهر بمراحل.
     *
     * والمحاكاة **بلا مفاتيح** تبقى (وضع تطوير معلَن في توثيق الصنف)، لكن مرجعها
     * يُوسم بسابقة `محاكاة-` فلا يُقرأ رقماً صادراً عن جهة.
     */
    public function send(Correspondence $corr): ?array
    {
        if (! $this->isConfigured()) {
            return [
                'ref' => self::SIMULATED_PREFIX.'-'.$this->refPrefix().'-'.now()->year.'-'.random_int(10000, 99999),
                'status' => CorrFlow::EXT_STAGES[0],
            ];
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

        return null;
    }

    /**
     * حالة النظام الخارجيّ.
     *
     * ⚠️ **تعثّر النداء الحقيقيّ لا يُقدّم المرحلة.** كان `catch` ثم `return $next`:
     * فينقلب انقطاعُ الشبكة تقدّماً في ملفّ العميل، وقد يبلغ «صدر الرد من الجهة»
     * فيُشعَر العميل بردٍّ لم يصدر. والصادق أن تبقى الحالة كما هي حتى تُجيب الجهة.
     *
     * والمحاكاة **بلا مفاتيح** تبقى (وضع تطوير معلَن): تتقدّم خطوةً في كل مزامنة.
     */
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
            Log::warning('external_corr.status.failed', ['corr' => $corr->number, 'status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::warning('external_corr.status.exception', ['corr' => $corr->number, 'message' => $e->getMessage()]);
        }

        // تعثّر النداء ⇒ لا تقدّم: الحالة تبقى على ما هي
        return $current !== '' ? $current : CorrFlow::EXT_STAGES[0];
    }

    /**
     * نصّ ردّ الجهة — أو `null` حين يتعذّر جلبه فعلاً (نظير `send`).
     *
     * ⚠️ **العطل لا يُنتج ردّ جهةٍ حكوميّة.** كان `catch` ثم `return $simulated`،
     * والنصّ المُختلَق: «تفيدكم {الجهة} **بالموافقة على الإجراء المطلوب**». فيُخزَّن
     * في `reply_body`، وتُضبط الحالة «صدر الرد من الجهة»، ويُشعَر العميل، ويُطبع
     * في PDF بعنوان «ردّ الجهة» داخل «إفادة العميل عن المخاطبة الرسميّة» — أي أن
     * انقطاع الشبكة يتحوّل إلى **موافقة حكوميّة موثَّقة** في ملفّ العميل.
     *
     * و`send` أُصلح بهذا المبدأ نفسه ووُسم مرجعُه المحاكى بسابقة «محاكاة»، وبقي
     * هذا وحده يختلق. والمحاكاة بلا مفاتيح تبقى، لكنها **تُعلن نفسها في النصّ**.
     */
    public function reply(Correspondence $corr): ?string
    {
        if (! $this->isConfigured() || ! $corr->ext_ref) {
            return '['.self::SIMULATED_PREFIX.' — لا ردّ حقيقيّ من الجهة؛ النظام الخارجيّ غير مُهيّأ] '
                .'بالإشارة إلى مخاطبتكم رقم '.($corr->ext_ref ?: $corr->number).'.';
        }

        try {
            $res = $this->client()->get($this->endpoint('reply', $corr->ext_ref));
            if ($res->successful() && ($body = $res->json('replyBody'))) {
                return (string) $body;
            }
            Log::warning('external_corr.reply.failed', ['corr' => $corr->number, 'status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::warning('external_corr.reply.exception', ['corr' => $corr->number, 'message' => $e->getMessage()]);
        }

        return null;
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
