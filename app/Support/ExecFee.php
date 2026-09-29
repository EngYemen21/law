<?php

namespace App\Support;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Execution\AcceptExecutionOffer;
use App\Domain\Journey\Transitions\Execution\ActivateExecution;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Workflow;
use App\Events\ExecStatusBroadcast;
use App\Models\Execution;
use App\Models\Invoice;
use App\Support\Finance\InvoiceDue;
use App\Support\Finance\InvoiceFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * **أتعاب التنفيذ: النموذج، والخطّة، والفواتير** — نظير `CaseFee` في القضايا.
 *
 * كان في التنفيذ خيارٌ واحد خلفه محرّك: فاتورةٌ واحدة بكامل الأتعاب تصدر عند قبول العرض،
 * وسدادها يفتح الملفّ. وقائمةُ «طريقة السداد» المعروضة على العميل بأربعة خيارات لا يقرؤها
 * كود، فالشاشة تَعِد بتقسيطٍ لا يقع. هنا النماذج الثلاثة بسلوكها الحقيقيّ (قرار المالك
 * 2026-09-12):
 *
 * | النموذج | من يختاره | متى يُفتح الملفّ | الفواتير |
 * |---|---|---|---|
 * | ثابت — كامل    | المكتب ثمّ العميل | بالسداد        | فاتورة واحدة |
 * | ثابت — تقسيط   | المكتب ثمّ العميل | بأوّل قسطٍ مسوّى | ثلاث، الكسر في الأولى |
 * | نسبة من المحصّل | المكتب وحده      | فور قبول العرض  | فاتورة مع كلّ تحصيل |
 *
 * وهو الصفّ الذي يناديه `PaymentReconciler::settleDomain` لفرع التنفيذ، كما ينادي
 * `CaseFee::settleInvoice` لفرع القضايا — فالفروع الثلاثة تصير متماثلة، وكان فرعُ التنفيذ
 * يشير إلى منسّق التدفّق مباشرةً فبقي أحاديّ الفاتورة.
 */
class ExecFee
{
    /** عدد دفعات خطّة التقسيط — يطابق `CaseFee::INSTALLMENTS`، وهو الافتراض الذي تضبطه الإدارة من الإعدادات. */
    public const INSTALLMENTS = 3;

    /** نماذج الأتعاب كما يقرؤها المحرّك ويعرضها المكتب. */
    public const MODES = ['fixed' => 'مبلغ ثابت', 'percent' => 'نسبة من المحصّل'];

    /** خطط السداد في النموذج الثابت وحده. */
    public const PLANS = ['full' => 'دفعة واحدة', 'install' => 'دفعات'];

    /** أقصى نسبةٍ تُقبل من المحصَّل — ما فوقها خطأ إدخال لا سياسة تسعير. */
    public const MAX_PCT = 50.0;

    /**
     * **عنوان طريقة السداد المعروض للعميل — مشتقٌّ لا حرّ.** كان `pay_method` نصّاً يكتبه
     * المسعِّر ولا يقرؤه محرّك، فأمكنه أن يخالف ما يقع فعلاً. الآن يُشتقّ من النموذج والخطّة.
     */
    public static function payMethodLabel(Execution $exec): string
    {
        if ($exec->feeMode() === 'percent') {
            return self::MODES['percent'];
        }

        return self::PLANS[$exec->payPlan()];
    }

