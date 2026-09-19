<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\Setting;
use App\Models\User;
use App\Support\ExecFee;
use Illuminate\Database\Eloquent\Model;

/**
 * **تحديد وتسجيل أتعاب ملف التنفيذ.**
 *
 * @extends Transition<Execution>
 */
final class SetExecutionFee extends Transition
{
    public function name(): string
    {
        return 'exec.set_fee';
    }

    public function from(): array
    {
        return [
            ExecutionStatus::UnderStudy->value,
            ExecutionStatus::FeeEstimation->value,
            ExecutionStatus::AdminApproval->value,
            ExecutionStatus::ServiceOffer->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        $isAdmin = (bool) ($payload['is_admin'] ?? false);

        return $isAdmin
            ? ExecutionStatus::ServiceOffer->value
            : ExecutionStatus::AdminApproval->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isAdmin() || $actor->isLawyer())) {
            return 'تحديد الأتعاب من صلاحية المحامي والإدارة فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->decision === 'مرفوض') {
            return 'هذا الطلب مرفوض بعد الدراسة — لا يُسعَّر ولا يُعرَض.';
        }

        if ($entity->assigned_lawyer_id === null) {
            return 'يلزم إسناد محامٍ لملفّ التنفيذ قبل تحديد الأتعاب — الإسناد من الإدارة أو من الموظّف المخوَّل.';
        }

        $fee = (int) ($payload['fee'] ?? 0);
        $feeMode = (string) ($payload['fee_mode'] ?? 'fixed');

        if ($feeMode === 'fixed' && $fee <= 0) {
            return 'مبلغ الأتعاب الثابتة يجب أن يكون أكبر من الصفر.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $isAdmin = $actor?->isAdmin() || (bool) ($payload['is_admin'] ?? false);
        $targetStatus = $isAdmin ? ExecutionStatus::ServiceOffer : ExecutionStatus::AdminApproval;

        $fee = (int) ($payload['fee'] ?? 0);
        $duration = (string) ($payload['duration'] ?? '30-45 يوم');
        $feeMode = (string) ($payload['fee_mode'] ?? 'fixed');
        $feePct = isset($payload['collection_fee_pct']) && $payload['collection_fee_pct'] !== null
            ? (float) $payload['collection_fee_pct']
            : null;

        $percent = $feeMode === 'percent';
        $entity->fee = $percent ? 0 : $fee;
        $entity->vat = $percent ? 0 : Setting::vatOn($fee);
        $entity->duration = $duration ?: '30-45 يوم';
        $entity->fee_mode = $percent ? 'percent' : 'fixed';
        $entity->collection_fee_pct = $percent ? $feePct : null;
        $entity->pay_plan = null;
        $entity->pay_method = ExecFee::payMethodLabel($entity);
        $entity->fee_approved = $isAdmin;
        if ($isAdmin) {
            $entity->offer_status = null;
        }

        $entity->stage = $targetStatus->stage();
        $entity->status = $targetStatus->value;
        $entity->tone = $targetStatus->tone();
        $entity->last_action = $isAdmin
            ? 'حدّدت الإدارة الأتعاب واعتمدتها وأُرسل العرض'
            : 'أُرسلت الأتعاب لاعتماد الإدارة';
    }

    public function record(array $payload): array
    {
        return [
            'fee' => (int) ($payload['fee'] ?? 0),
            'fee_mode' => $payload['fee_mode'] ?? 'fixed',
            'collection_fee_pct' => $payload['collection_fee_pct'] ?? null,
            'duration' => $payload['duration'] ?? null,
        ];
    }
}
