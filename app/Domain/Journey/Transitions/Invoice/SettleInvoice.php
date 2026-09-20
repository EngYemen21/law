<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **سداد الفاتورة — الكاتب الوحيد لـ`paid` و`paid_at` و«مدفوعة»** (م٢).
 *
 * كان السداد يُكتب مباشرةً في أربعة مواضع: `PaymentReconciler::settleDomain` و
 * `CaseFee::markInvoicePaid` و`ExecFee::settleInvoice` و`Consult/SettlePayment` — وكلُّها
 * كانت تمرّ من فتحة `StateWriteGuard` التي تعفي «كلّ فاتورةٍ ليست فاتورة استشارة»، فسدادُ
 * فواتير القضايا والتنفيذ (أكبر مبالغ المكتب) **لا يُسجَّل في `journey_transitions`** أصلاً
 * (ع٣). والآن الكاتب واحد، فلا ينزلق `paid` عن `status` (ع٤) ولا يُكتب `paid_at` مرّتين.
 *
 * `paid_at` هنا لا في خطّافٍ منفصل: تاريخ السداد جزءٌ من واقعة السداد، وكتابتُه بعدها بخطوةٍ
 * تعني نافذةً تكون فيها الفاتورة مدفوعةً بلا تاريخ — أي خارج كلّ تقريرٍ بفترة.
 *
 * @extends Transition<Invoice>
 */
final class SettleInvoice extends Transition
{
    public function name(): string
    {
        return 'invoice.settle';
    }

    public function from(): array
    {
        return [
            InvoiceStatus::Due->value,
            InvoiceStatus::PartiallyPaid->value,
            InvoiceStatus::ProofReview->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::Paid->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        // شبكةُ أمانٍ بعد قفل الصفّ: `from()` يحرس العمود، وهذا يحرس ازدواجه القديم — صفٌّ
        // قديم بـ`paid = true` وحالةٍ متخلّفة لا يُحصَّل مرّةً ثانية.
        return $entity->paid ? self::ALREADY_PAID : null;
    }

    public const ALREADY_PAID = 'الفاتورة محصَّلة أصلاً — لا تُحصَّل مرّتين.';

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->paid = true;
        $entity->paid_at = now();
        $entity->tone = 'b-green';
    }

    public function record(array $payload): array
    {
        // قناةُ التحصيل (ميسّر · اسمُ من حصّل يدويّاً) — وهي ما يميّز القيدين عند المطابقة
        return array_filter(['channel' => isset($payload['channel']) && is_string($payload['channel']) ? $payload['channel'] : null]);
    }
}
