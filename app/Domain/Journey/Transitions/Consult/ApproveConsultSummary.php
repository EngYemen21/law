<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **الاعتماد النهائيّ لملخّص الجلسة — يُفتح للعميل.** يناديه `AiReviewOutcome::approveConsultSummary`
 * وحده (بوّابة الصندوق وشاشة الملفّ معاً).
 *
 * العمود المراقَب هنا `summary_approved_at` لا حالةٌ نصّيّة، فالانتقال يُعلَن على **الجلسة**:
 * «منتهية ⇐ منتهية» — الجلسة المختومة وحدها لها ملخّص (ع٢٢)، فهي `from()` نفسها — والكتابة
 * الفعليّة في `apply()`. ويُعفي ذلك السجلَّ من طوابع زمنٍ بوصفها «حالات».
 *
 * الحارسان (معتمَدٌ أصلاً · بلا نصّ) يسألهما المنادي قبل النداء ويعود بـ`false` صامتاً كما كان؛
 * وهما هنا شبكةُ أمان.
 *
 * @extends Transition<Consult>
 */
final class ApproveConsultSummary extends Transition
{
    public function name(): string
    {
        return 'consult.approve_summary';
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

        return blank($entity->summary) ? 'لا ملخّص ليُعتمد.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->summary_approved_at = now();
        $entity->summary_approved_by = $actor?->id;
        // اعتماد الإدارة لملخّصٍ لم يعتمده محامٍ يُعدّ اعتماداً للمرحلتين
        $entity->summary_lawyer_approved_at ??= now();
        $entity->summary_lawyer_approved_by ??= $actor?->id;

        $entity->logAudit($actor->name ?? 'النظام', 'اعتماد ملخّص الاستشارة', 'مبدئيّ', 'معتمد');
    }
}
