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
 * **انعقدت جلسة الاستشارة وانتهت ⇐ التذكرة «بانتظار ملخّص الجلسة».**
 *
 * يناديه `ConsultSessionOutcome::sessionEnded` من زرّ الإنهاء ومن webhook Zoom (فاعلٌ آليّ `null`).
 * كان يكتب الحالة مباشرةً خارج المحرّك وخارج قائمة الحارس. `from()` هي الحالتان اللتان كان
 * يقبلهما بالضبط (والقديمة «قيد التنفيذ» لصفوفٍ قائمة)، وغيرهما يُتجاوز بصمتٍ كما كان —
 * يفحصه المنادي بـ`accepts()` قبل النداء، فلا يصير صمتُ الأمس خطأً 422 اليوم.
 *
 * @extends Transition<Ticket>
 */
final class SessionEnded extends Transition
{
    public function name(): string
    {
        return 'ticket.session_ended';
    }

    public function from(): array
    {
        return [TicketStatus::Scheduled->value, TicketStatus::LegacyInExecution->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingSessionSummary->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingSessionSummary->value);
        $entity->last_message = 'انتهت الجلسة، ويُعدّ المستشار ملخّصها لاعتماده.';
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new TicketStatusBroadcast($entity)];
    }
}
