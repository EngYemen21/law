<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة تعتمد ملخّص الملفّ نهائيّاً فيُنشر للعميل** — عمود `ticket_summaries.status` ⇐ «approved»
 * (المرحلة الثانية؛ وإن كتبته الإدارة واعتمدته بنفسها عُدّ اعتمادُها للمرحلتين معاً).
 *
 * يناديه `Lawyer\TicketController::approveSummary` في فرع الإدارة. كان يكتب الحالة مباشرةً مع
 * النصّ والأختام في حفظٍ واحد، وهو هنا الحفظ نفسه داخل المحرّك. `from()` مفتوحة كما كان — المنادي
 * يفحص ختم النشر لا قيمة العمود — والحارس يكرّر فحصه برسالته: **الاعتماد نهائيّ** (ع٧).
 *
 * الحمولة: `fields` · `edited` — كما في `LawyerApproveTicketSummary`.
 *
 * @extends Transition<TicketSummary>
 */
final class FinalApproveTicketSummary extends Transition
{
    public function name(): string
    {
        return 'ticket_summary.approved';
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
        return $actor !== null && $actor->isAdmin() ? null : 'الاعتماد النهائيّ للملخّص من صلاحيّة الإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var TicketSummary $entity */
        return $entity->isApproved() ? 'اعتُمد هذا الملخّص ونُشر للعميل — لا يُعاد اعتماده.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var TicketSummary $entity */
        LawyerApproveTicketSummary::fillText($entity, $payload);

        if (! $entity->isLawyerApproved()) {
            $entity->lawyer_approved_at = now();
            $entity->lawyer_approved_by = $actor?->id;
        }
        $entity->approved_at = now();
    }

    public function record(array $payload): array
    {
        return ['edited' => (bool) ($payload['edited'] ?? false)];
    }
}
