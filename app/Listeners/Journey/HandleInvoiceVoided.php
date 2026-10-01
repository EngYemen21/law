<?php

namespace App\Listeners\Journey;

use App\Events\Journey\InvoiceVoided;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Support\CaseFee;
use App\Support\ExecFee;

/**
 * **قسطٌ أُسقط من خطّة تقسيط** — يُعاد عدّ الخطّة فتكتمل إن لم يبقَ فيها مستحقّ. كانت الخطّة لا تكتمل
 * أبداً بعد إلغاء قسطٍ أو إعدامه (تدقيق الدفع B). والفاتورة خارج الخطط لا أثر لها هنا.
 */
final class HandleInvoiceVoided
{
    public function handle(InvoiceVoided $event): void
    {
        $invoice = $event->invoice;
        if ($invoice->installment_no === null) {
            return;
        }

        if ($invoice->case_id !== null && ($case = LegalCase::find($invoice->case_id)) && $case->pay_plan === 'install') {
            CaseFee::syncInstallmentPlan($case);
        } elseif ($invoice->exec_id !== null && ($exec = Execution::find($invoice->exec_id)) && $exec->payPlan() === 'install') {
            ExecFee::syncInstallmentPlan($exec);
        }
    }
}
