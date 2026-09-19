<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **رفع الدعوى في ناجز** ('بانتظار القيد').
 *
 * @extends Transition<LegalCase>
 */
final class FileNajiz extends Transition
{
    public function name(): string
    {
        return 'case.file_najiz';
    }

    public function from(): array
    {
        return [
            CaseStatus::InPreparation->value,
            CaseStatus::AwaitingRegistration->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::AwaitingRegistration->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isEmployee() || $actor->isAdmin())) {
            return 'تسجيل رفع الدعوى محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var LegalCase $entity */
        if ($entity->pleading_status !== 'approved') {
            return 'تُرفع الدعوى بعد الاعتماد النهائيّ للائحة.';
        }

        $requestNo = trim((string) ($payload['request_no'] ?? ''));
        if ($requestNo === '') {
            return 'رقم الطلب في ناجز مطلوب.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $requestNo = trim((string) $payload['request_no']);
        $filedAt = (string) ($payload['filed_at'] ?? now()->toDateString());

        $entity->najiz_request_no = $requestNo;
        $entity->filed_at = $filedAt;
        $entity->tone = CaseJourney::toneFor(CaseStatus::AwaitingRegistration->value);
        $entity->update_text = "رُفعت الدعوى في ناجز (رقم الطلب {$requestNo}) — بانتظار قيد المحكمة";
    }

    public function record(array $payload): array
    {
        return [
            'request_no' => trim((string) ($payload['request_no'] ?? '')),
            'filed_at' => (string) ($payload['filed_at'] ?? now()->toDateString()),
        ];
    }
}
