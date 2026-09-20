<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
 * **ولا تصفية بالتاريخ هنا** لأنّ `invoices` لا تحمل `paid_at` (ب١ في خطّة النظام الماليّ).
 * فكلّ رقمٍ في هذه اللقطة «منذ البداية». وهذا سببُ حذف مؤشّر «صافي التدفّق بعد الرواتب»:
 * كان يطرح رواتب **شهرٍ واحد** من إيراد **العمر كلّه**، ولا معنى للرقم بأيّ تفسير. يعود
 * حين يتوفّر `paid_at` فتصير التصفية بالفترة ممكنة (م١).
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
        $due = (int) self::notCancelled()->where('paid', false)->sum('amount');

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

    /** @return Builder<Invoice> */
    private static function notCancelled(): Builder
    {
        return Invoice::where('status', '!=', InvoiceStatus::Cancelled->value);
    }
}
