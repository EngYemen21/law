<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتمدت الإدارة ملخّص الملفّ ⇐ التذكرة «الرأي القانوني»** — ويُنشر الرأي المبدئيّ للعميل.
 *
 * يرافق `FinalApproveTicketSummary` في `Lawyer\TicketController::approveSummary`. كان يكتب الحالة
 * مباشرةً، ومن مراحل ما قبل اعتماد المستشار أو «بانتظار اعتماد الإدارة للملخّص» وحدها: **لا ترتدّ
 * التذكرة** فوق موعدٍ قائم (ج٩). وغيرها يُتجاوز بصمتٍ كما كان — المنادي يفحص `accepts()` قبل النداء.
 *
 * @extends Transition<Ticket>
 */
final class PublishLegalOpinion extends Transition
{
    public function name(): string
    {
        return 'ticket.legal_opinion_published';
    }

    public function from(): array
    {
        return [...TicketJourney::BEFORE_LAWYER_APPROVAL, TicketStatus::AwaitingAdminSummaryApproval->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::LegalOpinion->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->tone = TicketJourney::toneFor(TicketStatus::LegalOpinion->value);
        $entity->last_message = 'اعتُمد ملخص الملف وصدر الرأي القانوني المبدئي';
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }
}
