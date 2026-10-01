<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Support\PaymentReconciler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * **العودة من صفحة الدفع** — مصدرٌ واحد لمتحكّمات الفاتورة والاستشارة والقضيّة والتنفيذ (كانت كلٌّ منها
 * تكرّر: الجلب ← «هل تخصّ هذه الفاتورة؟» ← التسوية، بقاعدة انتماءٍ تختلف بين النسخ الأربع).
 *
 * لا يُوثق بمعطيات العنوان: الدفعة تُجلب من البوّابة بمعرّفها، ثمّ لا تُسوّى إلّا إن خصّت إحدى فواتير
 * صاحب الصفحة — بمرجع فاتورة البوّابة، أو برقم الفاتورة لفاتورةٍ لم يُخزَّن مرجعها بعد.
 */
final class GatewayCallback
{
    /**
     * @param  Builder<Invoice>  $invoices  فواتير صاحب الصفحة (فاتورةٌ واحدة، أو كلّ فواتير القضيّة/الطلب)
     */
    public static function confirm(Request $request, Builder $invoices): CallbackOutcome
    {
        $gateway = app(PaymentGateways::class)->default();
        $paymentId = $gateway->paymentIdFromCallback($request);
        $payment = $paymentId !== '' ? $gateway->fetchPayment($paymentId) : null;

        if ($payment === null || ! self::belongs($payment, $invoices)) {
            return CallbackOutcome::Unconfirmed;
        }

        // التسوية تسجّل الدفتر حتى للمرفوضة (تدقيق) — ثمّ يُقرأ الرفض ليُقال للعميل إنّه لم يُخصم شيء
        return match (true) {
            PaymentReconciler::settle($payment, 'callback') => CallbackOutcome::Settled,
            $payment->isFailed => CallbackOutcome::Declined,
            default => CallbackOutcome::Unconfirmed,
        };
    }

    /** @param  Builder<Invoice>  $invoices */
    private static function belongs(GatewayPayment $payment, Builder $invoices): bool
    {
        $ref = $payment->gatewayInvoiceId;
        $number = $payment->invoiceNumber();
        if ($ref === null && $number === null) {
            return false;
        }

        return $invoices->where(function (Builder $q) use ($ref, $number) {
            if ($ref !== null) {
                $q->where('gateway_ref', $ref);
            }
            if ($number !== null) {
                $q->orWhere(fn (Builder $byNumber) => $byNumber->whereNull('gateway_ref')->where('number', $number));
            }
        })->exists();
    }
}
