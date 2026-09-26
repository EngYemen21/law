<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * **لقطة الإيرادات — المصدر الواحد لشاشة `/admin/revenue` ولتقريرها PDF.**
 *
 * لماذا صنفٌ قائمٌ بذاته؟ لأنّ الحساب كان مكتوباً مرّتين حرفيّاً في `ReportController`
 * (`revenue()` و`revenuePdf()`)، فإصلاح أحدهما يترك الآخر يكذب — وهو بعينه ما ولّد العطل.
 * كلّ رقمٍ ماليّ يُعرض على المالك يُشتقّ من هنا، ومن هنا وحده.
 *
 * **القاعدة الحاكمة: الدخل هو الفاتورة المدفوعة، لا أكثر ولا أقلّ.**
 *
 * كان `totalFirmGross` يجمع ثلاثة مصادر مستقلّة — إيراد الاستشارات من جدول `consults`،
 * و«كلّ فاتورةٍ مدفوعة» من `invoices`، وأتعاب التنفيذ من جدول `executions` — وهي تتقاطع:
 * فاتورة الاستشارة داخل المجموعين الأوّل والثاني، وفاتورة التنفيذ داخل الثاني والثالث،
 * وأتعاب نسبة التحصيل كانت تُحسب حساباً (`collected × pct`) **سواءٌ صدرت فاتورةٌ أم لا
 * وسواءٌ سُدّدت أم لا**. فالاستشارة تُعَدّ مرّتين والتنفيذ مرّتين.
 *
 * الآن: المجموع مصدرهُ جدولٌ واحد، والتصنيف (استشارات · قضايا · تنفيذ · غير مصنَّف)
 * **تقسيمٌ** لذلك المجموع بحسب الرابط على الفاتورة نفسها — فمجموع الأقسام = المجموع دائماً.
 *
 * **وما ليس إيراداً لا يُخلَط به:** `totalDebtEnforced` و`totalCollectedDebts` و`collectionRate`
 * مبالغ ديونٍ تُحصَّل **لصالح الموكّلين**، لا مالَ المكتب. و`execFixedFees`/`execPercentFees`
 * مؤشّرا أداءٍ للتنفيذ (مستحَقٌّ محسوب) لا يدخلان الدخل — دخلُ التنفيذ هو `execIncome` وحده.
 *
 * **و`build()` بلا تصفيةٍ بالتاريخ**: كلّ رقمٍ في هذه اللقطة «منذ البداية». وهذا سببُ حذف
 * مؤشّر «صافي التدفّق بعد الرواتب» في م٠: كان يطرح رواتب **شهرٍ واحد** من إيراد **العمر
 * كلّه**، ولا معنى للرقم بأيّ تفسير. و`collectedBetween()` أدناه هي الفترةُ التي صارت ممكنة
 * بعد أن حملت `invoices` عمود `paid_at` (م١) — ومنها تُبنى مؤشّرات الفترة حين تعود.
 */
final class RevenueSnapshot
{
    /**
     * @param  list<array{m:string,v:int}>  $byService
     * @param  list<array{name:string,salary:int}>  $salaries
     */
    private function __construct(
        public readonly int $totalIncome,
        public readonly int $consultIncome,
        public readonly int $caseIncome,
        public readonly int $execIncome,
        public readonly int $otherIncome,
        public readonly int $issued,
        public readonly int $due,
        public readonly int $bookings,
        public readonly int $unbilledPaidConsults,
        public readonly int $execFixedFees,
        public readonly int $execPercentFees,
        public readonly int $totalDebtEnforced,
        public readonly int $totalCollectedDebts,
        public readonly int $collectionRate,
        public readonly ?int $invoiceCollectionRate,
        public readonly array $byService,
        public readonly array $salaries,
        public readonly int $salaryTotal,
    ) {}