    /**
     * **أثر قبول العرض** — يتفرّع على النموذج:
     *
     * النسبيّ: لا مبلغ مستحقّاً اليوم (قرار المالك: لا مقدَّم)، فلا فاتورة ولا سدادَ يسبق
     * فتح الملفّ — يُفتح بالقبول نفسه. والثابت: فاتورةٌ واحدة، والملفّ ينتظر السداد كما كان.
     */
    public static function openOnAcceptance(Execution $exec): void
    {
        // العنوان يُثبَّت هنا أيضاً لا عند التسعير وحده: صفوفٌ سُعِّرت قبل هذه الهجرة تصل
        // القبولَ بـ`pay_method` فارغ أو موروثٍ من القائمة القديمة، فيقرأ العميل عنواناً لا يصف ملفّه.
        $exec->update(['pay_method' => self::payMethodLabel($exec)]);

        if ($exec->feeMode() === 'percent') {
            $exec->update(['offer_status' => 'مقبول', 'pay_plan' => null, 'invoice_no' => null]);
            self::openFile($exec->fresh(), 'قُبل العرض بنموذج نسبة من المحصّل — يُرفع الطلب في ناجز');

            return;
        }

        /*
         * **فاتورةٌ واحدة مهما تكرّر القبول.** كانت المعاملة تغلّف الإنشاء **بلا قفلٍ ولا
         * إعادة فحص** — خلافاً لأختيها `openFile` و`openInstallmentPlan`: فنقرةٌ مزدوجة أو
         * إعادة إرسال POST تُصدر فاتورتين كاملتين، كلٌّ بـ`installment_no = 1`، فتبقى الثانية
         * ديناً أبديّاً في دفتر المكتب تدخل «غير المسدَّد» ثمّ «المتأخّر» في المحاسبة.
         * والقفل هنا على صفّ الطلب لا على الفاتورة: هو ما يتسابق عليه الطلبان.
         *
         * ورقم الفاتورة يُسَكّ **داخل** المعاملة: سكُّه خارجها كان يستهلك رقماً حتى للنداء
         * الذي لا يُنشئ شيئاً.
         */
        $issued = DB::transaction(function () use ($exec) {
            $locked = Execution::whereKey($exec->id)->lockForUpdate()->first();

            // مفتاح عدم التكرار هو `offer_status` نفسه الذي يكتبه النداء الأوّل داخل القفل
            // (نظير `effectiveStage() >= 7` في `openFile`) — لا المرحلة: حارسُها في
            // `ExecService::acceptOffer`، وتكرارُه هنا يمنع منادياً مشروعاً بلغ المرحلة 6.
            if ($locked === null || $locked->paid || $locked->offer_status === 'مقبول') {
                return false;
            }

            // الأساس والضريبة **من صفّ الطلب** لا محسوبين من نسبة اليوم: العرض اعتُمد ثمّ قَبِله
            // العميل بعد أيّام، فإعادةُ الحساب تُصدر فاتورةً بغير المبلغ الذي قَبِله إن عُدّلت
            // النسبة بينهما (`InvoiceFactory::fromFrozen`).
            $number = InvoiceNumber::next();
            InvoiceFactory::fromFrozen((int) $locked->fee, (int) $locked->vat, [
                'user_id' => $locked->user_id, 'exec_id' => $locked->id, 'number' => $number,
                'installment_no' => 1,
                'description' => 'أتعاب تنفيذ · '.$locked->number,
                ...InvoiceDue::execFee(), // المهلة من الإعدادات — التاريخ ونصّه من رقمٍ واحد
            ]);
            // الخطّة تبقى معلّقة حتى يختارها العميل عند السداد؛ والمجموع 1 حتى يُقسَّط
            $locked->update(['offer_status' => 'مقبول', 'invoice_no' => $number, 'installments_total' => 1]);

            return true;
        });

        if (! $issued) {
            return;
        }

        // المرحلة 6 انتقالٌ في المحرّك — كانت تُكتب هنا خارجه عبر `ExecService::sync`
        Workflow::run(new AcceptExecutionOffer, $exec, null, ['last_action' => 'قبل العميل العرض وصدرت الفاتورة']);
    }

    /**
     * **يفتح خطّة التقسيط**: يُعيد هيكلة فاتورة الأتعاب القائمة لتصير الدفعة الأولى، ويُصدر
     * لها أختين باستحقاقين متدرّجين. الكسر في الأولى فيساوي المجموعُ الأتعابَ بالضبط
     * (نفس منطق `CaseFee::openInstallmentPlan`) — ولا فتحَ ملفٍّ هنا: الملفّ يُفتح بأوّل
     * دفعةٍ **مسوّاة فعلاً**، لا باختيار الخطّة.
     *
     * @return Invoice|null فاتورة الدفعة الأولى، أو null إن لم يوجد ما يُقسَّم
     */
    public static function openInstallmentPlan(Execution $exec): ?Invoice
    {
        return DB::transaction(function () use ($exec) {
            $locked = Execution::whereKey($exec->id)->lockForUpdate()->first();

            if ($locked === null || $locked->paid || $locked->feeMode() !== 'fixed' || $locked->payPlan() === 'install') {
                return null;
            }

            $master = Invoice::where('exec_id', $locked->id)
                ->outstanding()
                ->orderBy('id')->first();
            if ($master === null) {
                return null;
            }

            // العدد من الإعدادات وقت فتح الخطّة ثمّ يُختم في `installments_total` — والخطّة
            // المفتوحة تُقرأ من صفّها لا من الإعداد، فتغييره لا يزحزح دفعاتِ ملفٍّ قائم.
            $count = SettingsRegistry::int('installments_count');

            $total = (int) $master->amount;
            $share = intdiv($total, $count);
            $first = $total - ($share * ($count - 1));

            // الضريبة تُقسَّم مع المبلغ — نظير `CaseFee::openInstallmentPlan`: الأمّ كانت تحمل
            // ضريبة الأتعاب كاملةً، فتقليصُ `amount` وحده يكسر `subtotal + vat_amount = amount`.
            // المهل من الإعدادات (`InvoiceDue::installment`) — نظير `CaseFee::openInstallmentPlan` بالقارئ نفسه.
            $master->update(array_merge(InvoiceFactory::taxFromTotal($first), [
                'installment_no' => 1,
                'description' => self::installmentLabel($locked, 1, $count),
                ...InvoiceDue::installment(1),
            ]));

            for ($n = 2; $n <= $count; $n++) {
                InvoiceFactory::fromTotal($share, [
                    'user_id' => $locked->user_id,
                    'exec_id' => $locked->id,
                    'installment_no' => $n,
                    'description' => self::installmentLabel($locked, $n, $count),
                    ...InvoiceDue::installment($n),
                ]);
            }

            $locked->update([
                'pay_plan' => 'install',
                'pay_method' => self::PLANS['install'],
                'installments_total' => $count,
                'installments_paid' => 0,
                'invoice_no' => $master->number,
            ]);

            return $master->fresh();
        });
    }

