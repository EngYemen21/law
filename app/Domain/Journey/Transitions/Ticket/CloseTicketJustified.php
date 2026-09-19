<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **القرار النهائي الثاني: إغلاق التذكرة بقرار مسبّب ومصنّف** ('مغلقة').
 *
 * يُشترط اختيار كود سبب نظامي صالح وتدوين تسبيب مبرر مكتوب لقرار الحفظ أو عدم رفع دعوى.
 * ينقل التذكرة إلى حالة الإغلاق النهائي ويجمد السجل (`is_frozen = true`).
 *
 * @extends Transition<Ticket>
 */
final class CloseTicketJustified extends Transition
{
    public function name(): string
    {
        return 'ticket.close_justified';
    }

    public function from(): array
    {
        return [
            TicketStatus::ReadyForOutcome->value,
            TicketStatus::Completed->value,
            TicketStatus::LegalOpinion->value,
            TicketStatus::AwaitingDocs->value, // في حال حفظ الملف لعدم تجاوب العميل
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::Closed->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        if (! ($actor->isAdmin() || $actor->isLawyer() || $actor->isEmployee())) {
            return 'صلاحية اتخاذ قرار إغلاق التذكرة محصورة في فريق العمل المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        if ($entity->legalCase()->exists()) {
            return 'تم تحويل هذه التذكرة لقضية مسبقاً ولا يمكن إغلاقها دون قضية.';
        }

        $code = $payload['closure_reason_code'] ?? null;
        if (! is_string($code) || ! in_array($code, ClosureReasonCode::values(), true)) {
            return 'يجب اختيار سبب إغلاق نظامي معتمد من القائمة.';
        }

        $notes = trim((string) ($payload['closure_notes'] ?? ''));
        if ($notes === '') {
            return 'يجب تدوين تسبيب وملاحظات لقرار الإغلاق للحفظ والتدقيق المهني.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->closure_reason_code = (string) $payload['closure_reason_code'];
        $entity->closure_notes = trim((string) ($payload['closure_notes'] ?? ''));
        $entity->closed_by_id = $actor?->id;
        $entity->is_frozen = true;
        $entity->outcome_decision_at = now();
        $entity->tone = TicketJourney::toneFor(TicketStatus::Closed->value);
        $entity->last_message = 'أُغلقت التذكرة بعد دراسة الطلب بقرار مسبّب دون تحويلها لقضية.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Ticket $entity */
        return [new TicketStatusBroadcast($entity)];
    }
}
