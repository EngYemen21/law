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
 * **القرار النهائي الأول: تحويل التذكرة إلى قضية رسمية** ('محولة إلى قضية').
 *
 * يُنادى تحت القفل الحصري والمعاملة في `Workflow::run`.
 * ينقل التذكرة إلى حالتها النهائية الصريحة، ويجمد السجل (`is_frozen = true`)
 * لمنع أي تعديل أو إغلاق لاحق.
 *
 * @extends Transition<Ticket>
 */
final class ConvertToCase extends Transition
{
    public function name(): string
    {
        return 'ticket.convert_to_case';
    }

    public function from(): array
    {
        return [
            TicketStatus::ReadyForOutcome->value,
            TicketStatus::Completed->value,
            TicketStatus::LegalOpinion->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::ConvertedToCase->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null; // يُقبل للوظائف والسكربتات المعتمدة
        }

        if (! ($actor->isAdmin() || $actor->isLawyer() || $actor->isEmployee())) {
            return 'صلاحية اتخاذ قرار تحويل التذكرة لقضية محصورة في فريق العمل المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        $allowedCaseId = $payload['case_id'] ?? null;
        if ($entity->legalCase()->when($allowedCaseId, fn ($q) => $q->where('id', '!=', $allowedCaseId))->exists()) {
            return 'تم تحويل هذه التذكرة لقضية مسبقاً.';
        }

        if ($entity->is_frozen && $entity->status === TicketStatus::Closed->value) {
            return 'التذكرة مغلقة بقرار نهائي مسبق ولا يمكن تحويلها.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::ConvertedToCase->value);
        $entity->is_frozen = true;
        $entity->outcome_decision_at = now();
        $entity->last_message = 'تم اتخاذ القرار النهائي: تحويل الطلب إلى ملف قضية رسمي.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Ticket $entity */
        return [new TicketStatusBroadcast($entity)];
    }
}