    /** الدفعة التالية في الخطّة — الأقدم غير المدفوعة **بترتيب الخطّة** لا بالأحدث. */
    public static function nextInstallment(Execution $exec): ?Invoice
    {
        return Invoice::where('exec_id', $exec->id)->whereNotNull('installment_no')
            ->outstanding()
            ->orderBy('installment_no')->orderBy('id')->first();
    }

    /**
     * **أوّل فاتورةٍ قابلة للسداد على الملفّ.** كان المسار يأخذ `latest('id')`، وهي بعد
     * التقسيم الدفعة **الثالثة**: فيُسدَّد قسطٌ ويبقى الأوّل مستحقّاً، ويعود العميل من
     * البوّابة بمرجعٍ لا يطابق فاتورته.
     */
    public static function nextPayable(Execution $exec): ?Invoice
    {
        return self::nextInstallment($exec)
            ?: Invoice::where('exec_id', $exec->id)
                ->outstanding()
                ->orderBy('id')->first();
    }

    /**
     * **نقطة التسوية الموحّدة لفاتورة تنفيذ** — تعرف وحدها أهي دفعةُ خطّة، أم سدادٌ كامل،
     * أم فاتورةُ أتعابٍ عن تحصيل. يناديها `PaymentReconciler` لمسارَي البوّابة والتحصيل
     * اليدويّ معاً.
     *
     * `$invoice` تصل من المُسوّي **مقلوبةً إلى مدفوعة سلفاً** — وعليه يقوم اشتقاقُ عدّ
     * الدفعات. والنداء المباشر (بلا فاتورة) يقلب **الأقدم غير المدفوعة وحدها**: كان
     * `latest('id')` هناك يشطب الدفعة الثالثة مجّاناً عند تسوية الأولى.
     */
    public static function settleInvoice(Execution $exec, ?Invoice $invoice = null): void
    {
        $invoice ??= self::nextPayable($exec);

        // **السداد بالمحرّك** (م٢) — نظير `CaseFee::markInvoicePaid`: كاتبٌ واحد لـ`paid`
        // و`paid_at` و«مدفوعة»، وسطرٌ في `journey_transitions` لم يكن يُكتب لفواتير التنفيذ.
        if ($invoice !== null && ! $invoice->paid) {
            try {
                Workflow::run(new SettleInvoice, $invoice, null, ['channel' => 'أتعاب تنفيذ']);
            } catch (TransitionDenied) {
                // لا تقبل السداد (ملغاة مثلاً) — لا تُعلَّم مدفوعةً، وبقيّة الأثر تتبع حالها
            }
        }

        // فاتورةُ أتعابٍ عن تحصيل: مالٌ للمكتب لا يفتح ملفّاً ولا يقدّم خطّة
        if ($exec->feeMode() === 'percent') {
            self::collectionFeePaid($exec, $invoice);

            return;
        }

        if ($exec->payPlan() === 'install') {
            self::markInstallmentPaid($exec);

            return;
        }

        self::openFile($exec, 'سُدّدت الأتعاب — يُرفع الطلب في ناجز');
    }

