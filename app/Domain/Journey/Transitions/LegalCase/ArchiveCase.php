<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **الأرشفة النهائية للقضية المغلقة** ('مؤرشفة').
 *
 * @extends Transition<LegalCase>
 */
final class ArchiveCase extends Transition
{
    public function name(): string
    {
        return 'case.archive';
    }

    public function from(): array
    {
        return [
            CaseStatus::Closed->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::Archived->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin()) {
            return 'أرشفة القضية من صلاحية الإدارة العليا فقط.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $entity->tone = CaseJourney::toneFor(CaseStatus::Archived->value);
        $entity->update_text = 'أُودعت القضية في الأرشيف القانوني بعد استيفاء كافة المتطلبات';
    }
}
