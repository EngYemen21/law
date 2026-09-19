<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use Illuminate\Database\Eloquent\Model;

/**
 * **المستشار يعتمد نتيجة الملفّ ويرفعها للإدارة** — عمود `ticket_summaries.result_status`
 * من «بانتظار المستشار» إلى «بانتظار الإدارة» (المسار القديم للنتيجة؛ نظيره للإدارة
 * `ApproveTicketResult`).
 *
 * يناديه `Lawyer\TicketController::approveResult`، ويرافقه `AwaitAdminResultApproval` على التذكرة.
 * كان يكتب مباشرةً. `from()` هي القيمة الوحيدة التي يقبلها المنادي (وغيرها 404 عنده).
 *
 * @extends Transition<TicketSummary>
 */
final class LawyerApproveTicketResult extends Transition
{
    public function name(): string
    {
        return 'ticket_summary.lawyer_approved_result';
    }

    public function column(): string
    {
        return 'result_status';
    }

    public function from(): array
    {
        return ['pending_lawyer'];
    }

    public function to(Model $entity, array $payload): string
    {
        return 'pending_admin';
    }
}
