<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **قرار دراسة ملف التنفيذ (قبول / طلب نواقص / رفض).**
 *
 * @extends Transition<Execution>
 */
final class StudyExecution extends Transition
{
    public function name(): string
    {
        return 'exec.study';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::NewRequest->value,
            ExecutionStatus::AiAnalysis->value,
            ExecutionStatus::UnderStudy->value,
            ExecutionStatus::FeeEstimation->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        $action = $payload['action'] ?? 'accept';

        return match ($action) {
            'accept' => ExecutionStatus::FeeEstimation->value,
            default => $entity->status,
        };
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isAdmin() || $actor->isEmployee())) {
            return 'دراسة الملف محصورة في فريق المحامين والإدارة.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        $action = $payload['action'] ?? 'accept';
        if (! in_array($action, ['accept', 'requestDocs', 'reject'], true)) {
            return 'إجراء دراسة غير صالح.';
        }

        if ($action === 'accept') {
            if ($entity->effectiveStage() !== 2) {
                return 'لا يمكن قبول هذا الطلب في مرحلته الحالية.';
            }
            if ($entity->decision === 'مرفوض') {
                return 'هذا الطلب مرفوض بالفعل.';
            }
        } elseif ($action === 'requestDocs') {
            if (! in_array($entity->effectiveStage(), [0, 1, 2], true)) {
                return 'لا يمكن طلب مستندات في مرحلته الحالية.';
            }
            if ($entity->decision === 'مرفوض') {
                return 'هذا الطلب مرفوض بعد الدراسة — لا تُطلب عليه مستندات.';
            }
        } elseif ($action === 'reject') {
            if (! in_array($entity->effectiveStage(), [2, 3], true)) {
                return 'لا يمكن رفض هذا الطلب في مرحلته الحالية.';
            }
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $action = $payload['action'] ?? 'accept';

        match ($action) {
            'accept' => (function () use ($entity) {
                $status = ExecutionStatus::FeeEstimation;
                $entity->stage = $status->stage();
                $entity->status = $status->value;
                $entity->tone = $status->tone();
                $entity->decision = 'مقبول';
                $entity->last_action = 'قبل المحامي الطلب — بانتظار تحديد الأتعاب';
            })(),
            'requestDocs' => (function () use ($entity) {
                // المرحلة والحالة لا تتغير في طلب النواقص
            })(),
            'reject' => (function () use ($entity) {
                $entity->decision = 'مرفوض';
                $entity->last_action = 'رُفض الطلب بعد الدراسة';
            })(),
        };
    }

    public function record(array $payload): array
    {
        return [
            'action' => $payload['action'] ?? 'accept',
        ];
    }
}
