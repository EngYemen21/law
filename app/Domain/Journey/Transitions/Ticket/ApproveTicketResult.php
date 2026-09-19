<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **نتيجة التذكرة تُعتمد مع ملخّص الجلسة** — عمود `ticket_summaries.result_status` ⇐ «approved»
 * ونصّ النتيجة المركَّب (`payload.result`). نقيض `RejectTicketResult`.
 *
 * يناديه `ConsultSessionOutcome::publish` داخل معاملته، قبل `ReadyForOutcome`: فإن رُفض نقل
 * التذكرة أُلغي اعتماد النتيجة معه. كان يُكتب مباشرةً بلا قيد؛ و`from()` مفتوحة كما كان — النشر
 * يحرسه حالُ التذكرة (`AWAITING_OUTCOME`) لا حالُ النتيجة، والنتيجة المرفوضة تُعاد باعتمادٍ جديد.
 *
 * @extends Transition<TicketSummary>
 */
final class ApproveTicketResult extends Transition
{
    public function name(): string
    {
        return 'ticket_summary.approve_result';
    }

    public function column(): string
    {
        return 'result_status';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return 'approved';
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'اعتماد النتيجة من صلاحيّة الإدارة العليا.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var TicketSummary $entity */
        $entity->result = (string) ($payload['result'] ?? $entity->result);
    }
}