    /**
     * يقدّم خطّة التقسيط بعد تسوية دفعة — العدد **يُحتسب من الفواتير المدفوعة** لا يُزاد
     * يدوياً، فلا يمكن لنقرةٍ أو تكرار ويبهوك أن يقدّم الخطّة بلا مال. و`installment_no`
     * يمنع فاتورةَ تحصيلٍ من أن تُحسب دفعةً.
     */
    public static function markInstallmentPaid(Execution $exec): void
    {
        $result = DB::transaction(function () use ($exec) {
            $locked = Execution::whereKey($exec->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $paid = Invoice::where('exec_id', $locked->id)->whereNotNull('installment_no')
                ->where('paid', true)->count();
            $total = max(1, (int) $locked->installments_total);
            $done = $paid >= $total;
            $isFirst = $paid === 1 && (int) $locked->installments_paid === 0;

            $locked->update(['installments_paid' => $paid]);

            return ['paid' => $paid, 'total' => $total, 'done' => $done, 'first' => $isFirst];
        });

        if ($result === null || $result['paid'] === 0) {
            return;
        }

        $exec->refresh();
        $exec->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => $result['done']
                ? '<p>تم سداد الدفعة الأخيرة واكتملت أتعاب التنفيذ.</p>'
                : "<p>تم استلام الدفعة {$result['paid']} من {$result['total']} من أتعاب التنفيذ.</p>",
            'time_label' => ExecService::clock(),
        ]);

        // الملفّ يُفتح بأوّل دفعةٍ مسوّاة فعلاً — لا باختيار الخطّة
        if ($result['first']) {
            self::openFile($exec, 'سُدّدت الدفعة الأولى — يُرفع الطلب في ناجز');

            return;
        }

        ExecService::notifyOffice($exec, 't-green', "سُدّدت الدفعة {$result['paid']} من {$result['total']} من أتعاب التنفيذ {$exec->number}.");
        if ($result['done']) {
            ExecService::mail($exec, 'feePaidInFull');
        }
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /**
     * **فتح ملفّ التنفيذ — الموضع الوحيد الذي تقع فيه المرحلة 7.** آمنٌ ضدّ التسابق
     * (قفل الصفّ) وضدّ التكرار، ولا يرجع بملفٍّ إلى الوراء أبداً: `effectiveStage() >= 7`
     * يمنع دفعةً ثانيةً تُسوّى على ملفٍّ بلغ المرحلة 8 من أن تسحبه إلى 7 وتسكّ رقماً ثانياً.
     *
     * @return bool وقع الفتح الآن (لا سابقاً)
     */
    public static function openFile(Execution $exec, string $lastAction): bool
    {
        // السداد ورقم الملفّ والمرحلة في انتقالٍ واحد تحت قفل المحرّك — انظر `ActivateExecution`.
        // حارسه (غير مدفوع، دون المرحلة 7) هو حارس القفل الذي كان هنا، ورفضُه صامتٌ كما كان:
        // دفعةٌ ثانية أو ويبهوك مكرّر لا يفتح ملفّاً مفتوحاً ولا يُخطئ.
        // **ويقف عند 7 «بانتظار الرفع في ناجز»**: الملفّ لم يُرفع بعد ولا رقم طلبٍ له، فقفزُه
        // إلى «قيد التنفيذ» يُقرئ العميلَ أن دعواه تُنفَّذ ولم تبدأ.
        try {
            Workflow::run(new ActivateExecution, $exec, null, ['last_action' => $lastAction]);
        } catch (TransitionDenied|ModelNotFoundException) {
            return false;
        }

        $exec->refresh();
        $exec->procedures()->create(['title' => 'سداد الأتعاب وفتح ملفّ التنفيذ لدى المكتب', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);

        // **الخبر يصف ما وقع فعلاً.** الملفّ صار يُفتح بثلاثة أسباب مختلفة، فنصٌّ واحد
        // يكذب في اثنين: «سُدّدت الأتعاب» على ملفٍّ نسبيٍّ لم يُدفع فيه ريال، وعلى ملفٍّ
        // سُدّدت منه دفعةٌ من ثلاث.
        [$opened, $role, $event] = match (true) {
            $exec->feeMode() === 'percent' => ['قُبل العرض وفُتح ملفّ التنفيذ', 'فتح الملفّ', 'fileOpened'],
            $exec->payPlan() === 'install' => ['سُدّدت الدفعة الأولى من أتعاب التنفيذ', 'سداد', 'firstInstallmentPaid'],
            default => ['سُدّدت أتعاب التنفيذ', 'سداد', 'paid'],
        };

        $exec->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => $role,
            'body' => '<p>'.e($opened).'، ورقم الملفّ المرجعيّ الداخليّ <b>'.e((string) $exec->exec_no).'</b> — ويُرفع الطلب في منصّة ناجز.</p>',
            'time_label' => ExecService::clock(),
        ]);
        ExecService::notify($exec, 'check', 't-green', "{$opened} لطلبك {$exec->number} (الرقم المرجعيّ الداخليّ {$exec->exec_no}) — ويُرفع الطلب في منصّة ناجز.");
        ExecService::notifyOffice($exec, 't-green', "فُتح ملفّ التنفيذ للطلب {$exec->number} — يلزم رفعه في منصّة ناجز.");
        // **والبريد يتبع السبب كما تتبعه الرسالة.** كان `'paid'` واحداً للأسباب الثلاثة،
        // ونصّه «تم استلام سداد الأتعاب بنجاح» — يصل صاحبَ ملفٍّ نسبيٍّ لم يدفع ريالاً،
        // وصاحبَ خطّةٍ سدّد دفعةً من ثلاث فيقرأ أن أتعابه اكتملت.
        ExecService::mail($exec, $event);
        Live::push(new ExecStatusBroadcast($exec));

