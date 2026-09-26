<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ClosureExecReasonCode;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Database\Eloquent\Model;

/**
 * **إغلاق وإنهاء ملف التنفيذ القضائي بتسبيب معتمد** (المرحلة 9 — مغلق).
 *
 * @extends Transition<Execution>
 */
final class CloseExecution extends Transition
{
    public function name(): string
    {
        return 'exec.close';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::Closed->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        /** @var Execution $entity */
        if ($entity->stage >= 9 || $entity->status === 'مغلق') {
            return 'الملف مغلق بالفعل.';
        }

        // إنهاء الملفات المرفوضة في مرحلة الدراسة أو برفض العميل للعرض محصور في الإدارة
        // (القاعدة في النموذج `isRejectedOpen` — والبطاقة تقرؤها علَماً فلا تُكتب ثالثةً في الواجهة)
        $isRejected = $entity->isRejectedOpen();

        if ($isRejected && ! $actor->isAdmin()) {
            return 'إنهاء الملفّ المرفوض وأرشفته من صلاحيّة الإدارة وحدها.';
        }

        if (! ($actor->isAdmin() || $actor->isLawyer() || $actor->isEmployee())) {
            return 'إغلاق ملف التنفيذ من صلاحية فريق العمل فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        $isRejected = $entity->isRejectedOpen();

        if (! $isRejected && ! in_array($entity->effectiveStage(), [7, 8], true)) {
            return 'لا يمكن إغلاق الملف في مرحلته الحالية.';
        }

        $reason = $payload['reason'] ?? null;
        if ($reason !== null && (! is_string($reason) || ! in_array($reason, ExecFlow::CLOSE_REASONS, true))) {
            return 'سبب الإنهاء المحدد غير صالح.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $reason = (string) ($payload['reason'] ?? 'أخرى');
        $reasonCode = ClosureExecReasonCode::tryFrom($reason)?->value ?? $reason;

        $status = ExecutionStatus::Closed;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->closed_reason = $reasonCode;
        $entity->last_action = 'أُغلق ملف التنفيذ وأُرشف — '.$reason;
    }

    public function record(array $payload): array
    {
        return [
            'reason' => $payload['reason'] ?? 'أخرى',
            'notes' => $payload['notes'] ?? null,
        ];
    }
}
