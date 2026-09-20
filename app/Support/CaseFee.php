<?php

namespace App\Support;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Transitions\LegalCase\ActivateCase as ActivateCaseTransition;
use App\Domain\Journey\Workflow;
use App\Events\CaseStatusBroadcast;
use App\Jobs\DraftCasePleadingJob;
use App\Mail\CaseFeePaidMail;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\MailService;
use App\Services\MoyasarService;
use App\Support\Audit;
use App\Support\Finance\InvoiceFactory;
use Illuminate\Support\Facades\DB;

/**
 * دورة سداد أتعاب القضية وتفعيلها — مصدر موحّد يخدم الدفع المحاكى ودفع Moyasar (webhook/callback).
 * markPaid وactivate كلاهما idempotent (تكرار إشعار البوّابة لا يُحدث أثرًا مزدوجًا).
 */
class CaseFee
{
    /** عدد دفعات خطّة التقسيط — الافتراض المُعلَن، والإدارة تضبطه من الإعدادات. */
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

            // **الأمّ هي الأقدم لا الأحدث.** فرعُ التنفيذ هاجر إلى `orderBy('id')` وبقي هذا على
            // `latest('id')`: فقضيّةٌ عليها فاتورةٌ تكميليّة أُصدرت بعد فاتورة الأتعاب كانت
            // التكميليّةُ هي ما يُقسَّم، وتبقى الأتعاب كاملةً مستحقّةً خارج الخطّة.
            $master = Invoice::where('case_id', $locked->id)->where('paid', false)->orderBy('id')->first();
            if ($master === null) {
                return null;
            }

            // العدد من الإعدادات وقت فتح الخطّة، ويُختم على الصفّ في `installments_total` —
            // فتغييرُه لاحقاً لا يمسّ خطّةً مفتوحة: تلك تُقرأ من صفّها لا من الإعداد.
            $count = SettingsRegistry::int('installments_count');

            $total = (int) $master->amount;
            $share = intdiv($total, $count);

            // الدفعة الأولى تأخذ الكسر المتبقّي كي يساوي المجموع الأتعاب بالضبط
            $first = $total - ($share * ($count - 1));

            // **ووسمُ موضعها من الخطّة** (`installment_no`) — العمود أُضيف على `invoices` ووسمته
            // فواتيرُ التنفيذ وحدها، فبقي فرعُ القضايا يعدّ كلَّ مدفوعةٍ على القضيّة دفعةً:
            // فاتورةٌ تكميليّة تُسدَّد فتقدّم الخطّة بلا دفعةٍ منها. الوسم هو ما يفصل النوعين.
            // **والضريبة تُقسَّم مع المبلغ.** الأمّ كانت تحمل ضريبة الأتعاب كاملةً، فتقليصُ
            // `amount` وحده يترك `subtotal + vat_amount` أكبر من الإجماليّ — أي فاتورةً
            // ضريبيّةً لا تتوازن. والحصّة إجماليٌّ معلومٌ لا أساس، فتُعكَس حساباً.
            $master->update(array_merge(InvoiceFactory::taxFromTotal($first), [
                'installment_no' => 1,
                'description' => self::installmentLabel($case, 1, $count),
                'due_label' => 'خلال 3 أيام',
                'due_at' => now()->addDays(3)->toDateString(),
            ]));

            for ($n = 2; $n <= $count; $n++) {
                InvoiceFactory::fromTotal($share, [
                    'user_id' => $locked->user_id,
                    'case_id' => $locked->id,
                    'installment_no' => $n,
                    'description' => self::installmentLabel($case, $n, $count),
                    'due_label' => 'خلال '.(($n - 1) * 30).' يوماً',
                    'due_at' => now()->addDays(($n - 1) * 30)->toDateString(),
                ]);
            }

            $locked->update([
                'pay_plan' => 'install',
                'installments_total' => $count,
                'installments_paid' => 0,
                'fee_status' => 'installments',
                'paid_text' => 'بانتظار سداد الدفعة 1 من '.$count,
            ]);

