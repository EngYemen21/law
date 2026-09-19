<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\InvoiceNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * **تسعير الاستشارة وإصدار فاتورتها ⇐ «بانتظار السداد».** نقيضه `RepriceConsult`.
 *
 * يناديه `ConsultBooking::setPrice` بعد حساب الضريبة من الإعدادات. كان يكتب الحالة مباشرةً.
 * `from()` و`guard()` هما حارسا `setPrice` نفسيهما (يبقيان هناك أوّلاً برسائلهما): المنتظِرة
 * للتسعير وحدها، ولا فاتورةَ بصفر — فاتورةٌ بـ٠ ر.س تُعلّق الطلب بلا مخرج.
 *
 * الحمولة: `price` · `vat` · `total` (محسوبةٌ عند المنادي). الفاتورة الصادرة تُقرأ بعد النداء
 * من `invoice()`.
 *
 * @extends Transition<Consult>
 */
final class PriceConsult extends Transition
{
    private ?Invoice $invoice = null;

    public function name(): string
    {
        return 'consult.price';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingPricing->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingPayment->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'التسعير فعلٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        return (int) ($payload['price'] ?? 0) < 1
            ? 'أقلّ سعرٍ للاستشارة ريالٌ واحد — استعمل الإلغاء إن كانت بلا مقابل.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $total = (int) $payload['total'];

        $entity->logAudit($actor->name ?? 'النظام', 'التسعير', (string) $entity->total, (string) $total);
        $entity->price = (int) $payload['price'];
        $entity->vat = (int) $payload['vat'];
        $entity->total = $total;
        $entity->priced_at = now();

        $this->invoice = Invoice::create([
            'user_id' => $entity->user_id,
            'consult_id' => $entity->id,
            'number' => InvoiceNumber::next(),
            'description' => "استشارة {$entity->ref} — {$entity->channel}",
            'amount' => $total,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 3 أيام',
            'due_at' => now()->addDays(3)->toDateString(),
            'paid' => false,
        ]);
    }

    public function record(array $payload): array
    {
        return ['price' => $payload['price'] ?? null, 'total' => $payload['total'] ?? null, 'invoice' => $this->invoice?->number];
    }

    /** الفاتورة التي صدرت في هذا النداء — `null` قبل النداء أو إن رُفض. */
    public function invoice(): ?Invoice
    {
        return $this->invoice;
    }
}
