<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Transition;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **رفع طلب فتح تنفيذ الحكم للإدارة العليا** (قرار المالك 2026-09-29) — من المحامي المسنَد أو الموظّف،
 * بسببٍ مكتوب والمبلغ المحكوم به (قرار 2026-09-30) والمنفَّذ ضده (قرار 2026-10-02). الحالة لا تتغيّر: الطلب قائمٌ على القضيّة حتى تعتمده الإدارة (فيُفتح الملفّ) أو ترفضه.
 *
 * @extends Transition<LegalCase>
 */
final class RequestCaseExecution extends Transition
{
    public const REASON_MIN = 10;

    public function name(): string
    {
        return 'case.request_execution';
    }

    /** بعد صدور الحكم (ومنها «مغلقة» — قرار المالك 2026-09-11)؛ والمؤرشفة لا. */
    public function from(): array
    {
        return CaseJourney::POST_JUDGMENT;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $entity->getAttribute('status');
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! in_array($actor->role, [Role::Lawyer, Role::Employee], true)) {
            return 'رفع طلب التنفيذ للمحامي أو الموظّف — والإدارة العليا تفتحه مباشرةً.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var LegalCase $entity */
        if ($entity->execution()->exists()) {
            return 'لهذه القضية طلب تنفيذٍ قائم.';
        }
        if ($entity->execution_requested_at !== null) {
            return 'طلب فتح التنفيذ مرفوعٌ للإدارة وبانتظار قرارها.';
        }
        if (mb_strlen(trim((string) ($payload['reason'] ?? ''))) < self::REASON_MIN) {
            return 'اكتب سبب طلب التنفيذ ('.self::REASON_MIN.' أحرف على الأقل).';
        }
        // المبلغ المحكوم به يصير «قيمة المطالبة» في ملفّ التنفيذ، وعليه يُقاس كلّ تحصيل
        if ((int) ($payload['amount'] ?? 0) < 1) {
            return 'أدخل المبلغ المحكوم به (ريال) — رقماً صحيحاً أكبر من صفر.';
        }
        // يصير مبلغ ملفّ التنفيذ عند الاعتماد — فلا يُقبل ما لا يتّسع له الملفّ
        if ((int) $payload['amount'] > Execution::MAX_CLAIM_AMOUNT) {
            return 'المبلغ المحكوم به يتجاوز الحدّ الأعلى ('.number_format(Execution::MAX_CLAIM_AMOUNT).' ريال).';
        }

        // يصير «المنفَّذ ضده» في ملفّ التنفيذ — القاعدة الواحدة لاسمه (`Execution::defendantError`)
        return Execution::defendantError((string) ($payload['defendant'] ?? ''));
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $entity->forceFill([
            'execution_requested_at' => now(),
            'execution_requested_by' => $actor?->id,
            'execution_request_reason' => trim((string) $payload['reason']),
            'execution_request_amount' => (int) $payload['amount'],
            'execution_request_defendant' => trim((string) $payload['defendant']),
        ]);
    }

    public function record(array $payload): array
    {
        return ['reason' => $payload['reason'] ?? '', 'amount' => (int) ($payload['amount'] ?? 0), 'defendant' => trim((string) ($payload['defendant'] ?? ''))];
    }
}