        return true;
    }

    /**
     * **فاتورة أتعابٍ عن مبلغٍ محصَّل** — في النموذج النسبيّ وحده. النسبة تُطبَّق على المحصَّل
     * لا على المطالبة، وتُقرَّب لأقرب ريال، والضريبة على الأساس المقرَّب فيساوي الإجماليُ
     * الأساسَ والضريبةَ بالضبط. وكلّ فاتورةٍ قائمةٌ بذاتها بلا تسويةٍ تراكميّة — والأساس
     * مطبوعٌ في وصفها كي يبقى الفرق مفهوماً حين يُحصَّل المبلغ نفسه على دفعتين.
     *
     * وملفٌّ بنموذجٍ ثابت: يُسجَّل تحصيله ولا يُفوتَر — بلا استثناء يُرمى.
     */
    public static function issueCollectionFee(Execution $exec, int $collected, string $note = ''): ?Invoice
    {
        if ($exec->feeMode() !== 'percent' || $collected < 1) {
            return null;
        }

        $pct = (float) $exec->collection_fee_pct;
        $base = (int) round($collected * $pct / 100);
        if ($pct <= 0 || $base < 1) {
            return null; // نسبةٌ لم تُحدَّد، أو أتعابٌ دون الريال — فاتورةٌ بصفر لا تُصدَر
        }

        // الأساس يُقرَّر **الآن** (نسبةٌ من محصَّلٍ وقع للتوّ)، فالضريبة بنسبة اليوم — وهي
        // تُجمَّد على الصفّ لحظةَ الإصدار (`InvoiceFactory::fromBase`).
        return InvoiceFactory::fromBase($base, [
            'user_id' => $exec->user_id, 'exec_id' => $exec->id,
            'installment_no' => null,
            'description' => 'أتعاب تنفيذ '.self::pctLabel($pct).'% من تحصيل '.number_format($collected).' ريال · '.$exec->number
                .($note !== '' ? ' — '.$note : ''),
            ...InvoiceDue::collection(), // المهلة من الإعدادات — التاريخ ونصّه من رقمٍ واحد
        ]);
    }

    /** سُدّدت فاتورة أتعابٍ عن تحصيل: خبرٌ للمكتب — لا مرحلةَ تتحرّك ولا رقمَ يُسَكّ. */
    private static function collectionFeePaid(Execution $exec, ?Invoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        $exec->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => '<p>سُدّدت فاتورة أتعاب التنفيذ '.e((string) $invoice->number).' ('.number_format((int) $invoice->amount).' ريال).</p>',
            'time_label' => ExecService::clock(),
        ]);
        ExecService::notifyOffice($exec, 't-green', "سُدّدت فاتورة أتعاب التنفيذ {$invoice->number} على الطلب {$exec->number}.");
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /** النسبة كما تُقرأ: «10» لا «10.00»، و«7.5» تبقى كما هي. */
    public static function pctLabel(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    }

    /** العدد يُمرَّر لا يُقرأ هنا: وصفُ فاتورةٍ يجب أن يوافق خطّتَها هي، لا الإعدادَ يوم قراءته. */
    private static function installmentLabel(Execution $exec, int $n, int $count): string
    {
        return "أتعاب تنفيذ {$exec->number} — الدفعة {$n} من ".$count;
    }
}
