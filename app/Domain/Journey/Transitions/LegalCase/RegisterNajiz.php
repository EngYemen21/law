<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **قيد الدعوى في المحكمة** ('منظورة').
 *
 * @extends Transition<LegalCase>
 */
final class RegisterNajiz extends Transition
{
    public function name(): string
    {
        return 'case.register_najiz';
    }

    public function from(): array
    {
        return [
            CaseStatus::AwaitingRegistration->value,
            CaseStatus::InCourt->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::InCourt->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isEmployee() || $actor->isAdmin())) {
            return 'تسجيل قيد الدعوى محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $caseNo = trim((string) ($payload['case_no'] ?? ''));
        if ($caseNo === '') {
            return 'رقم القضية في ناجز مطلوب.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $caseNo = trim((string) $payload['case_no']);
        $court = (string) ($payload['court'] ?? '');
        $circuit = (string) ($payload['circuit'] ?? '');
        $registeredAt = (string) ($payload['registered_at'] ?? now()->toDateString());

        $entity->najiz_case_no = $caseNo;
        $entity->court = $court;
        $entity->circuit = $circuit;
        $entity->registered_at = $registeredAt;
        $entity->tone = CaseJourney::toneFor(CaseStatus::InCourt->value);
        $entity->update_text = "قُيّدت الدعوى برقم {$caseNo} — {$circuit}";
    }

    public function record(array $payload): array
    {
        return [
            'case_no' => trim((string) ($payload['case_no'] ?? '')),
            'court' => (string) ($payload['court'] ?? ''),
            'circuit' => (string) ($payload['circuit'] ?? ''),
            'registered_at' => (string) ($payload['registered_at'] ?? now()->toDateString()),
        ];
    }
}
