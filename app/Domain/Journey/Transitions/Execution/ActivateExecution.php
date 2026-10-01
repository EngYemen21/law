<?php

namespace App\Domain\Journey\Transitions\Execution;

use App\Domain\Journey\Enums\ExecutionOfferStatus;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Transition;
use App\Models\Execution;
use App\Models\User;
use App\Support\ReferenceNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * **فتح ملفّ التنفيذ ⇐ المرحلة 7 «بانتظار الرفع في ناجز»** — يناديه `ExecFee::openFile` وحده.
 *
 * كان الفتح خطوتين: معاملةٌ مقفلة تكتب السداد ورقم الملفّ، ثمّ `ExecService::sync` يكتب المرحلة
 * والحالة خارج المحرّك. هنا خطوةٌ واحدة تحت قفل المحرّك نفسه.
 *
 * **ثلاثة أسباب تفتح الملفّ** (سدادٌ كامل · أوّل قسط · قبول عرضٍ نسبيّ) فالحالة المصدر لا تُعرف
 * سلفاً: `from()` مفتوح، والحارس هو ما كان يحرس به `openFile` تحت القفل — ملفٌّ غير مدفوع لم يبلغ
 * المرحلة 7. فلا يُسحب ملفٌّ بلغ الثامنة إلى السابعة، ولا يُسَكّ له رقمٌ ثانٍ.
 *
 * الحمولة: `last_action` — تصف السبب كما يراه المكتب.
 *
 * @extends Transition<Execution>
 */
final class ActivateExecution extends Transition
{
    public function name(): string
    {
        return 'exec.activate';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ExecutionStatus::PendingNajiz->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Execution $entity */
        return $entity->paid || $entity->effectiveStage() >= ExecutionStatus::PendingNajiz->stage()
            ? 'فُتح ملفّ التنفيذ بالفعل.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Execution $entity */
        $entity->paid = true;
        $entity->paid_at = now();
        // ⚠️ رقمٌ داخليّ لا صادرٌ عن جهة قضائيّة — مولّدٌ يفحص التفرّد، لا عشوائيّ بمدى ثلاثين
        $entity->exec_no = $entity->exec_no ?: ReferenceNumber::next(Execution::class, 'exec_no', 'EXE-TN');
        $entity->offer_status = ExecutionOfferStatus::Accepted->value;

        $status = ExecutionStatus::PendingNajiz;
        $entity->stage = $status->stage();
        $entity->tone = $status->tone();
        $entity->last_action = (string) ($payload['last_action'] ?? 'سداد الأتعاب — بانتظار الرفع في ناجز');
    }

    public function record(array $payload): array
    {
        return ['last_action' => $payload['last_action'] ?? null];
    }
}
