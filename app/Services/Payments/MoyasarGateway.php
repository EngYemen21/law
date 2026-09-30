<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Support\AppEnvironment;
use App\Support\Finance\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * بوّابة ميسّر (Moyasar) — نظام الفواتير المستضاف (Invoices API)، أوّل تطبيقٍ لعقد `PaymentGateway`.
 * بلا مفتاح سرّي في .env لا دفع (المتحكّمات تردّ 503) — لا محاكاة. وفي صندوق التجربة يُرفض المفتاح الحقيقيّ
 * (`sk_live_`) فلا تُخصم بطاقاتٌ حقيقيّة من التطوير؛ مفاتيح التجربة `sk_test_` هناك (فصل البيئات 2026-09-29).
 *
 * أمان: secret_key خادميّ فقط ولا يُسجَّل قط؛ لا تمرّ بيانات بطاقة بالخادم (الدفع على صفحة ميسّر).
 */
final class MoyasarGateway implements PaymentGateway
{
    public const NAME = 'moyasar';

    /** حالات فاتورة ميسّر المفتوحة — يُعاد استعمال رابطها بدل إنشاء فاتورةٍ مكرّرة. */
    private const OPEN_INVOICE_STATUSES = ['initiated', 'pending', ''];

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'ميسّر';
    }

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

        // يُعاد استعمال فاتورة البوّابة المفتوحة **بمبلغها الحاليّ فقط**: فتحُ خطّة تقسيطٍ يصغّر مبلغ الفاتورة،
        // فكان العميل يُوجَّه إلى رابط المبلغ الكامل القديم، فيُخصم كاملاً ولا يُسوّى (مراجعة الدفع 2026-09-30).
        if (! empty($invoice->gateway_ref) && in_array($invoice->gateway, [null, self::NAME], true)) {
            $existing = $this->findInvoice((string) $invoice->gateway_ref);

            // **دُفعت لدى البوّابة ولم يصل إشعارها بعد** (يدفع العميل ثمّ يعود فيضغط الدفع ثانيةً): كانت تُنشأ
            // فاتورةُ بوّابةٍ جديدة فيُخصم مرّتين. الآن يُعاد إلى صفحة العودة بدفعته فتُسوّى — لا رابطَ جديد.
            if ($existing !== null && $existing['paidPaymentId'] !== null) {
                return $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').'id='.urlencode($existing['paidPaymentId']);
            }

            if ($existing !== null
                && in_array($existing['status'], self::OPEN_INVOICE_STATUSES, true)
                && $existing['amount'] === Money::halalas($invoice->amount)) {
                return $existing['url'];
            }
        }

        $created = $this->createInvoice($invoice, $callbackUrl);
        if ($created === null) {
            return null;
        }

        $invoice->update(['gateway' => self::NAME, 'gateway_ref' => $created['id']]);

        return $created['url'];
    }

    public function fetchPayment(string $paymentId): ?GatewayPayment
    {
        if (! $this->isConfigured() || $paymentId === '') {
            return null;
        }

        try {
            $response = $this->http()->get($this->base().'/payments/'.urlencode($paymentId));

            if ($response->successful() && is_array($response->json())) {
                return self::toGatewayPayment($response->json());
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
     * كائن دفعة ميسّر الخامّ ← الشكل الموحّد. ميسّر تردّ المبلغ بالهللة، وحالة `paid` وحدها تعني وصول المال.
     *
     * @param  array<string, mixed>  $data
     */
    public static function toGatewayPayment(array $data): GatewayPayment
    {
        $metadata = $data['metadata'] ?? [];
        $invoiceId = (string) ($data['invoice_id'] ?? '');

        return new GatewayPayment(
            gateway: self::NAME,
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? 'unknown'),
            isPaid: ($data['status'] ?? null) === 'paid',
            amountHalalas: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? Money::CURRENCY),
            gatewayInvoiceId: $invoiceId !== '' ? $invoiceId : null,
            metadata: is_array($metadata) ? $metadata : [],
            raw: $data,
        );
    }

    public function paymentIdFromCallback(Request $request): string
    {
        return (string) $request->query('id', '');
    }

    public function webhookConfigured(): bool
    {
        return $this->webhookSecret() !== null;
    }

    /**
     * يُرسل ميسّر حقل `secret_token` في جسم الحدث (أو في ترويسة) — يُطابَق بالسرّ المضبوط بمقارنةٍ ثابتة الزمن.
     * لا يُقبل من سلسلة الاستعلام: السرّ في العنوان يُسجَّل في access.log وأيّ بروكسي وسيط.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === null) {
            return false;
        }

        $body = $this->webhookBody($request);
        $token = (string) (
            $request->input('secret_token')
            ?: ($body['secret_token'] ?? '')
            ?: $request->header('X-Moyasar-Secret-Token')
            ?: $request->header('X-Secret-Token')
            ?: $request->header('secret-token')
            ?: $request->bearerToken()
        );

        return hash_equals($secret, $token);
    }

    /**
     * إشعار لوحة ميسّر: `data` هو **كائن الدفعة** (أحداثها كلّها أحداث دفع). كان هنا فرعٌ لمعرّفٍ يبدأ بـ`inv_`
     * — ومعرّفات ميسّر UUID فلم يتحقّق قطّ؛ وإشعار الفاتورة له مستقبِله (`paymentIdFromInvoiceNotification`).
     */
    public function paymentIdFromWebhook(Request $request): ?string
    {
        $data = $request->input('data') ?: ($this->webhookBody($request)['data'] ?? []);
        $id = (string) (data_get($data, 'id') ?: '');

        return $id !== '' ? $id : null;
    }

    /**
     * `callback_url` الفاتورة: ميسّر ترسل إليه **كائن الفاتورة** حين تُدفع — إشعارٌ خادميّ لا تحويلُ متصفّح (توثيق
     * Create Invoice). بلا `secret_token` فيه، فلا يُوثق إلّا بمعرّفه: تُعاد جلب الفاتورة بالمفتاح السرّيّ ودفعتُها
     * المدفوعة منها، فطلبٌ مزوَّر لا يرى إلّا ما تراه ميسّر فعلاً.
     */
    public function paymentIdFromInvoiceNotification(Request $request): ?string
    {
        $id = (string) ($request->input('id') ?: ($this->webhookBody($request)['id'] ?? ''));
        $invoice = $id !== '' ? $this->findInvoice($id) : null;

        return $invoice['paidPaymentId'] ?? null;
    }

    public function auditFindings(bool $production): array
    {
        $secret = (string) config('services.moyasar.secret_key');
        $publishable = (string) config('services.moyasar.publishable_key');
        $out = [];

        if (! $production) {
            foreach (['MOYASAR_SECRET_KEY' => $secret, 'MOYASAR_PUBLISHABLE_KEY' => $publishable] as $key => $value) {
                if (self::isLiveKey($value)) {
                    $out[] = ['level' => 'fail', 'key' => $key, 'message' => 'مفتاح ميسّر حقيقيّ (live) في بيئة تجربة — ضع مفتاح sk_test_/pk_test_. الدفع معطّلٌ هنا حتى ذلك.'];
                }
            }

            return $out;
        }

        if (self::isTestKey($secret) || self::isTestKey($publishable)) {
            $out[] = ['level' => 'fail', 'key' => 'MOYASAR_SECRET_KEY', 'message' => 'مفتاح ميسّر تجريبيّ (sk_test_/pk_test_) في الإنتاج — لا يُحصَّل مالٌ حقيقيّ.'];
        }
        if ($secret !== '' && ! $this->webhookConfigured()) {
            $out[] = ['level' => 'fail', 'key' => 'MOYASAR_WEBHOOK_SECRET', 'message' => 'سرّ إشعارات ميسّر فارغ — لا تُسوّى الفواتير المدفوعة آليّاً.'];
        }

        return $out;
    }

    /**
     * ينشئ فاتورة ميسّر مستضافة ويعيد [id, url] أو null عند التعذّر. المبلغ بالهللة، وmetadata للمطابقة
     * عند العودة/الإشعار.
     *
     * @return array{id: string, url: string}|null
     */
    private function createInvoice(Invoice $invoice, string $callbackUrl): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->http()->post($this->base().'/invoices', [
                'amount' => Money::halalas($invoice->amount),
                'currency' => Money::CURRENCY,
                'description' => (string) $invoice->description,
                // `callback_url` إشعارٌ خادميّ من ميسّر حين تُدفع الفاتورة — لا تحويلُ المتصفّح (كان رابطَ عودة العميل
                // نفسه: مسار GET يتطلّب الدخول، فيُرفض الإشعار وتُسقطه ميسّر بعد خمس محاولات). والعميل يعود بـ`success_url`.
                'callback_url' => route('webhooks.payment.invoice', self::NAME),
                'back_url' => $callbackUrl,
                'success_url' => $callbackUrl,
                'metadata' => [
                    'invoice_number' => (string) $invoice->number,
                    'consult_id' => (string) $invoice->consult_id,
                ],
            ]);

            $data = $response->json();
            if ($response->successful() && ! empty($data['id']) && ! empty($data['url'])) {
                return ['id' => (string) $data['id'], 'url' => (string) $data['url']];
            }

            // تسجيل آمن: الحالة ورسالة الخطأ فقط — لا جسم كامل ولا مفاتيح
            Log::warning('moyasar.createInvoice.failed', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
                'message' => $this->errorMessage($data),
            ]);
        } catch (\Throwable $e) {
            Log::warning('moyasar.createInvoice.exception', ['invoice' => $invoice->number, 'message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * فاتورة ميسّر بمعرّفها — لإعادة استعمال رابطها بدل إنشاء نسخة مكرّرة.
     *
     * @return array{id: string, url: string, status: string, amount: int, paidPaymentId: ?string}|null
     */
    private function findInvoice(string $invoiceId): ?array
    {
        if (! $this->isConfigured() || $invoiceId === '') {
            return null;
        }

        try {
            $response = $this->http()->get($this->base().'/invoices/'.urlencode($invoiceId));
            $data = $response->json();

            if ($response->successful() && ! empty($data['id']) && ! empty($data['url'])) {
                $paid = collect(is_array($data['payments'] ?? null) ? $data['payments'] : [])
                    ->first(fn ($p) => is_array($p) && ($p['status'] ?? null) === 'paid' && ! empty($p['id']));

                return [
                    'id' => (string) $data['id'], 'url' => (string) $data['url'],
                    'status' => (string) ($data['status'] ?? ''), 'amount' => (int) ($data['amount'] ?? 0),
                    'paidPaymentId' => $paid !== null ? (string) $paid['id'] : null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('moyasar.getInvoice.exception', ['invoice_id' => $invoiceId, 'message' => $e->getMessage()]);
        }

        return null;
    }

    /** عميل HTTP واحد لكلّ نداءات ميسّر: المفتاح السرّيّ مصادقةً، ومهلة، وإعادة محاولةٍ عند انقطاع الاتّصال وحده. */
    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.moyasar.secret_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function base(): string
    {
        return rtrim((string) config('services.moyasar.base_url', 'https://api.moyasar.com/v1'), '/');
    }

    private function webhookSecret(): ?string
    {
        $secret = config('services.moyasar.webhook_secret');

        return ($secret !== null && $secret !== '') ? (string) $secret : null;
    }

    /** @return array<string, mixed> */
    private function webhookBody(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
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
