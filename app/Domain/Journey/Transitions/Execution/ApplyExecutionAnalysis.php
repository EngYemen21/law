<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Database\Eloquent\Model;

/**
 * **تطبيق نتائج التحليل الذكي على طلب التنفيذ.**
 *
 * @extends Transition<Execution>
 */
final class ApplyExecutionAnalysis extends Transition
{
    public function name(): string
    {
        return 'exec.apply_analysis';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        /** @var Execution $entity */
        $advance = (bool) ($payload['advance'] ?? false);
        $movable = $entity->effectiveStage() < 2;

        if ($movable && $advance) {
            return ExecutionStatus::UnderStudy->value;
        }

        if ($movable) {
            return ExecutionStatus::AiAnalysis->value;
        }

        return $entity->status;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $movable = $entity->effectiveStage() < 2;

        if ($movable) {
            $targetStatus = $this->to($entity, $payload);
            $targetEnum = ExecutionStatus::tryFrom($targetStatus) ?? ExecutionStatus::AiAnalysis;

            $entity->stage = $targetEnum->stage();
            $entity->status = $targetEnum->value;
            $entity->tone = $targetEnum->tone();

            if (isset($payload['last_action'])) {
                $entity->last_action = (string) $payload['last_action'];
            }
        }

        if (isset($payload['ai_done'])) {
            $entity->ai_done = (bool) $payload['ai_done'];
        }
        if (isset($payload['ai_source'])) {
            $entity->ai_source = (string) $payload['ai_source'];
        }
        if (isset($payload['ai_summary'])) {
            $entity->ai_summary = (string) $payload['ai_summary'];
        }
        if (isset($payload['ai_missing'])) {
            $entity->ai_missing = (array) $payload['ai_missing'];
        }
        if (isset($payload['ai_procedures'])) {
            $entity->ai_procedures = (array) $payload['ai_procedures'];
        }
        if (isset($payload['ai_study'])) {
            $entity->ai_study = (array) $payload['ai_study'];
        }
    }

    public function record(array $payload): array
    {
        return [
            'ai_source' => $payload['ai_source'] ?? null,
            'complete' => empty($payload['ai_missing']),
            'advance' => (bool) ($payload['advance'] ?? false),
        ];
    }
}
