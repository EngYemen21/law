<?php

namespace App\Support;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\GatewayPayment;
use App\Services\Payments\PaymentGateways;
use App\Support\Finance\Money;
use App\Support\Finance\ReceiptVoucher;
use Illuminate\Support\Facades\Log;

/**
 * يسوّي دفعة بوّابةٍ مع فاتورة النظام ثم يحرّك حالة المجال — مصدر واحد يخدم الإشعار والعودة والتحصيل اليدويّ.
 * دفاعيّ: يتحقّق من الحالة والمبلغ والعملة قبل التعليم، وidempotent ضدّ التكرار.
 *
 * **مستقلٌّ عن البوّابة:** يستقبل `GatewayPayment` الموحّد — كلّ بوّابةٍ تحوّل ردّها إليه، فلا يعرف هذا
 * الصنف شكلَ بيانات أيّ بوّابة (مراجعة بوّابات الدفع 2026-09-30).
 */
class PaymentReconciler
{
    /**
     * @param  GatewayPayment  $payment  ناتج `PaymentGateway::fetchPayment` — لا جسم إشعارٍ ولا معطيات عودة
     * @param  string  $channel  قناة ورود الحدث: webhook | callback (لتدقيق الدفتر)
     * @return bool true إن سُوّيت (أو كانت مسوّاة)، false عند تعذّر المطابقة/عدم تطابق المبلغ/حالة غير مدفوعة
     */
    public static function settle(GatewayPayment $payment, string $channel = 'callback'): bool
    {
        // سجّل الدفتر أوّلاً (قبل الحرّاس) فيلتقط حتى المحاولات المرفوضة — تدقيق ومطابقة.
        $invoice = self::resolveInvoice($payment);
        $ledger = self::record($payment, $invoice, $channel);

        if (! $payment->isPaid) {
            return false;
        }

        if ($invoice === null) {
            Log::warning('payment.reconcile.invoice_not_found', ['gateway' => $payment->gateway, 'payment_id' => $payment->id]);

            return false;
        }

        // تحقّق تطابق المبلغ (بالهللة) والعملة — دفاع ضدّ التلاعب بمعطيات العودة
        $expected = Money::halalas($invoice->amount);
        if ($payment->amountHalalas !== $expected || $payment->currency !== Money::CURRENCY) {
            Log::warning('payment.reconcile.amount_mismatch', [
                'gateway' => $payment->gateway, 'invoice' => $invoice->number, 'expected' => $expected,
                'paid' => $payment->amountHalalas, 'currency' => $payment->currency,
            ]);

            return false;
        }

        // فاتورة مدفوعة أصلاً: التسوية تمّت. لا نطمس مرجع الدفعة الأولى — طمسه يُضيّع أثر
        // المبلغ المحصَّل فعلاً. وإن اختلف معرّف الدفعة فهذه **شحنة ثانية حقيقية** على نفس
        // الفاتورة (يدفع العميل ثم يعيد المحاولة قبل وصول الويبهوك)، وتلزمها تسوية بشرية.
        // النظير الإداري settleManual يحرس بـ`if ($invoice->paid)` منذ البداية؛ هذا المسار
        // — وهو المعرَّض للإنترنت — كان بلا حارس.
        if ($invoice->paid) {
            if ($payment->id !== '' && $payment->id !== (string) $invoice->gateway_payment_id) {
                Log::error('payment.reconcile.duplicate_charge', [
                    'gateway' => $payment->gateway,
                    'invoice' => $invoice->number,
                    'settled_payment_id' => $invoice->gateway_payment_id,
                    'duplicate_payment_id' => $payment->id,
                    'amount' => $payment->amountHalalas,
                    'channel' => $channel,
                ]);
            }

            return true;
        }

        $invoice->update(['gateway_payment_id' => $payment->id]);

        // انتقال حالة المجال (idempotent) بحسب نوع الفاتورة: استشارة أو أتعاب قضية أو أتعاب تنفيذ.
        // **وقد يرفض:** دفعةٌ على فاتورة استشارةٍ لا تقبل السداد لا تُحيي شيئاً — تبقى في الدفتر
        // بلا تسوية وتُنبَّه الإدارة للاسترداد (ع١، ع٢).
        if (! self::settleDomain($invoice, app(PaymentGateways::class)->label($payment->gateway))) {
            return false;
        }

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
    public static function settleManual(Invoice $invoice, string $actor, ?User $by = null, ?string $method = null): bool
    {
        if ($invoice->paid) {
            return false; // مدفوعة أصلاً — لا تُقيَّد مرّتين
        }

        // الملغاة لا تُحصَّل — كانت تُسوّى فتُحيي الاستشارة التي أُلغيت معها (ع١)
        if ($invoice->status === InvoiceStatus::Cancelled->value) {
            return false;
        }

        Payment::updateOrCreate(
            ['gateway_payment_id' => 'manual-'.$invoice->id],
            [
                'invoice_id' => $invoice->id,
                'gateway' => 'manual',
                'status' => 'paid',
                // `amount` يبقى بالريال حرفاً كما كان (لقطةُ ما قُيّد، ومرجعُ اختباراتٍ قائمة)،
                // و`amount_halalas` **بالهللة دائماً** فيصير العمود قابلاً للجمع مع صفوف البوّابة.
                'amount' => (int) $invoice->amount,
                'amount_halalas' => Money::halalas($invoice->amount),
                'currency' => Money::CURRENCY,
                'source_channel' => 'admin',
                'raw' => ['actor' => $actor, 'settled_at' => now()->toIso8601String()],
                'reconciled_at' => now(),
            ]
        );

        if (! self::settleDomain($invoice, $actor)) {
            // لم يقبل الملفّ السداد — لا قيدَ لتحصيلٍ لم يُطبَّق
            Payment::where('gateway_payment_id', 'manual-'.$invoice->id)->delete();

            return false;
        }

        // سند القبض بعد نجاح التسوية — فلا يُستهلك رقمٌ متسلسل لتحصيلٍ رُفض
        $ledger = Payment::where('gateway_payment_id', 'manual-'.$invoice->id)->first();
        if ($ledger !== null) {
            ReceiptVoucher::issue($ledger, $by, $method);
        }

        return true;
    }

    /**
     * تسوية كائن المجال والمرتبطة بالفاتورة (استشارة/قضية/تنفيذ).
     *
     * **والفاتورة تُسوّى بالمحرّك لا بكتابةٍ مباشرة** (م٢): كان السطر
     * `$invoice->update(['paid' => true, 'status' => 'مدفوعة'])` يمرّ من فتحة
     * `StateWriteGuard` التي تعفي كلّ فاتورةٍ ليست فاتورة استشارة — فسدادُ فواتير القضايا
     * والتنفيذ، وهي أكبر مبالغ المكتب، لم يكن يترك أثراً في `journey_transitions` (ع٣).
     *
     * **ورفضُ الانتقال رفضٌ للتسوية كلّها**: دفعةٌ تصل على فاتورةٍ لا تقبل السداد (ملغاة
     * مثلاً) كانت تُعلَّم مدفوعةً فتُحيي ما أُلغي — وهو عينُ ما عولج في فرع الاستشارات.
     */
    public static function settleDomain(Invoice $invoice, string $actor): bool
    {
        if ($invoice->consult_id && ($consult = Consult::find($invoice->consult_id))) {
            return self::settleConsult($invoice, $consult, $actor);
        }

        try {
            Workflow::run(new SettleInvoice, $invoice, null, ['channel' => $actor]);
        } catch (TransitionDenied $denied) {
            Log::warning('invoice.settle.denied', [
                'invoice' => $invoice->number, 'status' => $invoice->status, 'reason' => $denied->getMessage(),
            ]);

            return false;
        }

        if ($invoice->case_id && ($case = LegalCase::find($invoice->case_id))) {
            // تعرف وحدها أهي دفعة من خطّة تقسيط أم سداد كامل
            CaseFee::settleInvoice($case, $invoice);
        } elseif ($invoice->exec_id && ($exec = Execution::find($invoice->exec_id))) {
            // تعرف وحدها أهي دفعة من خطّة تقسيط، أم سداد كامل، أم فاتورة أتعابٍ عن تحصيل
            ExecFee::settleInvoice($exec, $invoice);
        }

        return true;
    }

    /**
     * **فاتورة الاستشارة تُسوّى عبر `SettlePayment` — أو لا تُسوّى.**
     *
     * كانت الفاتورة تُعلَّم مدفوعةً قبل النظر في الاستشارة، ثمّ `markPaid` يُحيي ما أُلغي.
     * الآن: فاتورةٌ مسوّاة ⇒ لا شيء؛ ملفٌّ يقبل السداد ⇒ يُسوّى؛ وإلّا ⇒ المبلغ حُصّل ولم يُطبَّق،
     * فتُنبَّه الإدارة لتردّه ويبقى الأثر في سجلّ التدقيق.
     */
    private static function settleConsult(Invoice $invoice, Consult $consult, string $actor): bool
    {
        if ($invoice->paid) {
            return true;
        }

        // السجلّ يصف قناة التحصيل: اليدويّ (`gateway=manual`) غيرُ البوّابة — والمطابقة تحتاج التمييز.
        // والحكم بالعلَم لا بنصّ القناة (كان `$channel !== 'مدفوع عبر ميسّر'`).
        $manual = $invoice->payments()->where('gateway', 'manual')->exists();
        $channel = $manual
            ? 'مدفوع — تحصيل يدويّ بقيد الإدارة'
            : 'مدفوع عبر '.app(PaymentGateways::class)->label($invoice->gateway);

        if (ConsultBooking::markPaid($consult, $actor, $channel, $invoice)) {
            return true;
        }

        // **سباق الخطّاف والعودة:** كلاهما قرأ الفاتورة غير مدفوعة بلا قفل، فسدّد الأوّلُ وانتقل
        // بالاستشارة، ورُفض انتقالُ الثاني لأنّها غادرت «بانتظار السداد». الفاتورة نفسها مدفوعةٌ
        // الآن ⇒ نجاحٌ مكرّر لا دفعةٌ تتطلّب استرداداً (كان `markPaid` قبل المحرّك يقفل ويعيد الفحص).
        if ($invoice->fresh()?->paid) {
            return true;
        }

        if ($manual) {
            return false; // تحصيلٌ يدويّ رُفض قبل أن يقع — لا مبلغ يُردّ
        }

        Audit::log(
            action: 'دفعة على فاتورة لا تقبل السداد',
            description: "وصلت دفعة على الفاتورة {$invoice->number} للاستشارة {$consult->ref} وحالتها «{$consult->status}» والفاتورة «{$invoice->status}» — لم تُطبَّق وتتطلّب استرداداً.",
            category: 'مالية وفواتير',
            severity: 'critical',
            auditable: $consult,
        );

        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send($adminId, 'card', 't-red', "دفعة وصلت على الفاتورة {$invoice->number} ({$consult->ref}) وهي لا تقبل السداد — لم تُطبَّق، وتتطلّب استرداداً للعميل.");
        }

        return false;
    }

