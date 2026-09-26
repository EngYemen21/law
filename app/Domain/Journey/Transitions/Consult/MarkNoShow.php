<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Events\Journey\ConsultMarkedNoShow;
use App\Events\Journey\SessionEndedInSystem;
use App\Events\RoomStateChanged;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **«لم يحضر» — يدويّاً من الطاقم أو آليّاً بعد مهلة الإعدادات.**
 *
 * الحمولة: `automatic` (bool) — يُسجَّل «حسم آلي» في سجلّ الاستشارة.
 *
 * @extends Transition<Consult>
 */
final class MarkNoShow extends Transition
{
    public function name(): string
    {
        return 'consult.no-show';
    }

    public function column(): string
    {
        return 'session';
    }

    public function from(): array
    {
        return [SessionState::Waiting->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return SessionState::NotHeld->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (ConsultStatus::tryFrom((string) $entity->status)?->isClosed()) {
            return 'الاستشارة انتهت أو أُلغيت.';
        }

        if (ConsultStatus::tryFrom((string) $entity->status)?->isPreSession()) {
            return 'لم يُنشر لهذه الاستشارة موعد بعد.';
        }

        return $entity->isMissed() ? null : 'لم يحن موعد الاستشارة بعد.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $automatic = (bool) ($payload['automatic'] ?? false);
        $entity->logAudit(
            $actor->name ?? 'النظام',
            'الجلسة',
            SessionState::Waiting->value,
            $automatic ? 'لم يحضر (حسم آلي)' : 'لم يحضر'
        );
        $entity->status = ConsultStatus::NoShow->value;
    }

    /**
     * وغرفةُ Zoom تُغلق كما في الإنهاء (`SessionEndedInSystem`): إن كان أحدٌ فتحها وضاع حدثُ بدئها
     * فلا تبقى مفتوحةً لجلسةٍ حُسمت؛ وإن لم تُفتح فالإغلاق لا يفعل شيئاً — والاجتماع لا يُحذف،
     * فـZoom يبقى قادراً على إحيائها إن انعقدت فعلاً (`ZoomSessionStarted`).
     */
    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Consult $entity */
        return [
            new ConsultMarkedNoShow($entity),
            ...SessionEndedInSystem::forEnd($entity->meet_id, $entity->ref, $payload),
            ...RoomStateChanged::both($entity),
        ];
    }

    public function record(array $payload): array
    {
        return ['automatic' => (bool) ($payload['automatic'] ?? false)];
    }
}
