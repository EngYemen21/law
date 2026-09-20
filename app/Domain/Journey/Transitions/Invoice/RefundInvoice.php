<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **ردُّ مالٍ قُبض ⇐ «مستحقة» أو «ملغاة» بحسب الحمولة** (م٢).
 *
 * الاسترداد اليوم **إشعارٌ لا قيد** (ب٨): `HandleConsultCancelled` و`settleConsult` يُشعران
 * الإدارة ويسجّلان تدقيقاً، والفاتورة تبقى «مدفوعة» فيبقى مبلغُها محسوباً في الدخل إلى الأبد.
 * هذا الانتقال يعيد الفاتورة إلى واقعها: لم يَعُد للمكتب مالُها.
 *
 * **والوجهتان ليستا ترفاً.** ردُّ مالٍ على خدمةٍ ما زالت مطلوبة يُعيد المطالبة قائمةً
 * («مستحقة» — دفعةٌ خاطئة تُردّ ثمّ يُعاد السداد صحيحاً)، وردُّه على خدمةٍ أُلغيت يُنهي
 * المطالبة («ملغاة» — استشارةٌ ألغيت بعد سدادها). وخلطُهما في وجهةٍ واحدة يعني إمّا مطالبةً
 * بمالٍ لا يستحقّه المكتب، وإمّا إسقاطَ مطالبةٍ قائمة.
 *
 * **والقيد الماليّ المقابل (صفٌّ سالب في `payments`) مؤجَّلٌ إلى م٤** مع السداد الجزئيّ:
 * موضعه الدفتر لا الفاتورة، و`payments.method` لا يحمل بعدُ قيمة `refund`. وهنا حالةُ
 * الفاتورة وحدها — فلا يُقرأ هذا الانتقال على أنّه استردادٌ مكتمل.
 *
 * @extends Transition<Invoice>
 */
final class RefundInvoice extends Transition
{
    public function name(): string
    {
        return 'invoice.refund';
    }

    public function from(): array
    {
        return [InvoiceStatus::Paid->value];
    }

    /** `cancel = true` في الحمولة ⇒ المطالبة تنتهي؛ وإلّا تعود قائمةً. */
    public function to(Model $entity, array $payload): string
    {
        return ! empty($payload['cancel']) ? InvoiceStatus::Cancelled->value : InvoiceStatus::Due->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'الاسترداد قرارٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        // ازدواج `paid`/`status` القديم: صفٌّ حالتُه «مدفوعة» و`paid = false` لا مالَ فيه يُردّ
        return $entity->paid ? null : 'لا مالَ محصَّلاً على هذه الفاتورة يُردّ.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->paid = false;
        // **و`paid_at` يُمحى معه**: تركُه يُبقي الفاتورة داخل «إيراد الفترة» وهي غير مدفوعة
        $entity->paid_at = null;

        if (! empty($payload['cancel'])) {
            $entity->cancelled_at = now();
            $entity->tone = InvoiceStatus::Cancelled->tone();

            return;
        }

        $entity->tone = InvoiceStatus::Due->tone();
    }

    public function record(array $payload): array
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';

        return array_filter([
            'reason' => $reason !== '' ? $reason : null,
            'cancelled' => ! empty($payload['cancel']) ? true : null,
        ]);
    }
}
