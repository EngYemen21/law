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

        // من هنا **المال وصل**: كلّ رفضٍ بعده مالٌ خُصم ولم يُطبَّق — يُنبَّه له بالاسترداد في كلّ الفروع
        // (كان فرع الاستشارات وحده يُنبّه، والقضايا والتنفيذ يكتبان سطر سجلٍّ لا يراه أحد).
        if ($invoice === null) {
            self::flagRefund($ledger, null, 'وصلت دفعة لا تُطابق أيّ فاتورة', 'invoice_not_found');

            return false;
        }

        // تحقّق تطابق المبلغ (بالهللة) والعملة — دفاع ضدّ التلاعب بمعطيات العودة
        $expected = Money::halalas($invoice->amount);
        if ($payment->amountHalalas !== $expected || $payment->currency !== Money::CURRENCY) {
            self::flagRefund($ledger, $invoice, 'مبلغ الدفعة '.number_format($payment->amountHalalas / 100, 2).' '.$payment->currency
                .' لا يطابق مبلغ الفاتورة '.number_format($expected / 100, 2).' '.Money::CURRENCY, 'amount_mismatch');

            return false;
        }

        // فاتورة مدفوعة أصلاً: التسوية تمّت — وإن اختلف معرّف الدفعة فهذه **شحنة ثانية حقيقيّة**.
        if ($invoice->paid) {
            return self::alreadySettled($invoice, $payment, $ledger);
        }

        // انتقال حالة المجال (idempotent) بحسب نوع الفاتورة: استشارة أو أتعاب قضية أو أتعاب تنفيذ.
        if (! self::settleDomain($invoice, app(PaymentGateways::class)->label($payment->gateway))) {
            // **سباق الإشعار والعودة:** كلاهما قرأ الفاتورة غير مدفوعة، فسوّاها الأوّل ورُفض الثاني —
            // نجاحٌ مكرّر لا فشل (كان فرعا القضايا والتنفيذ يُقرئان العميل «تعذّر تأكيد الدفع»).
            $fresh = $invoice->fresh();
            if ($fresh?->paid) {
                return self::alreadySettled($fresh, $payment, $ledger);
            }

            self::flagRefund($ledger, $invoice, 'الفاتورة لا تقبل السداد في حالتها الحاليّة', 'not_payable');

            return false;
        }

        self::claimPayment($invoice, $payment);

        // اختم أوّل تسوية للدفتر (لا تُدهَس عند تكرار webhook/callback).
        if ($ledger !== null && $ledger->reconciled_at === null) {
            $ledger->forceFill(['reconciled_at' => now()])->save();
        }

        return true;
    }

    /**
     * الفاتورة مسوّاة: الدفعة نفسها (إشعارٌ وعودةٌ معاً) نجاحٌ مكرّر؛ ودفعةٌ **أخرى** شحنةٌ ثانيةٌ حقيقيّة
     * على فاتورةٍ واحدة (يدفع العميل ثمّ يعيد قبل وصول الإشعار) — تُنبَّه الإدارة لردّها.
     */
    private static function alreadySettled(Invoice $invoice, GatewayPayment $payment, ?Payment $ledger): bool
    {
        self::claimPayment($invoice, $payment);

        if ($payment->id !== (string) $invoice->fresh()?->gateway_payment_id) {
            self::flagRefund($ledger, $invoice, 'دفعة ثانية على فاتورة سُدّدت من قبل', 'duplicate_charge');
        }

        return true;
    }

    /**
     * يربط الفاتورة بدفعتها **مرّةً واحدة** — أوّل دفعةٍ تُسجَّل تبقى. كانت الكتابة قبل التسوية وبلا شرط،
     * فخاسرُ سباقٍ بدفعةٍ أخرى يطمس مرجع الدفعة التي سُوّيت بها الفاتورة.
     */
    private static function claimPayment(Invoice $invoice, GatewayPayment $payment): void
    {
        if ($payment->id === '') {
            return;
        }

        Invoice::whereKey($invoice->id)
            ->where(fn ($q) => $q->whereNull('gateway_payment_id')->orWhere('gateway_payment_id', ''))
            ->update(['gateway_payment_id' => $payment->id]);
    }

    /**
     * **مالٌ خُصم ولم يُطبَّق — يتطلّب استرداداً.** قيدٌ حرج في التدقيق وتنبيهٌ لكلّ مدير، مرّةً واحدة لكلّ
     * دفعة: `refund_required_at` يُختم ذرّيّاً، فالإشعار والعودة الواصلان معاً لا يُنبّهان مرّتين.
     */
    private static function flagRefund(?Payment $ledger, ?Invoice $invoice, string $reason, string $code): void
    {
        Log::error("payment.reconcile.{$code}", [
            'payment_id' => $ledger?->gateway_payment_id, 'gateway' => $ledger?->gateway, 'invoice' => $invoice?->number,
            'settled_payment_id' => $invoice?->fresh()?->gateway_payment_id, 'amount' => $ledger?->amount_halalas,
        ]);

        if ($ledger !== null
            && Payment::whereKey($ledger->id)->whereNull('refund_required_at')->update(['refund_required_at' => now()]) === 0) {
            return; // نُبّه لها سابقاً
        }

        $ref = self::ownerRef($invoice);
        $paymentId = (string) ($ledger->gateway_payment_id ?? '—');
        $where = $invoice !== null ? "على الفاتورة {$invoice->number}".($ref !== null ? " ({$ref})" : '') : 'بلا فاتورة مطابقة';

        Audit::log(
            action: 'دفعة لم تُطبَّق وتتطلّب استرداداً',
            description: "وصلت الدفعة {$paymentId} {$where} — {$reason}. لم تُطبَّق، وتتطلّب استرداداً للعميل.",
            category: 'مالية وفواتير',
            severity: 'critical',
            auditable: $invoice,
            auditableRef: $invoice?->number,
        );

        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send($adminId, 'card', 't-red', "دفعة وصلت {$where} ولم تُطبَّق ({$reason}) — تتطلّب استرداداً للعميل.");
        }
    }

    /** رقم الملفّ الذي تخصّه الفاتورة — ليعرف المدير من يردّ إليه. */
    private static function ownerRef(?Invoice $invoice): ?string
    {
        return match (true) {
            $invoice === null => null,
            $invoice->consult_id !== null => Consult::whereKey($invoice->consult_id)->value('ref'),
            $invoice->case_id !== null => LegalCase::whereKey($invoice->case_id)->value('number'),
            $invoice->exec_id !== null => Execution::whereKey($invoice->exec_id)->value('number'),
            default => null,
        };
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
     * الآن: فاتورةٌ مسوّاة ⇒ لا شيء؛ ملفٌّ يقبل السداد ⇒ يُسوّى؛ وإلّا ⇒ رفض، وتنبيه الاسترداد في `settle`.
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

        // رفضٌ — ومسار البوّابة (`settle`) يفرّق بين خاسر سباقٍ على فاتورةٍ سُوّيت ومالٍ يتطلّب استرداداً
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

        // **القناة الأولى تبقى**: الإشعار والعودة يصلان للدفعة نفسها، وكان آخرهما يدهس قناة أوّلهما في الدفتر
        $ledger = Payment::firstOrNew(['gateway_payment_id' => $payment->id], ['source_channel' => $channel]);
        $ledger->fill(
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
                'raw' => $payment->raw,
            ]
        )->save();

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
