<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **تحديد أتعاب القضية** ('بانتظار سداد الأتعاب' أو 'قيد التحضير' إن كانت صفراً).
 *
 * @extends Transition<LegalCase>
 */
final class SetFee extends Transition
{
    public function name(): string
    {
        return 'case.set_fee';
    }

    public function from(): array
    {
        return [
            CaseStatus::AwaitingFeeApproval->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        $fee = (int) ($payload['fee'] ?? 0);

        return $fee === 0 ? CaseStatus::InPreparation->value : CaseStatus::AwaitingFeePayment->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin()) {
            return 'تحديد الأتعاب من صلاحية الإدارة العليا فقط.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (! isset($payload['fee']) || ! is_numeric($payload['fee']) || (int) $payload['fee'] < 0) {
            return 'مبلغ الأتعاب غير صالح.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $fee = (int) $payload['fee'];
        $lawyerPct = (int) ($payload['lawyer_pct'] ?? 0);
        $lawyerFee = (int) ($payload['lawyer_fee'] ?? 0);

        $entity->fee = $fee;
        $entity->lawyer_pct = $lawyerPct;
        $entity->lawyer_fee = $lawyerFee;

        if ($fee === 0) {
            $entity->fee_status = 'waived';
            $entity->invoice_text = 'قضية بلا أتعاب';
            $entity->update_text = 'اعتمدت الإدارة القضية بلا أتعاب';
            $entity->tone = CaseJourney::toneFor(CaseStatus::InPreparation->value);
            // حالة اللائحة يضبطها `CaseFee::activate` الذي يتلو هذا الانتقال — ضبطُها هنا كان يجعله يظنّ
            // القضيّة مفعّلةً سابقاً فيعود بلا مسوّدة لائحة ولا رسالة تفعيل ولا تدقيق (تدقيق 2026-09-29)
        } else {
            $vat = (int) ($payload['vat'] ?? 0);
            $total = (int) ($payload['total'] ?? ($fee + $vat));
            $entity->fee_status = 'pending_payment';
            $entity->invoice_text = "أتعاب القضية {$fee} ر.س + ضريبة {$vat} = {$total} ر.س";
            $entity->update_text = 'حدّدت الإدارة الأتعاب، بانتظار سداد العميل لتفعيل القضية';
            $entity->tone = CaseJourney::toneFor(CaseStatus::AwaitingFeePayment->value);
        }
    }

    public function record(array $payload): array
    {
        return [
            'fee' => (int) ($payload['fee'] ?? 0),
            'lawyer_pct' => (int) ($payload['lawyer_pct'] ?? 0),
            'lawyer_fee' => (int) ($payload['lawyer_fee'] ?? 0),
        ];
    }
}
