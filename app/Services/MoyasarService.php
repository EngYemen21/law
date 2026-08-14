<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تكامل بوّابة الدفع Moyasar (ميسّر) — نظام الفواتير المستضاف (Invoices API).
 * بلا مفتاح سرّي في .env يبقى الدفع محاكى (اختبارات/تطوير محلي بلا شبكة).
 *
 * أمان: secret_key خادميّ فقط ولا يُسجَّل قط؛ لا تمرّ بيانات بطاقة بالخادم (الدفع على صفحة ميسّر).
 */
class MoyasarService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.moyasar.secret_key'));
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
