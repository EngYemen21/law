<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * **إسناد محامٍ مسؤول لملف التنفيذ.**
 *
 * @extends Transition<Execution>
 */
final class AssignExecutionLawyer extends Transition
{
    private ?int $previousId = null;

    private ?string $previousName = null;

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

        if ($actor->isEmployee() && $entity->assigned_lawyer_id === null && $actor->can(Permissions::COURT_PROCEEDINGS)) {
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

        // المحامي السابق يُلتقط **داخل القفل وقبل الكتابة**: ما يُقرأ خارجه قد يسبقه إسنادٌ آخر
        $this->previousId = $entity->assigned_lawyer_id !== null ? (int) $entity->assigned_lawyer_id : null;
        $this->previousName = trim((string) $entity->assigned_lawyer) !== '' ? (string) $entity->assigned_lawyer : null;

        $entity->assigned_lawyer_id = $lawyerId;
        $entity->assigned_lawyer = $lawyerName;
    }

    /**
     * **السابق يُحفظ مع الجديد في سطر الرحلة** (قرار المالك 2026-09-26: «يُذكر المحامي السابق ويُحفظ
     * حتى لا يضيع شيء»). كان السطر يحفظ المسنَد إليه وحده، فإعادة الإسناد تمحو من كان قبله من الملفّ
     * ومن السجلّ معاً — لا يبقى أثرٌ لمن عمل عليه.
     */
    public function record(array $payload): array
    {
        return [
            'lawyer_id' => $payload['lawyer_id'] ?? null,
            'lawyer_name' => $payload['lawyer_name'] ?? null,
            'previous_lawyer_id' => $this->previousId,
            'previous_lawyer_name' => $this->previousName,
        ];
    }

    /** المحامي الذي كان مسنَداً قبل هذا الانتقال — يقرؤه `ExecService` لرسالة العميل بعد التنفيذ. */
    public function previousId(): ?int
    {
        return $this->previousId;
    }

    public function previousName(): ?string
    {
        return $this->previousName;
    }
}
