<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\TicketStatusCorrected;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **تصحيح حالة التذكرة — للإدارة العليا وحدها، وبسببٍ مكتوب.**
 *
 * كانت قائمة «تغيير الحالة» بيد الموظّف، و`canTransition` يقبل أيّ تبديلٍ داخل رقم المرحلة:
 * فيُكمل الموظّف التذكرة متخطّياً اعتماد الإدارة (ع٥)، وينقض قرار الإغلاق (ع٦)، ويكرّر
 * «انعقدت الجلسة» (ع١٢). الرحلة الآن تتقدّم بأفعالٍ صريحة، وهذا الباب الاستثنائيّ الوحيد:
 * إداريّ، مسبَّب، مسجَّل.
 *
 * الحمولة: `status` (من الكتالوج، غير قديمة) · `reason` (إلزاميّ).
 *
 * @extends Transition<Ticket>
 */
final class CorrectTicketStatus extends Transition
{
    public function name(): string
    {
        return 'ticket.correct-status';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $payload['status'];
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'تصحيح حالة التذكرة للإدارة العليا وحدها.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (! isset($payload['status'])) {
            return null;
        }

        $target = TicketStatus::tryFrom((string) $payload['status']);

        return match (true) {
            $target === null => 'الحالة المطلوبة ليست من مراحل الرحلة.',
            $target->value === $entity->status => 'التذكرة في هذه الحالة أصلاً.',
            blank($payload['reason'] ?? null) => 'اذكر سبب التصحيح — يُحفظ في سجلّ التذكرة.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $status = (string) $payload['status'];
        $entity->tone = TicketJourney::toneFor($status);
        $entity->last_message = 'صحّحت الإدارة حالة التذكرة.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new TicketStatusCorrected($entity, $from, (string) $payload['status'], (string) $payload['reason'], $actor->name ?? 'الإدارة')];
    }

    public function record(array $payload): array
    {
        return [];
    }
}
