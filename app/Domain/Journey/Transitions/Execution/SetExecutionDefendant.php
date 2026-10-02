<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **تحديد المنفَّذ ضده أو تصحيحه على ملفّ التنفيذ** (قرار المالك 2026-10-02).
 *
 * كان الاسم يُنسخ من «الخصم» في التذكرة عند فتح الملفّ ولا يُعدَّل أبداً — والتذكرة لم تكن تطلبه إلّا لقسم
 * التنفيذ، فملفٌّ من قضيّةٍ يُفتح بـ«المنفَّذ ضده —» ويبقى كذلك. الآن يحدّده المحامي المسنَد أو الإدارة
 * ما دام الملفّ مفتوحاً؛ و**استبدالُ** اسمٍ قائم يلزمه سببٌ مكتوب، أمّا ملءُ الفارغ فلا.
 *
 * @extends Transition<Execution>
 */
final class SetExecutionDefendant extends Transition
{
    public const REASON_MIN = 10;

    private string $previous = '';

    public function name(): string
    {
        return 'exec.set_defendant';
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
        if ($actor === null || $actor->isAdmin()) {
            return null;
        }

        return $actor->isLawyer() && (int) $entity->getAttribute('assigned_lawyer_id') === (int) $actor->id
            ? null
            : 'تحديد المنفَّذ ضده للمحامي المسنَد أو الإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->isClosed()) {
            return 'الملفّ منتهٍ ومغلق — لا تُعدَّل بياناته.';
        }
        $name = trim((string) ($payload['defendant'] ?? ''));
        if (($error = Execution::defendantError($name)) !== null) {
            return $error;
        }
        $current = trim((string) $entity->defendant);
        if ($name === $current) {
            return 'الاسم المدخل هو المنفَّذ ضده الحاليّ.';
        }
        if ($current !== '' && mb_strlen(trim((string) ($payload['reason'] ?? ''))) < self::REASON_MIN) {
            return 'اكتب سبب التصحيح ('.self::REASON_MIN.' أحرف على الأقل).';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $this->previous = trim((string) $entity->defendant);
        $entity->defendant = trim((string) $payload['defendant']);
        $entity->last_action = ($this->previous === '' ? 'حُدِّد' : 'صُحّح').' المنفَّذ ضده: '.$entity->defendant;
    }

    public function record(array $payload): array
    {
        return array_filter([
            'from' => $this->previous,
            'to' => trim((string) ($payload['defendant'] ?? '')),
            'reason' => trim((string) ($payload['reason'] ?? '')),
        ], fn (string $v) => $v !== '');
    }
}
