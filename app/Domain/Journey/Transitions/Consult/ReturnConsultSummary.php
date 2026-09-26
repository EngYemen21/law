<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة تعيد محضر الجلسة للمستشار** — عكسُ `LawyerApproveConsultSummary`.
 *
 * كان «رفض المحضر» في مركز الاعتمادات يمسح `summary_lawyer_approved_at` كتابةً عاريةً: بلا قفلٍ
 * ولا حارس (يُمسح اعتمادُ محضرٍ نُشر للعميل فيعود إلى قائمة المحامي)، ولا سطرٍ في سجلّ الرحلة،
 * والمستشار لا يعلم أنّ محضره أُعيد ولا لماذا — فيبقى المحضر معلّقاً بين الطرفين.
 *
 * النمط من الانتقال المقابل حرفاً: العمود المراقَب طابعٌ زمنيّ، فيُعلَن الانتقال على **الجلسة**
 * «منتهية ⇐ منتهية» والكتابة في `apply()`. والسبب إلزاميّ ويُحفظ في السجلّ؛ وإبلاغ المستشار عند
 * المستدعي (`ApprovalsController::reject`) بعد نجاح الانتقال.
 *
 * @extends Transition<Consult>
 */
final class ReturnConsultSummary extends Transition
{
    public function name(): string
    {
        return 'consult.return_summary';
    }

    public function column(): string
    {
        return 'session';
    }

    public function from(): array
    {
        return [SessionState::Ended->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return SessionState::Ended->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'إعادة محضر الجلسة من صلاحيّة الإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Consult $entity */
        return match (true) {
            $entity->summaryApproved() => 'اعتُمد هذا المحضر ووصل العميل — لا يُعاد.',
            $entity->summary_lawyer_approved_at === null => 'لا محضر بانتظار الإدارة لهذه الجلسة.',
            blank($payload['reason'] ?? null) => 'اذكر سبب الإعادة — يصل المستشار مع المحضر.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->summary_lawyer_approved_at = null;
        $entity->summary_lawyer_approved_by = null;

        $entity->logAudit($actor->name ?? 'الإدارة', 'إعادة محضر الجلسة للمستشار', 'بانتظار الإدارة', trim((string) $payload['reason']));
    }

    public function record(array $payload): array
    {
        return ['reason' => trim((string) ($payload['reason'] ?? ''))];
    }
}
