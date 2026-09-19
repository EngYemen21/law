<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **رفع طلب التنفيذ في بوابة ناجز** (المرحلة 8 — قيد التنفيذ).
 *
 * @extends Transition<Execution>
 */
final class FileExecutionNajiz extends Transition
{
    public function name(): string
    {
        return 'exec.file_najiz';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::PendingNajiz->value,
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
            return 'رفع الطلب في ناجز محصور في فريق العمل المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $requestNo = trim((string) ($payload['request_no'] ?? ''));
        if ($requestNo === '') {
            return 'رقم الطلب في ناجز مطلوب.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $requestNo = trim((string) $payload['request_no']);
        $filedAt = (string) ($payload['filed_at'] ?? now()->toDateString());

        $entity->najiz_request_no = $requestNo;
        $entity->najiz_filed_at = $filedAt;

        $status = ExecutionStatus::InProgress;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = "رُفع الطلب في ناجز برقم {$requestNo}";
    }

    public function record(array $payload): array
    {
        return [
            'request_no' => trim((string) ($payload['request_no'] ?? '')),
            'filed_at' => (string) ($payload['filed_at'] ?? now()->toDateString()),
        ];
    }
}