    public static function build(): self
    {
        $income = self::incomeSplit();

        // الملغاة ليست ذمّةً على أحد: كانت تُجمَع في «الصادر» وتُطرح منها المحصَّلات، فتظهر
        // بكامل مبلغها ديناً على العميل إلى الأبد. والذمّة تُقاس مباشرةً — غيرُ ملغاةٍ وغير
        // مدفوعة — لا بطرح مجموعٍ من مجموع، فلا تصير سالبةً إن سُدّدت فاتورةٌ ثمّ أُلغيت.
        $issued = (int) self::notCancelled()->sum('amount');
        $due = (int) self::receivables()->sum('amount');

        $paidConsults = Consult::whereNotNull('paid_at');

        $totalDebtEnforced = (int) Execution::sum('amount');
        $totalCollectedDebts = (int) Execution::sum('collected');

        $staff = User::whereIn('role', [Role::Employee, Role::Lawyer])
            ->where('status', '!=', 'suspended')->where('salary', '>', 0)
            ->orderByDesc('salary')->get(['name', 'salary']);

        return new self(
            totalIncome: $income['total'],
            consultIncome: $income['consult'],
            caseIncome: $income['case'],
            execIncome: $income['exec'],
            otherIncome: $income['other'],
            issued: $issued,
            due: $due,
            bookings: (clone $paidConsults)->count(),
            unbilledPaidConsults: self::unbilledPaidConsults(),
            execFixedFees: (int) Execution::where('paid', true)->where('fee_mode', 'fixed')->sum('fee'),
            execPercentFees: (int) Execution::where('fee_mode', 'percent')
                ->selectRaw('SUM(collected * COALESCE(collection_fee_pct, 0) / 100) as pct_fee')
                ->value('pct_fee'),
            totalDebtEnforced: $totalDebtEnforced,
            totalCollectedDebts: $totalCollectedDebts,
            collectionRate: $totalDebtEnforced > 0 ? (int) round($totalCollectedDebts / $totalDebtEnforced * 100) : 0,
            // **المحصَّل ÷ الصادر** — كانت الشاشة تشتقّها `(الصادر − الذمم) ÷ الصادر`، والمعدومة خارج
            // الذمم وداخل الصادر، فتُحسب **محصَّلةً** وهي مالٌ أُسقطت مطالبته. والمدفوعة لا تكون ملغاةً ولا
            // معدومةً (`paid` لا يصحّ عليهما) فالمحصَّل هو `totalIncome` نفسه. null = لا صادر (لا مقياس).
            invoiceCollectionRate: $issued > 0 ? (int) round($income['total'] / $issued * 100) : null,
            byService: self::consultIncomeByChannel(),
            salaries: $staff->map(fn ($u) => ['name' => (string) $u->name, 'salary' => (int) $u->salary])->values()->all(),
            salaryTotal: (int) $staff->sum('salary'),
        );
    }

    /** الحمولة التي تقرؤها شاشة `admin/revenue`. */
    public function toArray(): array
    {
        return [
            'totalIncome' => $this->totalIncome,
            'consultIncome' => $this->consultIncome,
            'caseIncome' => $this->caseIncome,
            'execIncome' => $this->execIncome,
            'otherIncome' => $this->otherIncome,
            'issued' => $this->issued,
            'due' => $this->due,
            'bookings' => $this->bookings,
            'unbilledPaidConsults' => $this->unbilledPaidConsults,
            'execFixedFees' => $this->execFixedFees,
            'execPercentFees' => $this->execPercentFees,
            'totalDebtEnforced' => $this->totalDebtEnforced,
            'totalCollectedDebts' => $this->totalCollectedDebts,
            'collectionRate' => $this->collectionRate,
            'invoiceCollectionRate' => $this->invoiceCollectionRate,
            'byService' => $this->byService,
            'salaries' => $this->salaries,
            'salaryTotal' => $this->salaryTotal,
        ];
    }

    /**
     * صفوف كتلة «الإيرادات» في تقرير PDF.
     *
     * التسميات هنا لا في المتحكّم: الرقم واسمُه يخرجان من مصدرٍ واحد، فلا تعود الشاشة
     * تقول «دخل الاستشارات» ويقول التقرير غيرَه عن الحقل نفسه.
     *
     * @return list<list<array{0:string,1:string}>>
     */
    public function printCellRows(): array
    {
        return [
            [['إجمالي الدخل المحصل للمكتب', self::money($this->totalIncome)], ['الذمم المستحقة (فواتير غير ملغاة)', self::money($this->due)]],
            [['دخل الاستشارات المحصَّل', self::money($this->consultIncome)], ['دخل أتعاب القضايا المحصَّل', self::money($this->caseIncome)]],
            [['دخل التنفيذ المحصَّل', self::money($this->execIncome)], ['إجمالي الفواتير الصادرة (غير الملغاة)', self::money($this->issued)]],
        ];
    }

    private static function money(int $amount): string
    {
        return number_format($amount).' ر.س';
    }

