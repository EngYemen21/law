<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **تفعيل القضية** ('قيد التحضير').
 *
 * @extends Transition<LegalCase>
 */
final class ActivateCase extends Transition
{
    public function name(): string
    {
        return 'case.activate';
    }

    public function from(): array
    {
        return [
            CaseStatus::AwaitingFeeApproval->value,
            CaseStatus::AwaitingFeePayment->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return CaseStatus::InPreparation->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $entity->tone = CaseJourney::toneFor(CaseStatus::InPreparation->value);
        $entity->update_text = 'تم تفعيل القضية؛ يجهّز الفريق خطة العمل واللائحة';
        $entity->pleading_status = 'pending_lawyer';
    }

    public function record(array $payload): array
    {
        return [
            'pay_plan' => $payload['pay_plan'] ?? 'full',
            'fee_status' => $payload['fee_status'] ?? 'paid',
        ];
    }
}
