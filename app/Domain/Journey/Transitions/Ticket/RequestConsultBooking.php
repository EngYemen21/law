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
 * **طُلبت استشارةٌ على التذكرة ⇐ «بانتظار حجز الاستشارة».**
 *
 * يناديه `ConsultBooking::request` حين يُربط الطلب بتذكرة. كان يكتب الحالة مباشرةً بلا فحص.
 * `from()` هي الحالتان اللتان يشترطهما المنادون جميعاً قبل الطلب
 * (`TicketJourney::consultRequestBlocker`): «الرأي القانوني»، و«بانتظار حجز الاستشارة» لطلبٍ
 * يتجدّد بعد إلغاء سابقه. فالحارس هنا ضمانةٌ لأيّ منادٍ جديد، لا تضييقٌ على قائم.
 *
 * @extends Transition<Ticket>
 */
final class RequestConsultBooking extends Transition
{
    public function name(): string
    {
        return 'ticket.consult_requested';
    }

    public function from(): array
    {
        return [TicketStatus::LegalOpinion->value, TicketStatus::AwaitingBooking->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingBooking->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingBooking->value);
        $entity->last_message = 'تم فتح طلب حجز استشارة بانتظار استكمال الخطوات.';
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }

    public function record(array $payload): array
    {
        return ['consult' => $payload['consult'] ?? null];
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new TicketStatusBroadcast($entity)];
    }
}
