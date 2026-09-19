<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * **إبلاغ المنفّذ ضده بأمر التنفيذ وحساب مهلة الوفاء النظامية.**
 *
 * @extends Transition<Execution>
 */
final class NotifyExecutionDebtor extends Transition
{
    public function name(): string
    {
        return 'exec.notify_debtor';
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
            return 'تسجيل إبلاغ المنفّذ ضده محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (trim((string) ($payload['notified_at'] ?? '')) === '') {
            return 'تاريخ الإبلاغ مطلوب.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $notifiedAt = (string) $payload['notified_at'];
        $notified = Carbon::parse($notifiedAt);
        $due = ExecFlow::payDueAfter($notified);

        $entity->notified_at = $notified->toDateString();
        $entity->pay_due_at = $due->toDateString();

        $status = ExecutionStatus::InProgress;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = 'أُبلغ المنفَّذ ضدّه بأمر التنفيذ';
    }

    public function record(array $payload): array
    {
        return [
            'notified_at' => (string) ($payload['notified_at'] ?? ''),
        ];
    }
}
