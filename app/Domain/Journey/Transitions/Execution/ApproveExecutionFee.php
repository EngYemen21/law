<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\Setting;
use App\Models\User;
use App\Support\ExecFee;
use App\Support\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتماد الإدارة لأتعاب ملف التنفيذ وتقديم العرض للعميل** (المرحلة 5).
 *
 * @extends Transition<Execution>
 */
final class ApproveExecutionFee extends Transition
{
    public function name(): string
    {
        return 'exec.approve_fee';
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
        return ExecutionStatus::ServiceOffer->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin()) {
            return 'اعتماد الأتعاب من صلاحية الإدارة حصراً.';
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

        $adjustedFee = isset($payload['adjusted_fee']) && (int) $payload['adjusted_fee'] > 0
            ? (int) $payload['adjusted_fee']
            : (int) $entity->fee;

        if ($entity->feeMode() === 'percent') {
            if ((float) $entity->collection_fee_pct < 0.01) {
                return 'حدّد نسبة الأتعاب من المحصّل قبل اعتماد العرض.';
            }
            $maxPct = SettingsRegistry::int('exec_max_collection_pct');
            if ((float) $entity->collection_fee_pct > $maxPct) {
                return "نسبة الأتعاب من المحصّل تتجاوز السقف المعتمد ({$maxPct}٪) — عدّلها قبل الاعتماد.";
            }
        } else {
            if ($adjustedFee < 1) {
                return 'حدّد أتعاب التنفيذ قبل اعتماد العرض.';
            }
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        if ($entity->feeMode() === 'percent') {
            $entity->fee = 0;
            $entity->vat = 0;
        } else {
            $fee = isset($payload['adjusted_fee']) && (int) $payload['adjusted_fee'] > 0
                ? (int) $payload['adjusted_fee']
                : (int) $entity->fee;
            $entity->fee = $fee;
            $entity->vat = Setting::vatOn($fee);
        }

        $entity->fee_approved = true;
        $entity->offer_status = null;
        $status = ExecutionStatus::ServiceOffer;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->last_action = 'اعتمدت الإدارة الأتعاب وأُرسل العرض';
    }

    public function record(array $payload): array
    {
        return [
            'adjusted_fee' => $payload['adjusted_fee'] ?? null,
        ];
    }
}
