<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **أُلغي طلب الاستشارة قبل انعقادها** — التذكرة تعود إلى «الرأي القانوني» فيمكن طلبُ غيرها.
 *
 * كان `CancelRequest` يكتب حالة التذكرة بنفسه فلا يُسجَّل للتذكرة سطر. انظر
 * `TicketAwaitsSchedule` للسبب كاملاً. وشروطُ العودة (لا استشارةَ حيّة أخرى · رأيٌ معتمد)
 * يفحصها المنادي لأنّها عن الاستشارات لا عن التذكرة.
 *
 * @extends Transition<Ticket>
 */
final class TicketBookingWithdrawn extends Transition
{
    public function name(): string
    {
        return 'ticket.booking_withdrawn';
    }

    public function from(): array
    {
        return [TicketStatus::AwaitingBooking->value, TicketStatus::AwaitingSchedule->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::LegalOpinion->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $entity->forceFill([
            'tone' => TicketJourney::toneFor(TicketStatus::LegalOpinion->value),
            'last_message' => 'أُلغي طلب الاستشارة — يمكن طلب استشارة جديدة.',
            'date_label' => 'الآن',
        ]);
    }

    public function record(array $payload): array
    {
        return ['consult_ref' => $payload['consult_ref'] ?? null];
    }
}
