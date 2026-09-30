<?php

namespace Tests\Fixtures;

use App\Models\Invoice;
use App\Services\Payments\GatewayPayment;
use App\Services\Payments\PaymentGateway;
use App\Support\Finance\Money;
use Illuminate\Http\Request;

/**
 * **بوّابةٌ ثانية وهميّة** — دليل قابليّة الإضافة: تطبيقٌ للعقد وحده، بلا أيّ تعديلٍ في التسوية أو المتحكّمات
 * أو المجال. «ترى» الدفعات المسجّلة في `$payments` بدل نداء شبكة.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const NAME = 'fakepay';

    /** @var array<string, GatewayPayment> */
    public static array $payments = [];

    public static function pay(Invoice $invoice, string $paymentId, ?int $halalas = null): void
    {
        self::$payments[$paymentId] = new GatewayPayment(
            gateway: self::NAME,
            id: $paymentId,
            status: 'captured',
            isPaid: true,
            amountHalalas: $halalas ?? Money::halalas($invoice->amount),
            currency: Money::CURRENCY,
            gatewayInvoiceId: $invoice->gateway_ref,
            metadata: ['invoice_number' => $invoice->number],
            raw: ['id' => $paymentId],
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'بوّابة وهميّة';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function hostedUrlForInvoice(Invoice $invoice, string $callbackUrl): ?string
    {
        if ($invoice->paid) {
            return null;
        }
        $invoice->update(['gateway' => self::NAME, 'gateway_ref' => 'fk_'.$invoice->id]);

        return 'https://fakepay.test/checkout/fk_'.$invoice->id;
    }

    public function fetchPayment(string $paymentId): ?GatewayPayment
    {
        return self::$payments[$paymentId] ?? null;
    }

    public function paymentIdFromCallback(Request $request): string
    {
        return (string) $request->query('payment', '');
    }

    public function webhookConfigured(): bool
    {
        return true;
    }

    public function verifyWebhook(Request $request): bool
    {
        return hash_equals('fk_secret', (string) $request->header('X-Fake-Signature'));
    }

    public function paymentIdFromWebhook(Request $request): ?string
    {
        $id = $request->input('payment_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function auditFindings(bool $production): array
    {
        return [];
    }
}
