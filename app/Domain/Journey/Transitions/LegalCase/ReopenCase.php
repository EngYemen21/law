<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * **إعادة فتح القضية المغلقة قبل أرشفتها** ('صدر الحكم').
 *
 * @extends Transition<LegalCase>
 */
final class ReopenCase extends Transition
{
    public function name(): string
    {
        return 'case.reopen';
    }

    public function from(): array
    {
        return [
            CaseStatus::Closed->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::Judged->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin()) {
            return 'إعادة فتح القضية من صلاحية الإدارة العليا فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            return 'يجب تقديم سبب كافٍ لإعادة فتح القضية (5 أحرف على الأقل).';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $reason = trim((string) ($payload['reason'] ?? ''));

        $entity->closure_reason = null;
        $entity->closure_notes = null;
        $entity->closed_at = null;
        $entity->tone = CaseJourney::toneFor(CaseStatus::Judged->value);
        $entity->update_text = 'أعادت الإدارة فتح القضية بعد إغلاقها — '.Str::limit($reason, 60);
    }

    public function record(array $payload): array
    {
        return [
            'reason' => $payload['reason'] ?? '',
        ];
    }
}
