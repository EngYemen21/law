<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFee;
use Illuminate\Database\Eloquent\Model;

/**
 * **إثبات تحصيل مبالغ من المنفّذ ضده وإصدار فاتورة النسبة.**
 *
 * @extends Transition<Execution>
 */
final class RecordExecutionCollection extends Transition
{
    public function name(): string
    {
        return 'exec.add_collection';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::InProgress->value,
            ExecutionStatus::Closed->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return $entity->status;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isAdmin() || $actor->isEmployee())) {
            return 'إثبات التحصيل محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            return 'مبلغ التحصيل يجب أن يكون أكبر من الصفر.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $amount = (int) $payload['amount'];
        $entity->collected = (int) $entity->collected + $amount;
        $entity->last_action = 'تحصيل '.number_format($amount).' ريال';
    }

    public function record(array $payload): array
    {
        return [
            'amount' => (int) ($payload['amount'] ?? 0),
            'note' => (string) ($payload['note'] ?? ''),
        ];
    }
}