    /**
     * يسجّل/يحدّث صفّ دفتر المدفوعات (idempotent عبر gateway_payment_id).
     */
    private static function record(GatewayPayment $payment, ?Invoice $invoice, string $channel): ?Payment
    {
        if ($payment->id === '') {
            return null;
        }

        $ledger = Payment::updateOrCreate(
            ['gateway_payment_id' => $payment->id],
            [
                'invoice_id' => $invoice?->id,
                'gateway' => $payment->gateway,
                'gateway_invoice_id' => $payment->gatewayInvoiceId,
                'status' => $payment->status,
                // صفوف البوّابة بالهللة في العمودين — والتطابق مقصود: `amount` لقطةُ البوّابة كما وردت،
                // و`amount_halalas` الوحدةُ الموحّدة للجمع (انظر settleManual).
                'amount' => $payment->amountHalalas,
                'amount_halalas' => $payment->amountHalalas,
                'currency' => $payment->currency,
                'source_channel' => $channel,
                'raw' => $payment->raw,
            ]
        );

        // المال وصل ⇒ سند قبض (مرّةً واحدة — الإشعار والعودة قد يصلان معاً)؛ والمحاولة الفاشلة بلا سند
        return ReceiptVoucher::issue($ledger);
    }

    /**
     * فاتورة المنصّة التي تخصّها الدفعة: برقمها المُرسَل مع الفاتورة ← بمرجع فاتورة البوّابة ← بالاستشارة
     * المُرسَلة ← بمعرّف دفعةٍ سُوّيت سابقاً. (حُذف فرعٌ كان يقرأ `metadata` من فاتورة البوّابة وهي لا تُعاد —
     * لم يعمل قطّ، كما أثبت PHPStan.)
     */
    private static function resolveInvoice(GatewayPayment $payment): ?Invoice
    {
        $number = $payment->invoiceNumber();
        if ($number !== null && ($invoice = Invoice::where('number', $number)->first())) {
            return $invoice;
        }

        if ($payment->gatewayInvoiceId !== null && ($invoice = Invoice::where('gateway_ref', $payment->gatewayInvoiceId)->first())) {
            return $invoice;
        }

        $consultId = $payment->consultId();
        if ($consultId !== null && ($invoice = Consult::find($consultId)?->invoice)) {
            return $invoice;
        }

        return $payment->id !== '' ? Invoice::where('gateway_payment_id', $payment->id)->first() : null;
    }
}
