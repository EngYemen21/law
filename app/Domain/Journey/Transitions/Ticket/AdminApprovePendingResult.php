<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة تعتمد نتيجةً رفعها المستشار** — عمود `ticket_summaries.result_status` من «بانتظار
 * الإدارة» إلى «approved» (المسار القديم: `Admin\TicketController::approveResult`، بعد
 * `LawyerApproveTicketResult`). ثمّ تنتقل التذكرة بـ`ReadyForOutcome` عند المنادي.
 *
 * كان يكتب مباشرةً. ولا يُعاد استعمال `ApproveTicketResult` هنا: ذاك يكتب نصّ النتيجة المركَّب
 * من ملخّص الجلسة، وهذا المسار لا يمسّ النصّ. `from()` القيمة الوحيدة التي يقبلها المنادي
 * (وغيرها 404 عنده).
 *
 * @extends Transition<TicketSummary>
 */
final class AdminApprovePendingResult extends Transition
{
    public function name(): string
    {
        return 'ticket_summary.admin_approved_pending_result';
    }

    public function column(): string
    {
        return 'result_status';
    }

    public function from(): array
    {
        return ['pending_admin'];
    }

    public function to(Model $entity, array $payload): string
    {
        return 'approved';
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'اعتماد النتيجة من صلاحيّة الإدارة العليا.';
    }
}
