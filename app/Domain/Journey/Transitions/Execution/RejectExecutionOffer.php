<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Domain\Journey\Transitions\Invoice\CancelInvoice;
use App\Domain\Journey\Workflow;
use App\Models\Execution;
use App\Models\Invoice;
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
     * **الحالات نفسها التي يقبلها `ApproveExecutionFee` و`SetExecutionFee`.**
     *
     * الحارس الحقيقيّ هو المرحلة 5 أو 6 (قبل السداد) في `ExecService::rejectOffer`؛ أمّا نصّ الحالة فكان يُقصَر على
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
            ExecutionStatus::Payment->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::ServiceOffer->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->paid || $entity->effectiveStage() >= ExecutionStatus::PendingNajiz->stage()) {
            return 'فُتح ملفّ التنفيذ بالفعل — لا يوجد عرض للرفض.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        // إلغاء كلّ فاتورةٍ غير مسدَّدة على ملفّ التنفيذ عند رفض العرض
        Invoice::where('exec_id', $entity->id)
            ->where('paid', false)
            ->whereIn('status', (new CancelInvoice)->from())
            ->get()
            ->each(fn (Invoice $invoice) => Workflow::run(new CancelInvoice, $invoice, $actor, [
                'reason' => "رفض العميل عرض ملف التنفيذ {$entity->number}",
            ]));

        $status = ExecutionStatus::ServiceOffer;
        $entity->stage = $status->stage();
        $entity->status = $status->value;
        $entity->tone = $status->tone();
        $entity->offer_status = 'مرفوض';
        $entity->pay_plan = null;
        $entity->installments_total = 1;
        $entity->installments_paid = 0;
        $entity->invoice_no = null;
        $entity->last_action = 'رفض العميل عرض الخدمة';
    }

    public function record(array $payload): array
    {
        return [
            'action' => 'reject_offer',
        ];
    }
}