            return $master->fresh();
        });
    }

    /**
     * الفاتورة التالية المستحقّة في الخطّة — **بترتيب الخطّة** لا بترتيب الإصدار، فلا تلتقط
     * فاتورةً تكميليّة صدرت بينها. وكانت `latest('id')` تعيد الدفعة الأخيرة فيسدّدها العميل
     * وتبقى الأولى مستحقّة.
     *
     * ولا احتياطَ لخططٍ غير موسومة: العمود يسبق أوّل خطّةٍ في هذه القاعدة (قرار المالك
     * 2026-09-13: المنظومة في التطوير ولم تُرفع إنتاجاً)، فكلّ خطّةٍ تُفتح موسومةٌ حتماً —
     * ومسارٌ ثانٍ يحرس حالةً لا تقع كلفةٌ تُقرأ ولا تُختبَر.
     */
    public static function nextInstallment(LegalCase $case): ?Invoice
    {
        return Invoice::where('case_id', $case->id)->whereNotNull('installment_no')
            ->where('paid', false)->orderBy('installment_no')->orderBy('id')->first();
    }

    /**
     * **أوّل فاتورةٍ قابلة للسداد على القضيّة** — نظير `ExecFee::nextPayable`.
     *
     * `nextInstallment` تقتصر على دفعات الخطّة، وفاتورةُ السداد الكامل ليست منها (‏`installment_no`
     * فارغ)، فالاقتصار عليها كان يُعيد `null` على قضيّةٍ بلا خطّة — أي زرّ سدادٍ لا يُنتج رابطاً.
     * والترتيب: دفعةُ الخطّة أوّلاً متى وُجدت، وإلّا أقدم غير مدفوعة.
     */
    public static function nextPayable(LegalCase $case): ?Invoice
    {
        return self::nextInstallment($case)
            ?: Invoice::where('case_id', $case->id)->where('paid', false)->orderBy('id')->first();
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

            // العدّ على دفعات الخطّة وحدها: فاتورةٌ تكميليّة تُسدَّد كانت تقدّم الخطّة بلا
            // دفعةٍ منها — وقد تُنهيها.
            $paid = Invoice::where('case_id', $locked->id)->whereNotNull('installment_no')
                ->where('paid', true)->count();

            // **ولا تقدُّم بلا دفعةٍ من الخطّة** (نظير `ExecFee::markInstallmentPaid`): تسويةُ
            // فاتورةٍ خارج الخطّة كانت تكتب «دفعة 0 من 3» على سطر الحالة وترسل رسالةً بذلك.
            if ($paid === 0) {
                return null;
            }

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

        Audit::log(
            action: $result['done'] ? 'سداد الدفعة الأخيرة واكتمال أتعاب القضية' : 'سداد دفعة أتعاب',
            description: "تم سداد الدفعة {$result['paid']} من {$result['total']} لأتعاب القضية {$case->number}.",
            category: 'مالية وفواتير',
            auditable: $case,
            auditableRef: $case->number,
            afterState: [
                'الدفعة' => $result['paid'],
                'إجمالي_الدفعات' => $result['total'],
                'مكتمل' => $result['done'] ? 'نعم' : 'لا',
                'حالة_الأتعاب' => $result['done'] ? 'paid' : 'installments',
            ],
        );

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

    /** العدد يُمرَّر لا يُقرأ هنا: وصفُ فاتورةٍ يجب أن يوافق خطّتَها هي، لا الإعدادَ يوم قراءته. */
    private static function installmentLabel(LegalCase $case, int $n, int $count): string
    {
        return "أتعاب قضية {$case->number} — الدفعة {$n} من ".$count;
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

        Audit::log(
            action: 'سداد كامل أتعاب القضية',
            description: "تم سداد كامل أتعاب القضية {$case->number}".($invoice ? " بموجب الفاتورة {$invoice->number}" : '').'.',
            category: 'مالية وفواتير',
            auditable: $case,
            auditableRef: $case->number,
            afterState: [
                'حالة_الأتعاب' => 'paid',
                'خطة_السداد' => 'full',
                'الفاتورة' => $invoice?->number,
            ],
        );

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
     *
     * وحين لا تُمرَّر فاتورة بعينها نأخذ **الأقدم** غير المدفوعة — وهي فاتورة الأتعاب. كان
     * الاحتياط يأخذ الأحدث، فيشطب فاتورةً تكميليّة صدرت بعدها ويترك الأتعاب مستحقّة (نظير
     * ما هوجر في `ExecFee::nextPayable`).
     */
    public static function markInvoicePaid(LegalCase $case, ?Invoice $invoice = null): void
    {
        $target = $invoice ?: Invoice::where('case_id', $case->id)
            ->where('paid', false)->orderBy('id')->first();

        if ($target === null) {
            return;
        }

        // **السداد بالمحرّك** (م٢): هو الكاتب الوحيد لـ`paid` و`paid_at` و«مدفوعة»، فلا ينزلق
        // أحدها عن الآخر. والرفض متوقَّعٌ ومبتلَعٌ عمداً: يصل هذا الموضع من `PaymentReconciler`
        // بفاتورةٍ **سُوّيت قبل سطرين** (نظير `if ($invoice->paid)` الذي كان يحرسه ضمناً).
        try {
            Workflow::run(new SettleInvoice, $target, null, ['channel' => 'أتعاب قضيّة']);
        } catch (TransitionDenied) {
            // مسوّاةٌ أصلاً أو لا تقبل السداد — لا شيء يُكتب، ولا خطأَ يُرفع على تسويةٍ تمّت
        }
    }

    /** تفعيل القضية: خطة العمل + مسودة اللائحة (مرّة واحدة عند أول سداد). */
    public static function activate(LegalCase $case): void
    {
        if ($case->pleading_status !== 'none') {
            return; // فُعّلت سابقاً
        }

        $prevStatus = $case->status;
        if ($case->status !== 'قيد التحضير') {
            Workflow::run(new ActivateCaseTransition, $case, auth()->user() ?? $case->user, [
                'fee_status' => $case->fee_status,
                'pay_plan' => $case->pay_plan,
            ]);
        } else {
            $case->update([
                'pleading_status' => 'pending_lawyer',
            ]);
        }

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

        Audit::log(
            action: 'تفعيل القضية',
            description: "فُعّلت القضية {$case->number} وبدأ إعداد خطة العمل واللائحة، وأُسندت إلى {$lawyer}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => $prevStatus],
            afterState: [
                'الحالة' => 'قيد التحضير',
                'حالة_اللائحة' => 'pending_lawyer',
                'المحامي' => $lawyer,
            ],
        );

        DraftCasePleadingJob::dispatch($case);

        Live::push(new CaseStatusBroadcast($case));
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة أتعاب القضية (السداد الكامل) ويعيد رابط الدفع أو null.
     * يخزّن معرّف فاتورة البوّابة على الفاتورة للمطابقة عند العودة/الـwebhook.
     */
    public static function initiatePayment(LegalCase $case, string $callbackUrl): ?string
    {
        // **الأقدم لا الأحدث** (نظير `ExecService::initiatePayment`): بعد فتح خطّة التقسيط
        // كانت `latest('id')` هي الدفعة الأخيرة — يسدّدها العميل وتبقى الأولى مستحقّة
        // والقضيّة غير مفعّلة. والأقدم غير المدفوعة هي المستحقّة فعلاً في الحالين.
        $invoice = self::nextPayable($case);

        return $invoice ? app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
