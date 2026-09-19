<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **طُلبت من العميل مستندات ⇐ «بانتظار مستندات».**
 *
 * خمسة مداخل تكتب الحالة نفسها، وكانت تكتبها مباشرةً: الوكيل الآليّ عند الفتح (ترحيبٌ بلا
 * مرفقات، أو مرفقاتٌ لا تخصّ الموضوع، أو مرفقاتٌ تعذّر فحصها — `TicketTriage::onOpened`)،
 * وطلب النواقص من الموظّف (`requestDocs`)، وبوابة المستندات في «الإحالة» (`advance`)،
 * وطلب المستشار قبل اعتماد الملخّص (`Lawyer\TicketController::requestDocs`).
 *
 * `from()` واسعةٌ عمداً (`TicketOpenStates`): الوكيل الآليّ كان يكتب بلا أيّ فحص، والقاعدة ألّا
 * يضيق الانتقال عمّا كان ينجح. شروط كلّ مدخلٍ ورسائله باقيةٌ عنده بنصّها (مراحل ما قبل اعتماد
 * المستشار للموظّف والمستشار، والمراحل الأربع الأولى للإحالة). والنهايتان وحدهما خارجها.
 *
 * الحمولة: `last_message` — سطر البطاقة؛ يختلف بين المداخل فيأتي من المنادي بنصّه كما كان.
 *
 * @extends Transition<Ticket>
 */
final class AwaitTicketDocuments extends Transition
{
    public function name(): string
    {
        return 'ticket.awaiting_documents';
    }

    public function from(): array
    {
        return TicketOpenStates::values();
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingDocs->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingDocs->value);
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
