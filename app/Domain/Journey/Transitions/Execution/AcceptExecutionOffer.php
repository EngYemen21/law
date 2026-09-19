<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **قبول العميل لعرض أتعاب التنفيذ والانتقال للسداد أو الرفع.**
 *
 * @extends Transition<Execution>
 */
final class AcceptExecutionOffer extends Transition
{
    public function name(): string
    {
        return 'exec.accept_offer';
    }

    /**
     * **الحارس الحقيقيّ هو المرحلة 5 لا نصّ الحالة** — كما يحرس `ExecService::acceptOffer`.
     *
     * كان `from()` «عرض الخدمة» وحده، والمسار الحيّ (`ExecFee::openOnAcceptance`) كان يكتب المرحلة
     * 6 بلا شرطٍ على النصّ؛ فملفٌّ في المرحلة 5 بنصٍّ قديم (انظر `RejectExecutionOffer`) كان يُقبل
     * عرضه ثمّ يصير 422 هنا — **بعد** أن صدرت فاتورته في معاملةٍ سابقة. فالقبول مفتوح النصّ،
     * والمرحلة في `guard()`: **ما قبل فتح الملفّ** (دون 7) — حارس المرحلة 5 عند `acceptOffer`،
     * و`openOnAcceptance` يُنادى أيضاً لملفٍّ بلغ 6 (انظر تعليقها)؛ والمفتوح لا يُسحب إلى الوراء.
     */
    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        /** @var Execution $entity */
        return $entity->feeMode() === 'percent'
            ? ExecutionStatus::PendingNajiz->value
            : ExecutionStatus::Payment->value;
    }

    /**
     * **الفاتورة تسبق القبول بالأتعاب الثابتة — ولا يُصدرها الانتقال.**
     *
     * كان `apply()` ينادي `ExecFee::ensureInvoice`، وهي دالّةٌ غير موجودة، فأيُّ استعمالٍ للانتقال
     * يسقط بخطأٍ قاتل. والإصدار الحقيقيّ في `ExecFee::openOnAcceptance` داخل معاملةٍ مقفلة تمنع
     * الفاتورة المزدوجة؛ فالانتقال يُنادى بعد صدورها، ويرفض ما قبله برسالةٍ مفهومة بدل أن يوصل
     * الملفّ إلى «السداد» بلا فاتورة. النسبيّ لا فاتورة له عند القبول أصلاً (لا مقدَّم).
     */
    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        if ($entity->effectiveStage() >= ExecutionStatus::PendingNajiz->stage()) {
            return 'فُتح ملفّ التنفيذ — لا عرض بانتظار القبول.';
        }

        return $entity->feeMode() !== 'percent' && blank($entity->invoice_no)
            ? 'لم تصدر فاتورة أتعاب التنفيذ بعد — تُصدَر الفاتورة قبل تسجيل قبول العرض.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $entity->offer_status = 'مقبول';

        if ($entity->feeMode() === 'percent') {
            $entity->paid = true;
            $entity->paid_at = now();
            $status = ExecutionStatus::PendingNajiz;
            $entity->stage = $status->stage();
            $entity->status = $status->value;
            $entity->tone = $status->tone();
            $entity->last_action = 'قبول العرض — بانتظار الرفع في ناجز';
        } else {
            // الفاتورة صادرةٌ قبل هذا الانتقال (يحرسها `guard()`)
            $status = ExecutionStatus::Payment;
            $entity->stage = $status->stage();
            $entity->status = $status->value;
            $entity->tone = $status->tone();
            // الوصف من المنادي إن مرّره (`ExecFee::openOnAcceptance` يصف صدور الفاتورة)
            $entity->last_action = (string) ($payload['last_action'] ?? 'قبول العرض — بانتظار سداد الأتعاب');
        }
    }

    public function record(array $payload): array
    {
        return [
            'action' => 'accept_offer',
        ];
    }
}
