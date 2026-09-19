<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة ترفض مقترح المسار أو تستبعده ⇐ «الرأي القانوني»** (شاشة «بانتظار اعتمادك»).
 *
 * كان `ApprovalsController` يكتب الحالة مباشرةً بلا فحصٍ ولا قيد انتقال، والاستبعادُ يمسح
 * المقترح بتحديثٍ جماعيّ **ويترك الحالة** «بانتظار اعتماد الإدارة للمسار» — فتعلق التذكرة
 * بانتظار مقترحٍ لم يعد موجوداً. الآن الرفض والاستبعاد انتقالٌ واحد: الفرقُ بينهما السببُ
 * ورسالته وإشعار المقترِح، ويبقى ذلك في المتحكّم بعد الانتقال كما كان.
 *
 * `from()`: ما يُقترح منه المسار يُرفض منه (`ProposeOutcomeTrack`) — الشاشة تعرض كلّ تذكرةٍ
 * بمقترحٍ قائم أيّاً كانت حالتها، فلا يضيق الانتقال عمّا كان مسموحاً.
 *
 * @extends Transition<Ticket>
 */
final class RejectOutcomeTrack extends Transition
{
    public function name(): string
    {
        return 'ticket.reject_outcome_track';
    }

    public function from(): array
    {
        return (new ProposeOutcomeTrack)->from();
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::LegalOpinion->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'رفض مقترح المسار من صلاحيّة الإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        return $entity->proposed_track === null ? 'لا مقترح مسار قائم على هذه التذكرة.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->proposed_track = null;
        $entity->proposed_track_reason = null;
        $entity->proposed_by_id = null;
        $entity->proposed_at = null;
        // اللون يتبع الحالة — كان الرفض المباشر يكتب الحالة ويترك لون «بانتظار الاعتماد»
        $entity->tone = TicketJourney::toneFor(TicketStatus::LegalOpinion->value);
    }

    public function record(array $payload): array
    {
        return ['action' => ($payload['dismiss'] ?? false) ? 'dismiss' : 'reject'];
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new TicketStatusBroadcast($entity)];
    }
}
