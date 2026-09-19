<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة تعيد ملخّص التذكرة للمستشار** — عمود `ticket_summaries.status` من «بانتظار الإدارة»
 * إلى «بانتظار المستشار» (شاشة «بانتظار اعتمادك»).
 *
 * - **الرفض** (`payload.return_ticket = true`): والتذكرة تعود «بانتظار اعتماد المستشار» — كما كان.
 * - **الاستبعاد**: الملخّص وحده، والتذكرة كما هي — كما كان.
 *
 * كان الاثنان يكتبان مباشرةً بلا فحص حالةٍ ولا قيد. والشاشة لا تعرض إلا ملخّصاً «بانتظار
 * الإدارة» (`ApprovalsController::index`)، فذاك وحده المقبول. والتذكرة المنتهية (محوّلة أو
 * مغلقة) لا تُعاد حالتُها — كانت الكتابة المباشرة تُحييها «بانتظار اعتماد المستشار».
 *
 * @extends Transition<TicketSummary>
 */
final class ReturnTicketSummary extends Transition
{
    public const AWAITING_ADMIN = 'awaiting_admin';

    public const AWAITING_LAWYER = 'awaiting_lawyer';

    public function name(): string
    {
        return 'ticket_summary.return_to_lawyer';
    }

    public function from(): array
    {
        return [self::AWAITING_ADMIN];
    }

    public function to(Model $entity, array $payload): string
    {
        return self::AWAITING_LAWYER;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'إعادة الملخّص من صلاحيّة الإدارة العليا.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var TicketSummary $entity */
        $entity->lawyer_approved_at = null;

        $ticket = $entity->ticket;
        if (($payload['return_ticket'] ?? false) && $ticket !== null && ! self::isTerminal($ticket->status)) {
            $ticket->update([
                'status' => TicketStatus::AwaitingLawyerApproval->value,
                'tone' => TicketJourney::toneFor(TicketStatus::AwaitingLawyerApproval->value),
            ]);
        }
    }

    public function record(array $payload): array
    {
        return ['action' => ($payload['return_ticket'] ?? false) ? 'reject' : 'dismiss'];
    }

    /** محوّلة إلى قضيّة أو مغلقة — لا يُعاد فتحها من شاشة الاعتماد. */
    private static function isTerminal(?string $status): bool
    {
        return TicketStatus::tryFrom((string) $status)?->isTerminal() ?? false;
    }
}
