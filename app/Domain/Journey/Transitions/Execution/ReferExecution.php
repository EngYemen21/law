<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إحالة طلب التنفيذ إلى مرحلة الدراسة والتسعير** (المرحلة 2).
 *
 * @extends Transition<Execution>
 */
final class ReferExecution extends Transition
{
    public function name(): string
    {
        return 'exec.refer';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::NewRequest->value,
            ExecutionStatus::AiAnalysis->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::UnderStudy->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isEmployee() || $actor->isAdmin() || $actor->isLawyer())) {
            return 'الإحالة للدراسة من صلاحية فريق العمل فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->effectiveStage() !== 1 && $entity->effectiveStage() !== 0) {
            return 'الطلب محالٌ للدراسة بالفعل أو تجاوز هذه المرحلة.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $status = ExecutionStatus::UnderStudy;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = 'إحالة للدراسة والتسعير';
    }

    public function record(array $payload): array
    {
        return [
            'action' => 'refer',
        ];
    }
}
