<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **تصحيح مبلغ المطالبة على ملفّ التنفيذ** (قرار المالك 2026-09-30).
 *
 * كان مبلغ الملفّ يُنسخ عند فتحه ولا يُعدَّل أبداً، فملفٌّ فُتح بصفرٍ (تذكرةٌ بلا مبلغ، والحكم نصٌّ حرّ)
 * يستحيل عليه أيّ تحصيل — المتبقّي صفرٌ دائماً. الآن يصحّحه المحامي المسنَد أو الإدارة بسببٍ مكتوب،
 * **قبل أوّل تحصيل**: بعده صار المبلغ أساسَ ما حُصّل وفواتير أتعابه النسبيّة، فلا يُعاد تعريفه.
 *
 * @extends Transition<Execution>
 */
final class SetExecutionClaimAmount extends Transition
{
    public const REASON_MIN = 10;

    private int $previous = 0;

    public function name(): string
    {
        return 'exec.set_claim_amount';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return $entity->status;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null || $actor->isAdmin()) {
            return null;
        }

        return $actor->isLawyer() && (int) $entity->getAttribute('assigned_lawyer_id') === (int) $actor->id
            ? null
            : 'تصحيح مبلغ المطالبة للمحامي المسنَد أو الإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->isClosed()) {
            return 'الملفّ منتهٍ ومغلق — لا يُعدَّل مبلغه.';
        }
        if ((int) $entity->collected > 0) {
            return 'سُجّل تحصيلٌ على الملفّ — لا يُعدَّل مبلغ المطالبة بعده.';
        }
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount < 1) {
            return 'أدخل مبلغ المطالبة (ريال) — رقماً صحيحاً أكبر من صفر.';
        }
        if ($amount > Execution::MAX_CLAIM_AMOUNT) {
            return 'مبلغ المطالبة يتجاوز الحدّ الأعلى ('.number_format(Execution::MAX_CLAIM_AMOUNT).' ريال).';
        }
        if ($amount === (int) $entity->amount) {
            return 'المبلغ المدخل هو مبلغ المطالبة الحاليّ.';
        }
        if (mb_strlen(trim((string) ($payload['reason'] ?? ''))) < self::REASON_MIN) {
            return 'اكتب سبب التصحيح ('.self::REASON_MIN.' أحرف على الأقل).';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $this->previous = (int) $entity->amount;
        $entity->amount = (int) $payload['amount'];
        $entity->last_action = 'صُحّح مبلغ المطالبة إلى '.number_format((int) $payload['amount']).' ريال';
    }

    public function record(array $payload): array
    {
        return [
            'from' => $this->previous,
            'to' => (int) ($payload['amount'] ?? 0),
            'reason' => trim((string) ($payload['reason'] ?? '')),
        ];
    }
}