    /**
     * تقسيم مجموع الفواتير المدفوعة على الروابط الثلاثة — استعلامٌ واحد يضمن أنّ
     * `consult + case + exec + other = total` بحكم البناء لا بحكم الحظّ.
     *
     * الأولويّة (استشارة ثمّ قضيّة ثمّ تنفيذ) تمنع احتساب فاتورةٍ تحمل رابطين مرّتين.
     *
     * @return array{total:int,consult:int,case:int,exec:int,other:int}
     */
    private static function incomeSplit(): array
    {
        $row = Invoice::where('paid', true)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total_income')
            ->selectRaw('COALESCE(SUM(CASE WHEN consult_id IS NOT NULL THEN amount ELSE 0 END), 0) AS consult_income')
            ->selectRaw('COALESCE(SUM(CASE WHEN consult_id IS NULL AND case_id IS NOT NULL THEN amount ELSE 0 END), 0) AS case_income')
            ->selectRaw('COALESCE(SUM(CASE WHEN consult_id IS NULL AND case_id IS NULL AND exec_id IS NOT NULL THEN amount ELSE 0 END), 0) AS exec_income')
            ->first();

        $total = (int) ($row->total_income ?? 0);
        $consult = (int) ($row->consult_income ?? 0);
        $case = (int) ($row->case_income ?? 0);
        $exec = (int) ($row->exec_income ?? 0);

        return [
            'total' => $total,
            'consult' => $consult,
            'case' => $case,
            'exec' => $exec,
            // فواتير يدويّة لا ترتبط بملفّ — تبقى في المجموع ولا تُنسَب لقطاعٍ كذباً
            'other' => $total - $consult - $case - $exec,
        ];
    }

    /**
     * توزيع **دخل الاستشارات المحصَّل** على قنواتها.
     *
     * كان المصدر `SUM(consults.total)` — رقمٌ ثانٍ لمفهومٍ واحد يخالف مجموع الفواتير
     * كلّما وُجدت استشارةٌ مسدَّدة بلا فاتورة. الآن هو تقسيمٌ لـ`consultIncome` نفسه.
     *
     * @return list<array{m:string,v:int}>
     */
    private static function consultIncomeByChannel(): array
    {
        return Invoice::where('invoices.paid', true)
            ->join('consults', 'consults.id', '=', 'invoices.consult_id')
            ->selectRaw('consults.channel AS channel, SUM(invoices.amount) AS revenue')
            ->groupBy('consults.channel')
            ->get()
            ->map(fn ($row) => ['m' => (string) ($row->channel ?: 'أخرى'), 'v' => (int) $row->revenue])
            ->values()
            ->all();
    }

    /**
     * استشاراتٌ عليها `paid_at` وليست لها فاتورةٌ مدفوعة.
     *
     * **القرار: لا تُضمّ إلى الدخل، وتُعلَن بعددها.** مسار الإنتاج الوحيد الذي يكتب
     * `paid_at` هو الانتقال `SettlePayment`، وهو يستوجب فاتورةً ويعلّمها مدفوعةً في الحركة
     * نفسها — فالاستشارة المسدَّدة بلا فاتورة **خللُ بياناتٍ** (بذورُ عرضٍ أو سجلٌّ قديم)
     * لا دخلٌ يملك المكتب إثباته. وضمُّها كان يعني مصدرَ دخلٍ ثانياً — وهو بعينه العطل.
     * فتُستثنى من المال، ويُعرض عددها على الشاشة كي لا يُخفى النقص بدل أن يُحسب خطأً.
     */
    private static function unbilledPaidConsults(): int
    {
        return Consult::whereNotNull('paid_at')
            ->whereNotIn('id', Invoice::whereNotNull('consult_id')->where('paid', true)->select('consult_id'))
            ->count();
    }

