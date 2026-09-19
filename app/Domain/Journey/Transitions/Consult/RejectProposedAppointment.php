<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة ترفض الموعد المقترح أو تستبعده ⇐ «بانتظار تحديد الموعد»** — نقيض `PublishAppointment`.
 *
 * كان `ApprovalsController` يحذف الموعد ويكتب الحالة مباشرةً، بلا فحصٍ ولا قيد، ويُبقي
 * `appointment_id` يشير إلى صفٍّ محذوف. الشاشة لا تعرض إلا «بانتظار اعتماد الموعد»، فذاك
 * وحده المقبول — ولا يُعاد فتح استشارةٍ منتهية أو ملغاة بموعدٍ جديد.
 *
 * **الحذف لا الإلغاء، عمداً وكما كان:** المقترح لم يُنشر للعميل (`ProposeAppointment`)، وموعدٌ
 * «ملغى» يظهر في «مواعيدي» عن موعدٍ لم يعلم به العميل أصلاً. والأثر في سجلّ الاستشارة.
 *
 * @extends Transition<Consult>
 */
final class RejectProposedAppointment extends Transition
{
    public function name(): string
    {
        return 'consult.reject_proposed_appointment';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingAppointmentApproval->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingSchedule->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'رفض الموعد المقترح من صلاحيّة الإدارة العليا.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->appointment?->delete();
        $entity->appointment_id = null;

        $reason = trim((string) ($payload['reason'] ?? ''));
        $entity->logAudit(
            $actor->name ?? 'الإدارة',
            $reason !== '' ? 'رفض الموعد المقترح' : 'استبعاد الموعد المقترح',
            '—',
            $reason !== '' ? $reason : 'أُعيد لتحديد موعدٍ بديل'
        );
    }

    public function record(array $payload): array
    {
        return ['action' => isset($payload['reason']) ? 'reject' : 'dismiss'];
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new ConsultStatusBroadcast($entity)];
    }
}
