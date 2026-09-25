<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Domain\Journey\Transitions\Ticket\TicketBookingWithdrawn;
use App\Domain\Journey\Workflow;
use App\Events\Journey\ConsultCancelled;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إلغاء طلب استشارة قبل الجلسة — والإلغاء يُغلق ما يتبعه.**
 *
 * كان `cancelRequest` يكتب «ملغاة» وحدها: فاتورتها تبقى «مستحقة» فيسدّدها العميل
 * فتعود الاستشارة الملغاة إلى «بانتظار تحديد الموعد» (ع١)، وتذكرتها لا ترتدّ فتتجمّد
 * (ع١٠). الإلغاء هنا يُلغي الفواتير غير المدفوعة والموعد المعلَّق، ويُرجع التذكرة
 * إلى «الرأي القانوني» ليُطلب غيرها.
 *
 * @extends Transition<Consult>
 */
final class CancelRequest extends Transition
{
    public function name(): string
    {
        return 'consult.cancel';
    }

    public function from(): array
    {
        return ConsultStatus::preSession();
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::Cancelled->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'الإلغاء قرارٌ بشريّ.' : null;
    }

    public function record(array $payload): array
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';

        return array_filter(['reason' => $reason !== '' ? $reason : null]);
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';
        $afterState = ConsultStatus::Cancelled->value;
        if ($reason !== '') {
            $afterState .= " (السبب: {$reason})";
        }

        $entity->logAudit($actor->name ?? 'النظام', 'الحالة', (string) $entity->status, $afterState);

        Invoice::where('consult_id', $entity->id)->where('paid', false)->get()
            ->each(fn (Invoice $invoice) => $invoice->update([
                'status' => InvoiceStatus::Cancelled->value,
                'tone' => 'b-red',
            ]));

        $appointment = $entity->appointment;
        if ($appointment !== null && $appointment->status === AppointmentStatus::PendingApproval->value) {
            $appointment->update([
                'status' => AppointmentStatus::Cancelled->value, 'tone' => 'b-grey', 'when_kind' => 'past',
                // الموعد الملغى يحمل ذاكرته كما في إعادة الجدولة
                'consult_id' => $entity->getKey(), 'cancelled_at' => now(),
                'cancel_reason' => 'أُلغي طلب الاستشارة'.($reason !== '' ? ' — '.$reason : ''),
            ]);
        }

        $ticket = $entity->ticket;
        if ($ticket === null
            || ! (new TicketBookingWithdrawn)->accepts((string) $ticket->status)
            || $ticket->consults()->whereKeyNot($entity->id)->whereNotIn('status', [ConsultStatus::Cancelled->value, ConsultStatus::Ended->value])->exists()
            || ! $ticket->summary?->isApproved()) {
            return;
        }

        // والتذكرة بانتقالها هي — كانت تُكتب هنا بلا سطرٍ في رحلتها
        Workflow::run(new TicketBookingWithdrawn, $ticket, $actor, ['consult_ref' => $entity->ref]);
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? trim($payload['reason']) : '';

        return [new ConsultCancelled($entity, $from, $actor->name ?? 'النظام', $reason !== '' ? $reason : null)];
    }
}
