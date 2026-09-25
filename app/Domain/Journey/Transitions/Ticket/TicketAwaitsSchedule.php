<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **التذكرة تنتظر موعداً يحجزه الطاقم** — بعد سداد الاستشارة، أو بعد إلغاء موعدها بإعادة الجدولة.
 *
 * كانت انتقالات الاستشارة تكتب حالة التذكرة بنفسها (`$ticket->update(['status' => …])`)، فتتغيّر
 * حالة التذكرة **بلا سطرٍ في سجلّ رحلتها**: رُصد حيّاً على SB-2026-3286 (2026-09-25) —
 * «بانتظار تحديد الموعد» وسجلُّها فارغ. فالتذكرة تتحرّك بانتقالها هي، يناديه انتقال الاستشارة
 * داخل معاملته فيلتزمان معاً أو يتراجعان معاً (كما `SettleInvoice` داخل `SettlePayment`).
 *
 * الحمولة: `message` — ما يُكتب في «آخر تحديث» على بطاقة التذكرة.
 *
 * @extends Transition<Ticket>
 */
final class TicketAwaitsSchedule extends Transition
{
    public function name(): string
    {
        return 'ticket.awaits_schedule';
    }

    public function from(): array
    {
        // سُدّدت فتنتظر الحجز · أو أُلغي موعدها المؤكَّد فتنتظر موعداً جديداً
        return [TicketStatus::AwaitingBooking->value, TicketStatus::Scheduled->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingSchedule->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $entity->forceFill([
            'tone' => TicketJourney::toneFor(TicketStatus::AwaitingSchedule->value),
            'last_message' => (string) ($payload['message'] ?? 'يُحدَّد موعد الجلسة مع المستشار المختص.'),
            'date_label' => 'الآن',
        ]);
    }

    public function record(array $payload): array
    {
        return ['consult_ref' => $payload['consult_ref'] ?? null];
    }
}
