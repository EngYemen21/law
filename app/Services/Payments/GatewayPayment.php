<?php

namespace App\Services\Payments;

/**
 * **دفعةٌ كما تراها المنصّة، أيّاً كانت البوّابة.** كلّ بوّابة تحوّل ردّها الخامّ إلى هذا الشكل
 * (`PaymentGateway::fetchPayment`)، فلا يعرف `PaymentReconciler` ولا المتحكّمات شكلَ بيانات أيّ بوّابة.
 */
final readonly class GatewayPayment
{
    /**
     * @param  string  $gateway  اسم البوّابة في الإعداد (`moyasar`) — يُكتب في الدفتر
     * @param  string  $status  الحالة كما ردّتها البوّابة — تُحفظ في الدفتر للتدقيق
     * @param  bool  $isPaid  المال وصل فعلاً — الحكم للبوّابة لا لنصّ الحالة
     * @param  string|null  $gatewayInvoiceId  مرجع فاتورة البوّابة (= `invoices.gateway_ref`)
     * @param  array<string, mixed>  $metadata  ما أرسلته المنصّة مع الفاتورة وعاد مع الدفعة (رقم الفاتورة …)
     * @param  array<string, mixed>  $raw  الردّ الخامّ — لقطة الدفتر
     * @param  bool  $isFailed  رفضتها البوّابة نهائيّاً (بطاقة مرفوضة…) — لم يُخصم شيء
     */
    public function __construct(
        public string $gateway,
        public string $id,
        public string $status,
        public bool $isPaid,
        public int $amountHalalas,
        public string $currency,
        public ?string $gatewayInvoiceId,
        public array $metadata,
        public array $raw,
        public bool $isFailed = false,
    ) {}

    public function invoiceNumber(): ?string
    {
        $number = $this->metadata['invoice_number'] ?? null;

        return is_scalar($number) && (string) $number !== '' ? (string) $number : null;
    }

    public function consultId(): ?int
    {
        $id = $this->metadata['consult_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
