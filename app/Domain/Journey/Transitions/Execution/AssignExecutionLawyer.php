<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إسناد محامٍ مسؤول لملف التنفيذ.**
 *
 * @extends Transition<Execution>
 */
final class AssignExecutionLawyer extends Transition
{
    public function name(): string
    {
        return 'exec.assign_lawyer';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return $entity->status;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        /** @var Execution $entity */
        if ($entity->isClosed()) {
            return 'الملف منتهٍ ومغلق — لا يُسند.';
        }

        if ($actor->isAdmin()) {
            return null;
        }

        if ($actor->isEmployee() && $entity->assigned_lawyer_id === null && $actor->can('إجراءات المحكمة والجلسات')) {
            return null;
        }

        return 'لا تملك صلاحية إسناد محامٍ لملف التنفيذ.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (empty($payload['lawyer_id'])) {
            return 'المحامي المسند مطلوب.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $lawyerId = (int) $payload['lawyer_id'];
        $lawyerName = (string) ($payload['lawyer_name'] ?? '');

        if ($lawyerName === '') {
            $lawyer = User::find($lawyerId);
            $lawyerName = $lawyer?->name ?? '';
        }

        $entity->assigned_lawyer_id = $lawyerId;
        $entity->assigned_lawyer = $lawyerName;
    }

    public function record(array $payload): array
    {
        return [
            'lawyer_id' => $payload['lawyer_id'] ?? null,
            'lawyer_name' => $payload['lawyer_name'] ?? null,
        ];
    }
}
