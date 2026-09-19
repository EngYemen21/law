<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **تسجيل قرارات وإجراءات عدم الوفاء (المادة 46 وما بعدها).**
 *
 * @extends Transition<Execution>
 */
final class ApplyExecutionMeasures extends Transition
{
    public function name(): string
    {
        return 'exec.apply_measures';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::InProgress->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::InProgress->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isAdmin() || $actor->isEmployee())) {
            return 'تسجيل قرارات التنفيذ محصور في الفريق المختص.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $measures = array_values((array) ($payload['measures'] ?? []));
        $entity->measures = $measures;

        $status = ExecutionStatus::InProgress;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = count($measures) > 0
            ? 'إجراءات عدم الوفاء: '.implode(' · ', $measures)
            : 'رُفعت إجراءات عدم الوفاء';
    }

    public function record(array $payload): array
    {
        return [
            'measures' => array_values((array) ($payload['measures'] ?? [])),
        ];
    }
}
