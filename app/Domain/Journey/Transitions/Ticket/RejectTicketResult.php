<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة ترفض نتيجة التذكرة أو تعيد تدقيقها** — عمود `ticket_summaries.result_status`
 * (سجلّ «النتائج المعتمدة» في شاشة «بانتظار اعتمادك»).
 *
 * كان `ApprovalsController` يكتب «rejected» مباشرةً بلا قيد. يبقى الأثر كما هو — النتيجة وحدها،
 * لا حالة التذكرة — ويُسجَّل الانتقال وسببه. يُقبل من «معتمدة» وحدها (مراجعةٌ لاحقة)؛ و«بانتظار
 * الإدارة» (`pending_admin`) حُذفت 2026-09-19 مع مصدرها الوحيد، اعتماد المحامي للنتيجة.
 *
 * @extends Transition<TicketSummary>
 */
final class RejectTicketResult extends Transition
{
    public function name(): string
    {
        return 'ticket_summary.reject_result';
    }

    public function column(): string
    {
        return 'result_status';
    }

    public function from(): array
    {
        return ['approved'];
    }

    public function to(Model $entity, array $payload): string
    {
        return 'rejected';
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'رفض النتيجة من صلاحيّة الإدارة العليا.';
    }
}
