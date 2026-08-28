<?php

namespace App\Support;

use App\Events\CaseStatusBroadcast;
use App\Jobs\DraftCasePleadingJob;
use App\Mail\CaseFeePaidMail;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\MailService;
use App\Services\MoyasarService;
use Illuminate\Support\Facades\DB;

/**
 * دورة سداد أتعاب القضية وتفعيلها — مصدر موحّد يخدم الدفع المحاكى ودفع Moyasar (webhook/callback).
 * markPaid وactivate كلاهما idempotent (تكرار إشعار البوّابة لا يُحدث أثرًا مزدوجًا).
 */
class CaseFee
{
    /** عدد دفعات خطّة التقسيط. */
    public const INSTALLMENTS = 3;

    /**
     * يفتح خطّة تقسيط على أتعاب القضية: يقسّم الفاتورة القائمة إلى فواتير حقيقية.
     *
     * كانت الخطّة «ميزة مستقلّة لا تمرّ ببوّابة الدفع»: نقرة واحدة تُفعّل القضية وتكتب
     * «تم استلام الدفعة الأولى» بلا بوّابة ولا فاتورة ولا صفّ دفع، ونقرتان لاحقتان تُصيّران
     * فاتورة الأتعاب كاملةً «مدفوعة» فتدخل إجمالي المحصَّل — خدمة كاملة بصفر ريال.
     *
     * الآن: الفاتورة الأصلية (غير المدفوعة، ولم يُدفع عليها شيء) تُعاد هيكلتها لتصير الدفعة
     * الأولى، وتُصدر لها أخوات باستحقاقات متدرّجة. كل دفعة تُسدَّد كأي فاتورة — بالبوّابة أو
     * بتحصيل إداريّ يقيّد الدفتر — ولا تفعيل إلا بتسوية الأولى فعلاً.
     *
     * @return Invoice|null فاتورة الدفعة الأولى، أو null إن لم توجد فاتورة قابلة للتقسيم
     */
    public static function openInstallmentPlan(LegalCase $case): ?Invoice
    {
        return DB::transaction(function () use ($case) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->first();

            if ($locked === null || $locked->fee_status !== 'pending_payment') {
                return null;
            }

            $master = Invoice::where('case_id', $locked->id)->where('paid', false)->latest('id')->first();
            if ($master === null) {
                return null;
            }

            $total = (int) $master->amount;
            $share = intdiv($total, self::INSTALLMENTS);

            // الدفعة الأولى تأخذ الكسر المتبقّي كي يساوي المجموع الأتعاب بالضبط
            $first = $total - ($share * (self::INSTALLMENTS - 1));

            $master->update([
                'amount' => $first,
                'description' => self::installmentLabel($case, 1),
                'due_label' => 'خلال 3 أيام',
                'due_at' => now()->addDays(3)->toDateString(),
            ]);

            for ($n = 2; $n <= self::INSTALLMENTS; $n++) {
                Invoice::create([
                    'user_id' => $locked->user_id,
                    'case_id' => $locked->id,
                    'number' => InvoiceNumber::next(),
                    'description' => self::installmentLabel($case, $n),
                    'amount' => $share,
                    'status' => 'مستحقة',
                    'tone' => 'b-amber',
                    'due_label' => 'خلال '.(($n - 1) * 30).' يوماً',
                    'due_at' => now()->addDays(($n - 1) * 30)->toDateString(),
                    'paid' => false,
                ]);
            }

            $locked->update([
                'pay_plan' => 'install',
                'installments_total' => self::INSTALLMENTS,
                'installments_paid' => 0,
                'fee_status' => 'installments',
                'paid_text' => 'بانتظار سداد الدفعة 1 من '.self::INSTALLMENTS,
            ]);

            return $master->fresh();
        });
    }

    /** الفاتورة التالية المستحقّة في الخطّة (الأقدم غير المدفوعة). */
    public static function nextInstallment(LegalCase $case): ?Invoice
    {
        return Invoice::where('case_id', $case->id)->where('paid', false)->orderBy('id')->first();
    }

    /**
     * نقطة التسوية الموحّدة لفاتورة قضية — تعرف وحدها أهي دفعة من خطّة أم سداد كامل.
     * يناديها PaymentReconciler لمسارَي البوّابة والتحصيل اليدويّ معاً.
     */
    public static function settleInvoice(LegalCase $case, Invoice $invoice): void
    {
        if ($case->pay_plan === 'install') {
            self::markInstallmentPaid($case);

            return;
        }

        self::markPaid($case, $invoice);
    }

    /**
     * يقدّم خطّة التقسيط بعد تسوية دفعة — العدد **يُحتسب من الفواتير المدفوعة** لا يُزاد
     * يدوياً، فلا يمكن لنقرة أو تكرار ويبهوك أن يقدّم الخطّة بلا مال.
     */
    public static function markInstallmentPaid(LegalCase $case): void
    {
        $result = DB::transaction(function () use ($case) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $paid = Invoice::where('case_id', $locked->id)->where('paid', true)->count();
            $total = max(1, (int) $locked->installments_total);
            $done = $paid >= $total;
            $isFirst = $paid === 1 && $locked->installments_paid === 0;

            $locked->update([
                'installments_paid' => $paid,
                'fee_status' => $done ? 'paid' : 'installments',
                'paid_text' => $done
                    ? 'تم سداد كامل الأتعاب'
                    : "دفعة {$paid} من {$total} مدفوعة",
            ]);

            return ['paid' => $paid, 'total' => $total, 'done' => $done, 'first' => $isFirst];
        });

        if ($result === null) {
            return;
        }

        $case->refresh();
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => $result['done']
                ? '<p>تم سداد الدفعة الأخيرة واكتمال أتعاب القضية.</p>'
                : "<p>تم استلام الدفعة {$result['paid']} من {$result['total']}.</p>",
            'time_label' => self::clock(),
        ]);

        // التفعيل عند أوّل دفعة **مسوّاة فعلاً** لا عند اختيار الخطّة
        if ($result['first']) {
            self::activate($case);
        }

        if ($result['done']) {
            $case->loadMissing('user');
            if ($case->user?->email) {
                app(MailService::class)->send($case->user, new CaseFeePaidMail($case));
            }
        }
    }

    private static function installmentLabel(LegalCase $case, int $n): string
    {
        return "أتعاب قضية {$case->number} — الدفعة {$n} من ".self::INSTALLMENTS;
    }

    /**
     * سداد كامل الأتعاب وتفعيل القضية — idempotent وآمن ضدّ التسابق (قفل الصفّ).
     *
     * `$invoice` هي الفاتورة المسوّاة فعلاً. تمريرها إلزاميّ من مسار البوّابة: بدونه تعود
     * markInvoicePaid إلى احتياط «أحدث فاتورة غير مدفوعة»، والمُسوّي كان قد قلب الفاتورة
     * الحقيقية إلى مدفوعة قبل سطر واحد — فيلتقط الاحتياط **فاتورة أخرى ويشطبها بلا مقابل**.
     */
    public static function markPaid(LegalCase $case, ?Invoice $invoice = null): void
    {
        // قفل الصفّ ثم إعادة فحص الحالة — يمنع تسابق webhook+callback
        $didPay = DB::transaction(function () use ($case, $invoice) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->first();
            if ($locked === null || $locked->fee_status === 'paid') {
                return false;
            }

            $locked->update(['pay_plan' => 'full', 'fee_status' => 'paid', 'paid_text' => 'تم سداد كامل الأتعاب']);
            self::markInvoicePaid($locked, $invoice);

            return true;
        });

        if (! $didPay) {
            return;
        }

        $case->refresh();
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => '<p>تم استلام سداد كامل الأتعاب وتفعيل القضية.</p>',
            'time_label' => self::clock(),
        ]);
        self::activate($case);

        // بريد للعميل بتأكيد سداد الأتعاب وتفعيل القضية (أفضل-جهد — لا يعطّل مسار الدفع إن فشل)
        $case->loadMissing('user');
        if ($case->user?->email) {
            app(MailService::class)->send($case->user, new CaseFeePaidMail($case));
        }
    }

    /**
     * يعلّم فاتورة أتعاب القضية مدفوعةً — **فاتورة واحدة** لا كل غير المدفوعة.
     *
     * كان `where('paid', false)->update(...)` يشطب كل فواتير القضية دفعةً واحدة، فأي
     * فاتورة تكميلية قائمة تُصبح «مدفوعة» بلا مقابل وتدخل إجمالي المحصَّل في اللوحة.
     * حين لا تُمرَّر فاتورة بعينها نأخذ الأحدث غير المدفوعة (وهي التي سُدّدت).
     */
    public static function markInvoicePaid(LegalCase $case, ?Invoice $invoice = null): void
    {
        $target = $invoice ?: Invoice::where('case_id', $case->id)
            ->where('paid', false)->latest('id')->first();

        $target?->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);
    }

    /** تفعيل القضية: خطة العمل + مسودة اللائحة (مرّة واحدة عند أول سداد). */
    public static function activate(LegalCase $case): void
    {
        if ($case->pleading_status !== 'none') {
            return; // فُعّلت سابقاً
        }

        $case->update([
            'status' => 'قيد التحضير',
            'tone' => CaseJourney::toneFor('قيد التحضير'),
            'update_text' => 'تم تفعيل القضية؛ يجهّز الفريق خطة العمل واللائحة',
            'pleading_status' => 'pending_lawyer',
        ]);

        $lawyer = $case->assigned_lawyer ?: 'المستشار القانوني';
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'تفعيل',
            'body' => '<p>تم تفعيل القضية وإسنادها إلى '.e($lawyer).'.</p>',
            'time_label' => self::clock(),
        ]);

        $steps = ['إعداد اللائحة', 'تجهيز المستندات', 'رفع الدعوى', 'متابعة الجلسات', 'متابعة الحكم', 'التنفيذ'];
        $stepsHtml = implode('', array_map(fn ($s) => '<li>'.e($s).'</li>', $steps));
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'خطة العمل',
            'body' => '<p>خطة العمل المقترحة للقضية:</p><div class="result-card"><div class="result-sec"><div class="t">مراحل القضية</div><ul>'.$stepsHtml.'</ul></div></div>',
            'time_label' => self::clock(),
        ]);

        DraftCasePleadingJob::dispatch($case);

        Live::push(new CaseStatusBroadcast($case));
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة أتعاب القضية (السداد الكامل) ويعيد رابط الدفع أو null.
     * يخزّن معرّف فاتورة البوّابة على الفاتورة للمطابقة عند العودة/الـwebhook.
     */
    public static function initiatePayment(LegalCase $case, string $callbackUrl): ?string
    {
        $invoice = Invoice::where('case_id', $case->id)->where('paid', false)->latest('id')->first();

        return $invoice ? app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
