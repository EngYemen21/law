<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Events\Journey\ConsultReferred;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إحالة الاستشارة إلى محامٍ.**
 *
 * كانت تقبل «بانتظار اعتماد الموظف» فتتخطّى اعتماد التحليل، وتُحلّ المحامي من اقتراح
 * الذكاء بمطابقة `LIKE` غير مهرَّبة (ع٩)؛ وتزامن معرّف المحامي إلى التذكرة دون اسمه (ع٢٧).
 * هنا المحامي يصل معرّفاً محسوماً من المتحكّم، ويُزامَن المعرّف والاسم معاً.
 *
 * الحمولة: `lawyer_id` (int|null) · `lawyer` (الاسم المعروض).
 *
 * @extends Transition<Consult>
 */
final class ReferConsult extends Transition
{
    public function name(): string
    {
        return 'consult.refer';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::ReferredToLawyer->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $status = ConsultStatus::tryFrom((string) $entity->status);

        return match (true) {
            $entity->session === SessionState::Live->value => 'الجلسة منعقدة الآن — أنهِها قبل تغيير المستشار.',
            $status?->isClosed() === true => 'الاستشارة انتهت أو أُلغيت — لا تُحال إلى محامٍ.',
            $status?->isPreSession() === true => "الاستشارة ({$entity->ref}) ما زالت في دورة الحجز — حالتها «{$entity->status}». "
                .'إحالتها الآن تُخرجها من طابور التسعير فلا تُسعَّر ولا تصل الفاتورة العميلَ. '
                .'أكمل التسعير والسداد واختيار الموعد أوّلاً.',
            $status === ConsultStatus::AwaitingEmployeeApproval => 'اعتمد تحليل الفريق القانوني أوّلاً ثمّ أحِل الاستشارة.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $actorName = $actor->name ?? 'النظام';
        $lawyerId = isset($payload['lawyer_id']) ? (int) $payload['lawyer_id'] : null;
        $lawyer = trim((string) ($payload['lawyer'] ?? '')) ?: (string) $entity->lawyer;

        $entity->logAudit($actorName, 'الحالة', (string) $entity->status, ConsultStatus::ReferredToLawyer->value);
        if ($lawyer !== $entity->lawyer) {
            $entity->logAudit($actorName, 'المحامي', (string) $entity->lawyer, $lawyer);
        }

        $entity->lawyer = $lawyer;
        if ($lawyerId !== null) {
            $entity->assigned_lawyer_id = $lawyerId;

            if ($entity->ticket_id) {
                Ticket::whereKey($entity->ticket_id)->update([
                    'assigned_lawyer_id' => $lawyerId,
                    'assigned_lawyer' => $lawyer,
                ]);
            }
        }
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new ConsultReferred($entity)];
    }

    public function record(array $payload): array
    {
        return ['lawyer_id' => $payload['lawyer_id'] ?? null];
    }
}
