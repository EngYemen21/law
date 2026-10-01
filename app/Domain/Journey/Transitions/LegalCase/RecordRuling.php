<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\ArabicCount;
use App\Support\CaseJourney;
use App\Support\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * **تسجيل صدور الحكم القضائي** ('صدر الحكم').
 *
 * @extends Transition<LegalCase>
 */
final class RecordRuling extends Transition
{
    /** مهلة الاستئناف بالأيّام — الافتراض المُعلَن لـ`appeal_deadline_days` (قرار المالك 2026-10-01؛ كانت ٣٠ منقوشة). */
    public const APPEAL_DAYS = 30;

    public function name(): string
    {
        return 'case.record_ruling';
    }

    public function from(): array
    {
        return [
            CaseStatus::InCourt->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::Judged->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! ($actor->isLawyer() || $actor->isEmployee() || $actor->isAdmin())) {
            return 'تسجيل الحكم محصور في الفريق المختص.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $ruling = trim((string) ($payload['ruling'] ?? ''));
        if ($ruling === '') {
            return 'منطوق الحكم مطلوب لتسجيله.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $entity->ruling = (string) $payload['ruling'];
        $entity->appeal_status = 'pending_appeal';
        $days = SettingsRegistry::int('appeal_deadline_days');
        $entity->appeal_deadline_at = now()->addDays($days)->toDateString();
        $entity->tone = CaseJourney::toneFor(CaseStatus::Judged->value);
        $entity->update_text = 'صدر الحكم في القضية — بدأت مهلة الاستئناف ('.ArabicCount::days($days).')';
    }

    public function record(array $payload): array
    {
        return [
            'ruling' => $payload['ruling'] ?? '',
        ];
    }
}
