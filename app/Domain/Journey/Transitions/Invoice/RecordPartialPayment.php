<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **سدادٌ جزئيّ ⇐ «مسدّدة جزئيّاً»** (م٢ — بنيةً، وم٤ تسجيلاً).
 *
 * `invoices.paid` قيمةٌ منطقيّة، والتقسيط اليوم يُحاكَى بتفتيت الفاتورة إلى فواتير أخوات.
 * فالموكّل الذي يدفع ٣٠٠٠ من ٥٠٠٠ لا موضع لمبلغه: الفاتورة «غير مدفوعة» بكاملها (ب٥).
 *
 * **ما يقع هنا اليوم:** الحالة وحدها تتحرّك، وتبقى الفاتورة قابلةً للسداد
 * (`InvoiceStatus::isPayable`) فبقيّتُها هي المطالبة القائمة.
 *
 * **وما يبقى لم٤ عمداً:** المبلغ المدفوع لا يُسجَّل على الفاتورة، لأنّ موضعه الصحيح صفٌّ في
 * `payments` بمبلغه وطريقته ومن قيَّده — لا عمودٌ جديد على `invoices` يصير مصدراً ثانياً
 * للحقيقة يخالف الدفتر. والحمولة `amount` تُحفظ في سجلّ الانتقال منذ اليوم فلا يضيع أثرها.
 *
 * @extends Transition<Invoice>
 */
final class RecordPartialPayment extends Transition
{
    public function name(): string
    {
        return 'invoice.partial_payment';
    }

    public function from(): array
    {
        return [InvoiceStatus::Due->value, InvoiceStatus::ProofReview->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::PartiallyPaid->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        if ($entity->paid) {
            return 'الفاتورة محصَّلة بالكامل — لا سدادَ جزئيّاً عليها.';
        }

        if (! array_key_exists('amount', $payload)) {
            return null; // `allowed()` يسأل بلا حمولة
        }

        $amount = (int) $payload['amount'];

        // **دون الريال لا سداد، وبكامل المبلغ ليس جزئيّاً**: الأوّل قيدٌ بلا مال، والثاني
        // تحصيلٌ كامل موضعُه `SettleInvoice` — ولو قُبل هنا لبقيت فاتورةٌ مسدَّدة بالكامل
        // «مسدّدة جزئيّاً» خارج الدخل وداخل الذمم.
        return match (true) {
            $amount < 1 => 'أقلّ سدادٍ جزئيّ ريالٌ واحد.',
            $amount >= (int) $entity->amount => 'المبلغ يغطّي الفاتورة كاملةً — هذا تحصيلٌ كامل لا سدادٌ جزئيّ.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->tone = 'b-amber';
    }

    public function record(array $payload): array
    {
        return array_filter(['amount' => isset($payload['amount']) ? (int) $payload['amount'] : null]);
    }
}
