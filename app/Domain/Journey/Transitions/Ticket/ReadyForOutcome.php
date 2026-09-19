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
 * **انتقال التذكرة إلى جاهزية اتخاذ قرار المآل** ('بانتظار قرار المآل').
 *
 * يُنادى فور اعتماد الإدارة العليا لملخص الاستشارة أو نتيجة الملف،
 * فيمنع ترك التذكرة في حالة رمادية، ويضعها مباشرة أمام بوابة اتخاذ القرار النهائي.
 *
 * @extends Transition<Ticket>
 */
final class ReadyForOutcome extends Transition
{
    public function name(): string
    {
        return 'ticket.ready_for_outcome';
    }

    public function from(): array
    {
        return [
            TicketStatus::LegalOpinion->value,
            TicketStatus::Scheduled->value,
            TicketStatus::AwaitingSessionSummary->value,
            TicketStatus::Completed->value,
            'بانتظار اعتماد الإدارة',
            'بانتظار اعتماد النتيجة',
            'قيد التنفيذ',
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::ReadyForOutcome->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isAdmin() || $actor->isLawyer() || $actor->isEmployee())) {
            return 'غير مصرّح لك بنقل التذكرة لجاهزية اتخاذ القرار.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        if ($entity->legalCase()->exists()) {
            return 'تم تحويل هذه التذكرة لقضية مسبقاً.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::ReadyForOutcome->value);
        $entity->last_message = 'اكتملت دراسة الطلب — بانتظار اتخاذ قرار المآل النهائي.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Ticket $entity */
        return [new TicketStatusBroadcast($entity)];
    }
}
