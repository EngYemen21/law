<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **رفض العميل لعرض أتعاب التنفيذ.**
 *
 * @extends Transition<Execution>
 */
final class RejectExecutionOffer extends Transition
{
    public function name(): string
    {
        return 'exec.reject_offer';
    }

    /**
     * **الحالات نفسها التي يقبلها `ApproveExecutionFee`.**
     *
     * الحارس الحقيقيّ هو المرحلة 5 في `ExecService::rejectOffer`؛ أمّا نصّ الحالة فكان يُقصَر على
     * «عرض الخدمة» وحده، فملفٌّ في المرحلة 5 بنصٍّ آخر يُرفض رفضُه بخطأ 422 بينما يُقبل قبولُه
     * (`acceptOffer` لا يمرّ بهذا الانتقال). و`to()` يعيد النصّ إلى «عرض الخدمة» فيتّسق مع المرحلة.
     */
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

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $entity->offer_status = 'مرفوض';
        $entity->last_action = 'رفض العميل عرض الخدمة';
    }

    public function record(array $payload): array
    {
        return [
            'action' => 'reject_offer',
        ];
    }
}
