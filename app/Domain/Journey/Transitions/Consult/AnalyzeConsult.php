<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **تحليل الفريق القانونيّ — بعد نشر الموعد، لا قبله ولا أثناء الجلسة.**
 *
 * كان الحارس يمنع المغلقة وحدها: تحليلُ طلبٍ «بانتظار التسعير» ينقله إلى «بانتظار
 * اعتماد الموظف» فيسقط من طابور التسعير ولا تصل فاتورته (ع١٩) — صنفُ حادثة CN-2026-4504
 * نفسه الذي حُرس في `refer` و`requestDocs`.
 *
 * نداءُ النموذج يقع **قبل** الانتقال (شبكة خارج المعاملة)، والحمولة نتيجته:
 * `class` · `summary` · `lawyer` · `done` (bool) · `source` · `missing` (list).
 *
 * @extends Transition<Consult>
 */
final class AnalyzeConsult extends Transition
{
    public function name(): string
    {
        return 'consult.analyze';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingEmployeeApproval->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $status = ConsultStatus::tryFrom((string) $entity->status);

        return match (true) {
            $status?->isClosed() === true => 'الاستشارة انتهت أو أُلغيت — لا يُعاد تحليلها.',
            $status?->isPreSession() === true => 'الطلب ما زال في دورة الحجز — يُحلَّل بعد التسعير والسداد ونشر الموعد.',
            $status === ConsultStatus::InSession => 'الجلسة منعقدة الآن — يُحلَّل الملفّ قبلها أو بعدها.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $before = (string) $entity->status;
        $done = (bool) ($payload['done'] ?? false);

        $entity->ai_class = $payload['class'] ?? null;
        $entity->ai_summary = $payload['summary'] ?? null;
        $entity->ai_lawyer = $payload['lawyer'] ?? null;
        $entity->ai_done = $done;
        $entity->ai_source = $payload['source'] ?? null;
        // دمجٌ لا استبدال — لا تُدهس نواقص كتبها الموظّف يدويّاً وأُشعر بها العميل
        $entity->missing = array_values(array_unique(array_merge(
            $entity->missing ?? [],
            is_array($payload['missing'] ?? null) ? $payload['missing'] : []
        )));

        $entity->logAudit($actor->name ?? 'النظام', 'الحالة', $before, 'قيد معالجة الفريق القانوني');
        $entity->logAudit('النظام', 'تحليل الفريق القانوني', '—', $done ? 'اكتمل' : 'تعذّر — يلزم إعداد يدويّ');
        $entity->logAudit('النظام', 'الحالة', 'قيد معالجة الفريق القانوني', ConsultStatus::AwaitingEmployeeApproval->value);
    }
}
