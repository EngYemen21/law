<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\ClosureCaseReasonCode;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **إغلاق القضية القضائية بتسبيب نظامي** ('مغلقة').
 *
 * @extends Transition<LegalCase>
 */
final class CloseCase extends Transition
{
    public function name(): string
    {
        return 'case.close';
    }

    public function from(): array
    {
        return [
            CaseStatus::Judged->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::Closed->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin()) {
            return 'إغلاق القضية من صلاحية الإدارة العليا فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $reason = $payload['closure_reason'] ?? null;
        if ($reason !== null && (! is_string($reason) || ! in_array($reason, ClosureCaseReasonCode::values(), true))) {
            return 'سبب الإغلاق المحدد غير صالح.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $reasonCode = $payload['closure_reason'] ?? ClosureCaseReasonCode::RulingFinalized->value;
        $reasonEnum = ClosureCaseReasonCode::tryFrom($reasonCode) ?? ClosureCaseReasonCode::RulingFinalized;

        $entity->closure_reason = $reasonEnum->value;
        $entity->closure_notes = isset($payload['closure_notes']) ? (string) $payload['closure_notes'] : null;
        $entity->closed_at = now();
        $entity->tone = CaseJourney::toneFor(CaseStatus::Closed->value);
        $entity->update_text = 'أُغلقت القضية — '.$reasonEnum->label();
    }

    public function record(array $payload): array
    {
        return [
            'closure_reason' => $payload['closure_reason'] ?? ClosureCaseReasonCode::RulingFinalized->value,
            'closure_notes' => $payload['closure_notes'] ?? null,
        ];
    }
}
