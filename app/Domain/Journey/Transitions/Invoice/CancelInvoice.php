<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إلغاء الفاتورة ⇐ «ملغاة»** (م٢).
 *
 * كان الإلغاء الوحيد في النظام يقع داخل `Consult/RepriceConsult` بتحديثٍ مباشر على الصفوف،
 * فلا سطرَ له في `journey_transitions` ولا تاريخَ إلغاءٍ على الفاتورة. وهنا الواقعةُ مسمّاةٌ
 * ومؤرَّخة (`cancelled_at`) — والتاريخ لازمٌ لأنّ الملغاة تخرج من الذمم ومن الصادر
 * (`Finance\RevenueSnapshot`)، فلا يُعرف بغيره **متى** خرجت.
 *
 * **ولا تُلغى مدفوعة:** ردُّ مالٍ قُبض استردادٌ (`RefundInvoice`) لا إلغاء — وإلغاؤها كان
 * يُخرج مبلغَها من الدخل بلا قيدٍ يقابله. والمعدومة أُسقطت مطالبتُها أصلاً فلا شيء يُلغى.
 *
 * @extends Transition<Invoice>
 */
final class CancelInvoice extends Transition
{
    public function name(): string
    {
        return 'invoice.cancel';
    }

    public function from(): array
    {
        return [
            InvoiceStatus::Draft->value,
            InvoiceStatus::Due->value,
            // **«بانتظار مراجعة الإثبات» مصدرٌ مقبول أيضاً** وإن لم تذكره الخطّة: إعادةُ تسعير
            // استشارةٍ (`Consult/RepriceConsult`) تلغي **كلّ** فاتورةٍ غير مدفوعة عليها، ومنها
            // فاتورةٌ رُفع لها إثبات بعد. وحصرُ المصادر بثلاثةٍ كان يجعل إعادةَ التسعير ترتدّ
            // ويبقى العميل مطالَباً بسعرٍ أُلغي.
            InvoiceStatus::ProofReview->value,
            InvoiceStatus::PartiallyPaid->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::Cancelled->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        return $entity->paid ? 'الفاتورة محصَّلة — ردُّ مالها استردادٌ لا إلغاء.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->cancelled_at = now();
        $entity->tone = 'b-red';
    }

    public function record(array $payload): array
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';

        return array_filter(['reason' => $reason !== '' ? $reason : null]);
    }
}
