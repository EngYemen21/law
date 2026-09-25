<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **موعد الاستشارة اعتُمد ونُشر** — التذكرة «موعد مؤكد».
 *
 * كان `PublishAppointment` يكتب حالة التذكرة بنفسه فلا يُسجَّل للتذكرة شيء. انظر
 * `TicketAwaitsSchedule` للسبب كاملاً.
 *
 * ومصدره كلُّ حالةٍ غير نهائيّة كما كان السلوك: الإدارة قد تحجز مباشرةً لتذكرةٍ في أيّ مرحلةٍ
 * قبل الحسم. والنهائيّة لا تُمسّ — والمنادي يسأل `accepts()` قبل النداء.
 *
 * الحمولة: `message` — «تم تأكيد موعد الاستشارة: …».
 *
 * @extends Transition<Ticket>
 */
final class TicketScheduled extends Transition
{
    public function name(): string
    {
        return 'ticket.scheduled';
    }

    public function from(): array
    {
        return array_values(array_map(
            fn (TicketStatus $s) => $s->value,
            array_filter(TicketStatus::cases(), fn (TicketStatus $s) => ! $s->isFinal() && $s !== TicketStatus::Scheduled)
        ));
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::Scheduled->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $entity->forceFill([
            'tone' => TicketJourney::toneFor(TicketStatus::Scheduled->value),
            'last_message' => (string) ($payload['message'] ?? 'تم تأكيد موعد الاستشارة.'),
            'date_label' => 'الآن',
        ]);
    }

    public function record(array $payload): array
    {
        return ['consult_ref' => $payload['consult_ref'] ?? null];
    }
}
