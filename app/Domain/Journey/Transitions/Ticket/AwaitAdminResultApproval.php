<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتمد المستشار نتيجة الملفّ ⇐ التذكرة «بانتظار اعتماد الإدارة»** (الحالة القديمة للمسار القديم).
 *
 * يرافق `LawyerApproveTicketResult` في `Lawyer\TicketController::approveResult`. كان يكتب الحالة
 * مباشرةً **بلا أيّ فحصٍ لحالة التذكرة** — شرطُ المنادي على النتيجة وحدها (`pending_lawyer`).
 * فـ`from()` مفتوحةٌ (`ANY`) كي لا يضيق الانتقال عمّا كان ينجح؛ وقيدُ الانتقال يُظهر اليوم
 * من أين قفزت التذكرة، وهو ما كان يضيع.
 *
 * @extends Transition<Ticket>
 */
final class AwaitAdminResultApproval extends Transition
{
    public function name(): string
    {
        return 'ticket.awaiting_admin_result_approval';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::LegacyAwaitingAdminResult->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::LegacyAwaitingAdminResult->value);
        $entity->last_message = 'اعتمد المستشار ملخص الجلسة ورفعه للإدارة';
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }
}
