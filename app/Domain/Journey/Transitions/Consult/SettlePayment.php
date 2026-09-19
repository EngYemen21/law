<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\ConsultPaid;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **سداد فاتورة الاستشارة — الفاتورة التي دُفعت بعينها.**
 *
 * كان `markPaid` يفحص `paid_at` وحده ثمّ يعلّم `$consult->invoice` — وهي `latestOfMany`:
 * فدفعةٌ على فاتورةٍ قديمة ألغاها تصحيح السعر تُعلِّم **الفاتورة الجديدة** مدفوعةً بلا
 * دفعة (ع٢)، ودفعةٌ على استشارةٍ ملغاة تُحييها (ع١). هنا الانتقال لا يقع إلا من
 * «بانتظار السداد»، ولا يُسوّي إلا فاتورةً تخصّ الاستشارة وتقبل الدفع.
 *
 * الحمولة: `invoice_id` (إلزاميّ للتنفيذ)، `note` (قناة التحصيل للسجلّ).
 *
 * @extends Transition<Consult>
 */
final class SettlePayment extends Transition
{
    public function name(): string
    {
        return 'consult.settle-payment';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingPayment->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingSchedule->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (! isset($payload['invoice_id'])) {
            return null; // `allowed()` يسأل بلا حمولة
        }

        $invoice = $this->invoice($entity, $payload);
        if ($invoice === null) {
            return 'الفاتورة لا تخصّ هذه الاستشارة.';
        }

        $status = InvoiceStatus::tryFrom((string) $invoice->status);

        return $invoice->paid || $status === null || ! $status->isPayable()
            ? 'الفاتورة لا تقبل السداد في حالتها الحاليّة.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $invoice = $this->invoice($entity, $payload);
        if ($invoice === null) {
            throw new \LogicException('SettlePayment بلا فاتورة.');
        }

        $entity->logAudit((string) ($payload['actor_name'] ?? $actor->name ?? 'النظام'), 'السداد', ConsultStatus::AwaitingPayment->value, (string) ($payload['note'] ?? 'مدفوع'));
        $entity->paid_at = now();

        $invoice->update(['paid' => true, 'status' => InvoiceStatus::Paid->value, 'tone' => 'b-green']);

        // سُدّدت الاستشارة ⇒ الخطوة التالية حجز الطاقم للموعد، لا حجز العميل (قرار المالك 2026-09-14)
        $ticket = $entity->ticket;
        if ($ticket !== null && $ticket->status === TicketStatus::AwaitingBooking->value) {
            $ticket->update([
                'status' => TicketStatus::AwaitingSchedule->value,
                'tone' => TicketJourney::toneFor(TicketStatus::AwaitingSchedule->value),
                'last_message' => 'سُدّدت الاستشارة — يُحدَّد موعد الجلسة مع المستشار المختص.',
                'date_label' => 'الآن',
            ]);
        }
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new ConsultPaid($entity)];
    }

    public function record(array $payload): array
    {
        return ['invoice_id' => $payload['invoice_id'] ?? null, 'note' => $payload['note'] ?? null];
    }

    /** @param  array<string, mixed>  $payload */
    private function invoice(Model $consult, array $payload): ?Invoice
    {
        return Invoice::whereKey((int) ($payload['invoice_id'] ?? 0))
            ->where('consult_id', $consult->getKey())
            ->first();
    }
}
