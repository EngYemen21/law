<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\AppEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تكامل بوّابة الدفع Moyasar (ميسّر) — نظام الفواتير المستضاف (Invoices API).
 * بلا مفتاح سرّي في .env لا دفع (المتحكّمات تردّ 503) — لا محاكاة. وفي صندوق التجربة يُرفض المفتاح الحقيقيّ
 * (`sk_live_`) فلا تُخصم بطاقاتٌ حقيقيّة من التطوير؛ مفاتيح التجربة `sk_test_` هناك (فصل البيئات 2026-09-29).
 *
 * أمان: secret_key خادميّ فقط ولا يُسجَّل قط؛ لا تمرّ بيانات بطاقة بالخادم (الدفع على صفحة ميسّر).
 */
class MoyasarService
{
    public function isConfigured(): bool
    {
        $key = (string) config('services.moyasar.secret_key');

        return $key !== '' && ! (AppEnvironment::isSandbox() && self::isLiveKey($key));
    }

    /** مفتاحٌ حقيقيّ يخصم بطاقات — ميسّر يميّز الوضع ببادئة المفتاح وحدها. */
    public static function isLiveKey(string $key): bool
    {
        return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'pk_live_');
    }

    /** مفتاحٌ تجريبيّ لا يُحصّل مالاً — لا مكان له في الإنتاج. */
    public static function isTestKey(string $key): bool
    {
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'pk_test_');
    }

    /**
     * ينشئ فاتورة ميسّر مستضافة للدفع ويعيد [id, url] أو null عند التعذّر.
     * المبلغ بالهللة (ريال × 100). العملة SAR. تُمرَّر metadata للمطابقة عند العودة/الـwebhook.
     *
     * @return array{id:string,url:string}|null
     */
    public function createInvoice(Invoice $invoice, string $callbackUrl): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withBasicAuth((string) config('services.moyasar.secret_key'), '')
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($this->base().'/invoices', [
                    'amount' => (int) round($invoice->amount * 100), // هللة
                    'currency' => 'SAR',
                    'description' => (string) $invoice->description,
                    'callback_url' => $callbackUrl,
                    'back_url' => $callbackUrl,
                    'success_url' => $callbackUrl,
                    'metadata' => [
                        'invoice_number' => (string) $invoice->number,
                        'consult_id' => (string) $invoice->consult_id,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $url = $data['url'] ?? null;
                $id = $data['id'] ?? null;
                if ($url && $id) {
                    return ['id' => (string) $id, 'url' => (string) $url];
                }
            }

            // تسجيل آمن: الحالة ورسالة الخطأ فقط — لا جسم كامل ولا مفاتيح
            Log::warning('moyasar.createInvoice.failed', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
                'message' => $this->errorMessage($response->json()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('moyasar.createInvoice.exception', ['invoice' => $invoice->number, 'message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * يجلب دفعةً من ميسّر بمعرّفها — للتحقّق الخادميّ عند العودة (لا يُوثَق بمعطيات الـURL).
     *
     * @return array<string,mixed>|null
     */
    public function fetchPayment(string $paymentId): ?array
    {
        if (! $this->isConfigured() || $paymentId === '') {
            return null;
        }

        try {
            $response = Http::withBasicAuth((string) config('services.moyasar.secret_key'), '')
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($this->base().'/payments/'.urlencode($paymentId));

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('moyasar.fetchPayment.failed', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
                'message' => $this->errorMessage($response->json()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('moyasar.fetchPayment.exception', ['payment_id' => $paymentId, 'message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * يجلب فاتورة ميسّر بمعرّفها (لإعادة استخدامها بدل إنشاء نسخة مكرّرة) — يعيد [id,url,status] أو null.
     *
     * @return array{id:string,url:string,status:string}|null
     */
    public function getInvoice(string $invoiceId): ?array
    {
        if (! $this->isConfigured() || $invoiceId === '') {
            return null;
        }

        try {
            $response = Http::withBasicAuth((string) config('services.moyasar.secret_key'), '')
                ->acceptJson()->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($this->base().'/invoices/'.urlencode($invoiceId));

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['id']) && ! empty($data['url'])) {
                    return ['id' => (string) $data['id'], 'url' => (string) $data['url'], 'status' => (string) ($data['status'] ?? '')];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('moyasar.getInvoice.exception', ['invoice_id' => $invoiceId, 'message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * رابط صفحة الدفع لفاتورة النظام — ينشئ فاتورة ميسّر بالرابط المعتمد الحالي ويخزّن معرّفها.
     */
    public function hostedUrlForInvoice(Invoice $invoice, string $callbackUrl): ?string
    {
        // فاتورة مدفوعة لا تُعاد إلى البوّابة إطلاقاً. كانت الحالة `paid` تسقط من قائمة
        // إعادة الاستعمال أدناه فيُنشأ **فاتورة بوّابة جديدة** ويُدهَس gateway_ref القديم —
        // أي خصم ثانٍ حقيقي على عميل سدّد فعلاً، بلا أي أثر للأولى.
        if ($invoice->paid) {
            Log::warning('moyasar.hosted_url.refused_paid_invoice', [
                'invoice' => $invoice->number,
                'gateway_ref' => $invoice->gateway_ref,
            ]);

            return null;
        }

        if (! empty($invoice->gateway_ref)) {
            $existing = $this->getInvoice((string) $invoice->gateway_ref);
            if ($existing !== null && in_array($existing['status'], ['initiated', 'pending', ''], true)) {
                return $existing['url'];
            }
        }

        $result = $this->createInvoice($invoice, $callbackUrl);
        if ($result === null) {
            return null;
        }

        $invoice->update(['gateway_ref' => $result['id']]);

        return $result['url'];
    }

    private function base(): string
    {
        return rtrim((string) config('services.moyasar.base_url', 'https://api.moyasar.com/v1'), '/');
    }

    /** يستخرج رسالة خطأ ميسّر بأمان (بلا بيانات حسّاسة). */
    private function errorMessage(mixed $json): string
    {
        if (is_array($json)) {
            return (string) ($json['message'] ?? $json['type'] ?? 'unknown');
        }

        return 'unknown';
    }
}
