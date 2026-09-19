<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتمد المستشار ملخّص الملفّ ⇐ التذكرة «بانتظار اعتماد الإدارة للملخّص».**
 *
 * يرافق `LawyerApproveTicketSummary` في `Lawyer\TicketController::approveSummary`. كان يكتب الحالة
 * مباشرةً، ومن مراحل ما قبل اعتماد المستشار وحدها — وغيرها يُتجاوز بصمتٍ كما كان: التذكرة التي
 * تقدّمت (موعدٌ قائم مثلاً) لا ترتدّ باعتماد ملخّصها. `from()` تلك المراحل بالضبط، والمنادي يفحص
 * `accepts()` قبل النداء.
 *
 * @extends Transition<Ticket>
 */
final class AwaitAdminSummaryApproval extends Transition
{
    public function name(): string
    {
        return 'ticket.awaiting_admin_summary_approval';
    }

    public function from(): array
    {
        return TicketJourney::BEFORE_LAWYER_APPROVAL;
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingAdminSummaryApproval->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingAdminSummaryApproval->value);
        $entity->last_message = 'اعتمد المستشار ملخّص الملف، وهو بانتظار اعتماد الإدارة';
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }
}
