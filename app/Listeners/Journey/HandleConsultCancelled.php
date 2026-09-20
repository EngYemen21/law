<?php

namespace App\Listeners\Journey;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultCancelled;
use App\Events\TicketStatusBroadcast;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit;
use App\Support\Live;
use App\Support\Notify;

/** آثار إلغاء طلب الاستشارة — بعد التزام الإلغاء، لا داخله. */
final class HandleConsultCancelled
{
    public function handle(ConsultCancelled $event): void
    {
        $consult = $event->consult;

        $reason = $event->reason !== null && trim($event->reason) !== '' ? trim($event->reason) : null;
        $description = "ألغى {$event->actorName} طلب الاستشارة {$consult->ref} (كانت «{$event->from}»)";
        if ($reason !== null) {
            $description .= " — سبب الإلغاء: {$reason}";
        }
        $description .= '.';

        Audit::log(
            action: 'إلغاء طلب استشارة',
            description: $description,
            category: 'استشارات',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الحالة' => $event->from],
            afterState: array_filter(['الحالة' => 'ملغاة', 'السبب' => $reason]),
        );

        Live::push(new ConsultStatusBroadcast($consult));
        if ($consult->ticket !== null) {
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }

        $reasonSnippet = $reason !== null ? " للسبب: «{$reason}»." : '.';
        Notify::send($consult->user_id, 'info', 't-grey', "أُلغي طلب استشارتك ({$consult->ref}){$reasonSnippet} إن كنت قد سددت فسيتواصل معك المكتب بشأن الاسترداد.");

        if ($consult->assigned_lawyer_id !== null) {
            $appointmentDetails = ($consult->day && $consult->time)
                ? " المقررة في {$consult->day} الساعة {$consult->time}"
                : '';
            Notify::send(
                $consult->assigned_lawyer_id,
                'cal',
                't-amber',
                "تم إلغاء موعد جلسة الاستشارة ({$consult->ref}) المسندة إليك{$appointmentDetails}{$reasonSnippet}"
            );
        }

        // أتمتة تنبيه الاسترداد المالي للإدارة والمحاسبة في حال كانت الاستشارة مدفوعة مسبقاً
        $isPaid = $consult->paid_at !== null
            || Invoice::where('consult_id', $consult->id)->where('paid', true)->exists()
            || in_array($event->from, [
                ConsultStatus::AwaitingSchedule->value,
                ConsultStatus::AwaitingAppointmentApproval->value,
            ], true);

        if ($isPaid) {
            $paidInvoice = Invoice::where('consult_id', $consult->id)->where('paid', true)->latest('id')->first();
            $invoiceText = $paidInvoice ? " على الفاتورة {$paidInvoice->number}" : '';
            $amountText = $paidInvoice ? " بمبلغ {$paidInvoice->total} ر.س" : ($consult->total ? " بمبلغ {$consult->total} ر.س" : '');

            Audit::log(
                action: 'استرداد مالي مستحق',
                description: "أُلغي طلب الاستشارة {$consult->ref} بعد السداد{$invoiceText}{$amountText} — يتطلّب تدخلاً مالياً واسترداداً للعميل.",
                category: 'مالية وفواتير',
                severity: 'critical',
                auditable: $consult,
            );

            foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
                Notify::send(
                    $adminId,
                    'card',
                    't-red',
                    "طلب الاستشارة {$consult->ref} أُلغي بعد السداد{$invoiceText}{$amountText} — يتطلّب استرداداً مالياً للعميل."
                );
            }
        }
    }
}
