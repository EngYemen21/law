<?php

namespace App\Support;

use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Services\MoyasarService;
use Illuminate\Support\Facades\Log;

/**
 * يسوّي دفعة Moyasar مع فاتورة النظام ثم يحرّك حالة المجال — مصدر واحد يخدم الـwebhook والـcallback (DRY).
 * دفاعيّ: يتحقّق من الحالة والمبلغ والعملة قبل التعليم، وidempotent ضدّ التكرار.
 */
class PaymentReconciler
{
    /**
     * @param  array<string,mixed>  $payment  كائن دفعة ميسّر (data من الحدث أو ناتج fetchPayment)
     * @param  string  $channel  قناة ورود الحدث: webhook | callback (لتدقيق الدفتر)
     * @return bool true إن سُوّيت (أو كانت مسوّاة)، false عند تعذّر المطابقة/عدم تطابق المبلغ/حالة غير مدفوعة
     */
    public static function settle(array $payment, string $channel = 'callback'): bool
    {
        // سجّل الدفتر أوّلاً (قبل الحرّاس) فيلتقط حتى المحاولات المرفوضة — تدقيق ومطابقة.
        $invoice = self::resolveInvoice($payment);
        $ledger = self::record($payment, $invoice, $channel);

        if (($payment['status'] ?? null) !== 'paid') {
            return false;
        }

        if ($invoice === null) {
            Log::warning('moyasar.reconcile.invoice_not_found', ['payment_id' => $payment['id'] ?? null]);

            return false;
        }

        // تحقّق تطابق المبلغ (بالهللة) والعملة — دفاع ضدّ التلاعب بمعطيات العودة
        $expected = (int) round($invoice->amount * 100);
        $paidAmount = (int) ($payment['amount'] ?? 0);
        $currency = (string) ($payment['currency'] ?? '');
        if ($paidAmount !== $expected || $currency !== 'SAR') {
            Log::warning('moyasar.reconcile.amount_mismatch', [
                'invoice' => $invoice->number, 'expected' => $expected, 'paid' => $paidAmount, 'currency' => $currency,
            ]);

            return false;
        }

        $invoice->update(['gateway_payment_id' => (string) ($payment['id'] ?? '')]);

        // انتقال حالة المجال (idempotent) بحسب نوع الفاتورة: استشارة أو أتعاب قضية أو أتعاب تنفيذ.
        self::settleDomain($invoice, 'ميسّر');

        // اختم أوّل تسوية للدفتر (لا تُدهَس عند تكرار webhook/callback).
        if ($ledger !== null && $ledger->reconciled_at === null) {
            $ledger->forceFill(['reconciled_at' => now()])->save();
        }

        return true;
    }

    /**
     * تحصيل يدويّ من الإدارة (نقداً/تحويلاً خارج البوّابة) — يسوّي الفاتورة **ويقيّد الدفتر**.
     * كان المسار الإداري يعلّم الفاتورة مدفوعة بلا صفّ في payments، فتستحيل التسوية المحاسبية
     * (المحصّل في اللوحة لا يقابله قيد)، وبلا حارس حالة يُعاد التحصيل على فاتورة مدفوعة.
     */
    public static function settleManual(Invoice $invoice, string $actor): bool
    {
        if ($invoice->paid) {
            return false; // مدفوعة أصلاً — لا تُقيَّد مرّتين
        }

        Payment::updateOrCreate(
            ['gateway_payment_id' => 'manual-'.$invoice->id],
            [
                'invoice_id' => $invoice->id,
                'gateway' => 'manual',
                'status' => 'paid',
                'amount' => (int) $invoice->amount,
                'currency' => 'SAR',
                'source_channel' => 'admin',
                'raw' => ['actor' => $actor, 'settled_at' => now()->toIso8601String()],
                'reconciled_at' => now(),
            ]
        );

        self::settleDomain($invoice, $actor);

        return true;
    }

    /** تسوية كائن المجال والمرتبطة بالفاتورة (استشارة/قضية/تنفيذ) */
    public static function settleDomain(Invoice $invoice, string $actor = 'ميسّر'): void
    {
        $invoice->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);

        if ($invoice->consult_id && ($consult = Consult::find($invoice->consult_id))) {
            ConsultBooking::markPaid($consult, $actor, 'مدفوع عبر ميسّر');
        } elseif ($invoice->case_id && ($case = LegalCase::find($invoice->case_id))) {
            CaseFee::markPaid($case);
        } elseif ($invoice->exec_id && ($exec = Execution::find($invoice->exec_id))) {
            ExecService::markPaid($exec);
        }
    }

    /**
     * يسجّل/يحدّث صفّ دفتر المدفوعات (idempotent عبر gateway_payment_id).
     *
     * @param  array<string,mixed>  $payment
     */
    private static function record(array $payment, ?Invoice $invoice, string $channel): ?Payment
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            return null;
        }

        return Payment::updateOrCreate(
            ['gateway_payment_id' => $paymentId],
            [
                'invoice_id' => $invoice?->id,
                'gateway' => 'moyasar',
                'gateway_invoice_id' => ((string) ($payment['invoice_id'] ?? '')) ?: null,
                'status' => (string) ($payment['status'] ?? 'unknown'),
                'amount' => (int) ($payment['amount'] ?? 0),
                'currency' => (string) ($payment['currency'] ?? 'SAR'),
                'source_channel' => $channel,
                'raw' => $payment,
            ]
        );
    }

    /** @param  array<string,mixed>  $payment */
    private static function resolveInvoice(array $payment): ?Invoice
    {
        $number = $payment['metadata']['invoice_number'] ?? null;
        if ($number && ($inv = Invoice::where('number', $number)->first())) {
            return $inv;
        }

        $gatewayRef = $payment['invoice_id'] ?? null;
        if ($gatewayRef) {
            $inv = Invoice::where('gateway_ref', $gatewayRef)->first();
            if ($inv) {
                return $inv;
            }

            // جلب بيانات الفاتورة من ميسّر إن غاب الربط المسبق
            $moyasarInvoice = app(MoyasarService::class)->getInvoice((string) $gatewayRef);
            if ($moyasarInvoice) {
                $invNum = $moyasarInvoice['metadata']['invoice_number'] ?? null;
                if ($invNum && ($inv = Invoice::where('number', $invNum)->first())) {
                    $inv->update(['gateway_ref' => (string) $gatewayRef]);

                    return $inv;
                }
                $cId = $moyasarInvoice['metadata']['consult_id'] ?? null;
                if ($cId && ($consult = Consult::find($cId)) && $consult->invoice) {
                    $consult->invoice->update(['gateway_ref' => (string) $gatewayRef]);

                    return $consult->invoice;
                }
            }
        }

        $consultId = $payment['metadata']['consult_id'] ?? null;
        if ($consultId && ($consult = Consult::find($consultId)) && $consult->invoice) {
            return $consult->invoice;
        }

        $paymentId = $payment['id'] ?? null;
        if ($paymentId && ($inv = Invoice::where('gateway_payment_id', $paymentId)->first())) {
            return $inv;
        }

        return null;
    }
}
