<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Finance\InvoiceDue;
use App\Support\Finance\InvoiceFactory;
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

        // تصحيح القناة عند التسعير (الطلب قد يصل بقناةٍ يغيّرها الاتّفاق مع العميل) — قبل إنشاء
        // الفاتورة كي يحمل وصفُها القناةَ المعتمدة. الكتالوج واحد: `Consult::CHANNELS`
        if (! empty($payload['channel']) && in_array($payload['channel'], Consult::CHANNELS, true)) {
            $entity->channel = $payload['channel'];
            if ($payload['channel'] === 'هاتفية' && empty($entity->phone) && $entity->user?->phone) {
                $entity->phone = $entity->user->phone;
            }
        }

        // الأساس والضريبة **كما سعّرهما المسعِّر في هذه الحمولة نفسها** لا كما تُحسب من الإعداد
        // ثانيةً — والفاتورة والاستشارة تحملان الرقم عينه (`InvoiceFactory::fromFrozen`).
        // والمهلة من الإعدادات (`InvoiceDue`) — التاريخ ونصّه من رقمٍ واحد
        $this->invoice = InvoiceFactory::fromFrozen((int) $payload['price'], (int) $payload['vat'], [
            'user_id' => $entity->user_id,
            'consult_id' => $entity->id,
            'description' => "استشارة {$entity->ref} — {$entity->channel}",
            ...InvoiceDue::consult(),
        ], $actor);
    }

    public function record(array $payload): array
    {
        return [
            'price' => $payload['price'] ?? null,
            'total' => $payload['total'] ?? null,
            'invoice' => $this->invoice?->number,
            'channel' => $payload['channel'] ?? null,
        ];
    }

    /** الفاتورة التي صدرت في هذا النداء — `null` قبل النداء أو إن رُفض. */
    public function invoice(): ?Invoice
    {
        return $this->invoice;
    }
}
