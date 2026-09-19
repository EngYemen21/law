<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **قيد طلب التنفيذ بالمحكمة وتحديد الدائرة** (المرحلة 8).
 *
 * @extends Transition<Execution>
 */
final class RegisterExecutionNajiz extends Transition
{
    public function name(): string
    {
        return 'exec.register_najiz';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::InProgress->value,
            ExecutionStatus::PendingNajiz->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::InProgress->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isAdmin() || $actor->isEmployee())) {
            return 'قيد طلب التنفيذ محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (trim((string) ($payload['court'] ?? '')) === '') {
            return 'محكمة التنفيذ مطلوبة.';
        }
        if (trim((string) ($payload['circuit'] ?? '')) === '') {
            return 'الدائرة القضائية مطلوبة.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $court = trim((string) $payload['court']);
        $circuit = trim((string) $payload['circuit']);
        $registeredAt = (string) ($payload['registered_at'] ?? now()->toDateString());

        $entity->court = $court;
        $entity->circuit = $circuit;
        $entity->registered_at = $registeredAt;

        $status = ExecutionStatus::InProgress;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = "قُيّد الطلب لدى {$court} — {$circuit}";
    }

    public function record(array $payload): array
    {
        return [
            'court' => trim((string) ($payload['court'] ?? '')),
            'circuit' => trim((string) ($payload['circuit'] ?? '')),
            'registered_at' => (string) ($payload['registered_at'] ?? now()->toDateString()),
        ];
    }
}
