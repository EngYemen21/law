<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **شطب الدين ⇐ «دين معدوم»** (م٢).
 *
 * بلا هذه الحالة يطالب المكتب بديونٍ لا تُحصَّل إلى الأبد، وتبقى منفوخةً في «الذمم المستحقّة»
 * (ب٦) — فالرقم المعروض على المالك يحمل مبالغَ لا أحد ينتظر وصولها.
 *
 * **والسبب إلزاميّ.** الشطب إسقاطُ مالٍ للمكتب حقٌّ فيه، وقيدٌ بلا تعليل لا يُدافَع عنه عند
 * أيّ مراجعة — ولذلك `written_off_reason` عمودٌ على الصفّ لا سطرٌ في سجلٍّ يُنسى.
 *
 * **ولا تُشطب مدفوعة:** المال وصل، فشطبُه يُخرجه من الدخل بلا استردادٍ يقابله — وهو ما
 * يحرسه `from()` ويؤكّده `guard()` بعد قفل الصفّ.
 *
 * وقرارُ العتبة (اقتراحٌ آليّ بعد ١٨٠ يوماً، واعتمادٌ من الإدارة العليا وحدها — ق٨) **ليس
 * هنا**: الانتقال يجيب «هل يجوز؟» لا «متى يُقترح؟»، والاقتراح شاشةٌ في م٨.
 *
 * @extends Transition<Invoice>
 */
final class WriteOffInvoice extends Transition
{
    public function name(): string
    {
        return 'invoice.write_off';
    }

    public function from(): array
    {
        return [InvoiceStatus::Due->value, InvoiceStatus::PartiallyPaid->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::WrittenOff->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'شطب الدين قرارٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        if ($entity->paid) {
            return self::PAID;
        }

        // الحمولة الفارغة وحدها معفاة — هي سؤال `Workflow::allowed` عن إتاحة الزرّ لا نداءٌ
        // للتنفيذ. وكلُّ نداءٍ حقيقيّ يلزمه سببٌ، ولو لم يُمرَّر المفتاح أصلاً.
        if ($payload === []) {
            return null;
        }

        return trim((string) ($payload['reason'] ?? '')) === '' ? self::NO_REASON : null;
    }

    public const PAID = 'الفاتورة محصَّلة — لا دينَ فيها يُشطب.';

    public const NO_REASON = 'سبب شطب الدين مطلوب — لا إسقاطَ مالٍ بلا تعليل.';

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->written_off_at = now();
        // 300 حرفاً هو حدّ العمود — والقصّ هنا أصدق من استثناءٍ يُجهض قراراً إداريّاً وقع
        $entity->written_off_reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 300);
        $entity->tone = InvoiceStatus::WrittenOff->tone();
    }

    public function record(array $payload): array
    {
        return array_filter(['reason' => trim((string) ($payload['reason'] ?? '')) ?: null]);
    }
}
