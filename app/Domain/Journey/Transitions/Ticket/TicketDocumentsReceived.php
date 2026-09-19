<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **وصل مستندٌ يخصّ الملفّ ⇐ «قيد التحليل»** بانتظار مراجعة الموظّف وإحالته للمستشار
 * (لا إحالة آليّة من الذكاء — القرار بيد الموظّف).
 *
 * ثلاثة مداخل كانت تكتب الحالة مباشرةً: الوكيل الآليّ عند الفتح بمستنداتٍ ذات صلة،
 * والوكيل عند إرفاق مستندٍ مرتبط والتذكرة «بانتظار مستندات» (`TicketTriage`)، وإرفاق الموظّف
 * مستنداً والتذكرة «بانتظار مستندات» (`Employee\TicketController::attach`).
 *
 * `from()` واسعةٌ (`TicketOpenStates`) لأنّ مدخل الفتح كان يكتب بلا فحص؛ وشرط «بانتظار مستندات»
 * باقٍ عند المدخلين الآخرين بنصّه.
 *
 * الحمولة: `last_message` — سطر البطاقة بنصّ المنادي كما كان.
 *
 * @extends Transition<Ticket>
 */
final class TicketDocumentsReceived extends Transition
{
    public function name(): string
    {
        return 'ticket.documents_received';
    }

    public function from(): array
    {
        return TicketOpenStates::values();
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::Analyzing->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::Analyzing->value);
        if (filled($payload['last_message'] ?? null)) {
            $entity->last_message = (string) $payload['last_message'];
        }
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }

    public function record(array $payload): array
    {
        return array_filter(['via' => $payload['via'] ?? null]);
    }
}