    /**
     * **الدخل المحصَّل في فترة** — أوّل رقمٍ ماليٍّ بفترةٍ يصير ممكناً في النظام (م١).
     *
     * قبل عمود `paid_at` كان البديل الوحيد `updated_at`، وهو يتحرّك مع كلّ تعديلٍ غير ذي صلة
     * (تذكير، رفع إثبات، تصحيح وصف) — فـ«إيراد سبتمبر» لم يكن له جوابٌ صادق. وهذا سببُ غياب
     * التصفية بالتاريخ من `ReportController` كلّه: البيانات لم تكن تسمح بها.
     *
     * **والمعيار تاريخُ التحصيل لا تاريخُ الإصدار** — الاعتراف بالإيراد **عند التحصيل**
     * (ق٧ في الخطّة). و`vat` مجموعُ الضريبة المحصَّلة في الفترة نفسها، وهو أساس إقرار الربع
     * (م٩). والملغاة والمعدومة خارجه بحكم البناء: `paid` لا يكون صحيحاً عليهما.
     *
     * **ولا شاشة تقرؤه بعد** — التبويبات في م٩ وم١٠. وهو هنا لأنّ المصدر الواحد للدخل هو
     * هذا الصنف، ولأنّ رقماً بفترةٍ يُحسب في شاشةٍ سيصير رقماً ثانياً يخالف هذا.
     *
     * @param  string|\DateTimeInterface  $from  بداية الفترة (مشمولة)
     * @param  string|\DateTimeInterface  $to  نهايتها (مشمولة بكامل يومها)
     * @return array{total:int, vat:int, subtotal:int, count:int}
     */
    public static function collectedBetween(string|\DateTimeInterface $from, string|\DateTimeInterface $to): array
    {
        $row = Invoice::where('paid', true)
            ->whereBetween('paid_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(vat_amount), 0) AS vat')
            ->selectRaw('COUNT(*) AS invoices')
            ->first();

        $total = (int) ($row->total ?? 0);
        $vat = (int) ($row->vat ?? 0);

        // الأساس بالطرح لا بجمعٍ ثانٍ: `subtotal + vat_amount = amount` على كلّ صفّ، فمجموعها
        // كذلك — والطرح يضمن ألّا يفترق الثلاثة إن بقي صفٌّ قديم بلا أعمدة ضريبة.
        return ['total' => $total, 'vat' => $vat, 'subtotal' => $total - $vat, 'count' => (int) ($row->invoices ?? 0)];
    }

    /** @return Builder<Invoice> */
    private static function notCancelled(): Builder
    {
        return Invoice::where('status', '!=', InvoiceStatus::Cancelled->value);
    }

    /**
     * **تعريف «الذمّة» — المصدر الواحد** الذي تقرؤه هذه اللقطة وشاشة `/admin/finance`
     * (‏`Finance\FinanceBoard`: بطاقتا الذمم والمتأخّر، وتبويب الأعمار، وأعلى المدينين).
     *
     * ذمّةٌ = مبلغٌ ما زال المكتب يطالب به: **غير مدفوع، ولا ملغىً، ولا معدوم**.
     *
     * - **الملغاة** ليست ذمّةً على أحد — كانت تظهر بكامل مبلغها ديناً إلى الأبد.
     * - **المعدومة** أُسقطت مطالبتُها بقرارٍ إداريّ مسبَّب (`WriteOffInvoice`)، وهذا بعينه
     *   سببُ وجود الحالة: «كان المكتب يطالب بديونٍ لا تُحصَّل وتبقى منفوخةً في المستحقّ»
     *   (ب٦). وم٢ أضافت الحالة ولم تُخرجها من هذا الحساب، فبقيت منفوخةً بها — وهنا تخرج.
     *
     * ولذلك هو **مرشّحٌ مشترك** لا نسختان: رقمُ «الذمم» في `/admin/revenue` ورقمُه في
     * `/admin/finance` من تعريفٍ واحد، فلا يفترقان.
     *
     * @return Builder<Invoice>
     */
    public static function receivables(): Builder
    {
        return Invoice::where('paid', false)
            ->whereNotIn('status', [InvoiceStatus::Cancelled->value, InvoiceStatus::WrittenOff->value]);
    }

    /**
     * **أهذه الفاتورة ذمّةٌ على صاحبها؟** — تعريفُ `receivables()` نفسه مطبَّقاً على صفٍّ واحد.
     *
     * لماذا هنا لا في الواجهة؟ لأنّ شاشة العميل كانت تحسب الذمّة بـ`!paid` وحده، فتعُدّ الملغاة
     * والمعدومة ديناً: عُرض على العميل ٢٤٬٠٣٥ ر.س وذمّتُه ١٧٬٥١٩، والفرق فاتورةٌ أُلغيت — بينما
     * شاشة الإدارة تعرض الرقم الصحيح من `receivables()`. رقمان لمفهومٍ واحد، والعميل يأخذ الخطأ.
     * فالتعريف يبقى في موضعٍ واحد، وتقرؤه الشاشتان.
     */
    public static function isReceivable(Invoice $invoice): bool
    {
        return ! $invoice->paid && ! in_array(
            $invoice->status,
            [InvoiceStatus::Cancelled->value, InvoiceStatus::WrittenOff->value],
            true
        );
    }
}
