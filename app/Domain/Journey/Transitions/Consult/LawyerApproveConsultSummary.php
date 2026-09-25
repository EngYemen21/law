<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **المرحلة الأولى من اعتماد ملخّص الجلسة — المستشار يعتمد ويرفع للإدارة.** لا يصل الموكّلَ شيء
 * حتى تعتمده الإدارة بـ`ApproveConsultSummary`.
 *
 * **لماذا وُجد هذا الانتقال؟** كانت المرحلة الأولى الشاذّة الوحيدة في معمارٍ سليم: الاعتماد
 * النهائيّ يمرّ بالمحرّك، واعتماد المستشار كتابةٌ عارية في `AiReviewOutcome` تقرأ ثمّ تكتب:
 *
 * ```php
 * if ($consult->summary_lawyer_approved_at !== null) { return false; }
 * $consult->update([...]);   // ← بلا قفل
 * ```
 *
 * فطلبان متزامنان يقرآن «فارغ» كلاهما ويمرّان كلاهما: **إشعاران متطابقان للإدارة عن اعتمادٍ
 * واحد، وصفرُ أثرٍ في سجلّ الرحلة** (قيس حيّاً 2026-09-25). والعلاج ليس قفلاً موضعيّاً بل
 * إدخالَ الإجراء المحرّكَ، فيرث القفل والحارس والسجلّ معاً.
 *
 * **والنمط مأخوذٌ من `ApproveConsultSummary` حرفاً:** العمود المراقَب طابعٌ زمنيّ لا حالةٌ نصّيّة،
 * فيُعلَن الانتقال على **الجلسة** «منتهية ⇐ منتهية» — والجلسة المختومة وحدها لها ملخّص —
 * والكتابة الفعليّة في `apply()`. ويُعفي ذلك السجلَّ من طوابع زمنٍ بوصفها «حالات».
 *
 * **ولا يعمل الحارس إلّا لأنّ `Workflow::run` يقفل الصفّ ويعيد قراءته قبل ندائه**، فالطلب
 * الثاني يرى اعتماد الأوّل مكتوباً فيُردّ.
 *
 * @extends Transition<Consult>
 */
final class LawyerApproveConsultSummary extends Transition
{
    public function name(): string
    {
        return 'consult.lawyer_approve_summary';
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
        return $actor === null ? 'اعتماد الملخّص قرارٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Consult $entity */
        if ($entity->summaryApproved()) {
            return 'اعتُمد هذا الملخّص ووصل العميل.';
        }

        if ($entity->summary_lawyer_approved_at !== null) {
            return 'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ.';
        }

        return blank($entity->summary) ? 'لا ملخّص ليُعتمد.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->summary_lawyer_approved_at = now();
        $entity->summary_lawyer_approved_by = $actor?->id;

        $entity->logAudit($actor->name ?? 'النظام', 'اعتماد ملخّص الاستشارة', 'مبدئيّ', 'اعتمده المستشار — بانتظار الإدارة');
    }
}
