<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة ترفض إثبات التحويل ⇐ «مستحقة» من جديد** — نقيض `SubmitPaymentProof`.
 *
 * كان متحكّم المحاسبة (اليوم `Admin\FinanceController::rejectProof`) يكتب الحالة مباشرةً. حذف الملفّ من القرص والإشعار
 * والتدقيق تبقى عند المتحكّم كما كانت؛ هنا تفريغ الإثبات وإعادة الاستحقاق في معاملةٍ واحدة.
 *
 * `from()` مفتوح كما كان المتحكّم: حارساه «إثباتٌ مرفوع» و«غير محصّلة» — وهما هنا شبكةُ أمان
 * بعد قفل الصفّ (تحصيلٌ وقع بين القراءة والرفض لا يُعاد إلى الاستحقاق).
 *
 * @extends Transition<Invoice>
 */
final class RejectPaymentProof extends Transition
{
    public function name(): string
    {
        return 'invoice.reject_proof';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::Due->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'رفض الإثبات قرارٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        if ($entity->paid) {
            return 'الفاتورة محصَّلة — لا معنى لرفض إثباتها.';
        }

        return $entity->proof_path === null ? 'لا يوجد إثبات مرفوع لهذه الفاتورة.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->proof_path = null;
        $entity->proof_uploaded_at = null;
        $entity->tone = InvoiceStatus::Due->tone();
    }

    public function record(array $payload): array
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';

        return array_filter(['reason' => $reason !== '' ? $reason : null]);
    }
}
