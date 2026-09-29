<?php

namespace App\Support\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PayoutKind;
use App\Models\Expense;
use App\Models\StaffPayout;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * **التقارير الماليّة** (المرحلة د): الإيرادات والمصروفات والأرباح والخسائر لفترةٍ، مقارنةً بالفترة
 * السابقة المقابلة (`FinanceBoard::previousPeriod`)، وجدولٌ شهريّ داخل الفترة.
 *
 * قرارات المالك: الإيراد **عند التحصيل**، والأرباح **بلا ضريبة** (ضريبة المخرجات للهيئة لا للمكتب،
 * وضريبة المدخلات تُستردّ) — فالربح = صافي المحصَّل − صافي المصروفات المعتمدة − مستحقّات الموظّفين
 * المصروفة فعلاً (غير الملغاة). وكلّ رقمٍ من مصدره الواحد: الإيراد من `RevenueSnapshot`، والمصروف من
 * `Expense::counted`، والصرف من `StaffPayout::active` — لا استعلامٌ ثانٍ لمفهومٍ واحد.
 *
 * المبالغ كلّها بالهللة: الفواتير والصرف بالريال الصحيح (×١٠٠) والمصروفات بالهللة أصلاً.
 */
final class ProfitAndLoss
{
    /**
     * @param  array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string}  $period
     * @return array<string, mixed>
     */
    public static function report(array $period): array
    {
        $previous = FinanceBoard::previousPeriod($period);
        $now = self::slice($period['from'], $period['to']);
        $before = self::slice($previous['from'], $previous['to']);

        return [
            'period' => self::periodInfo($period),
            'previous' => self::periodInfo($previous),
            'summary' => [
                'current' => $now['summary'],
                'previous' => $before['summary'],
            ],
            'revenueByKind' => self::compare(self::REVENUE_KINDS, $now['revenue'], $before['revenue']),
            'expensesByCategory' => self::compare(
                array_combine(ExpenseCategory::values(), array_map(fn (ExpenseCategory $c) => $c->label(), ExpenseCategory::cases())),
                $now['expenses'],
                $before['expenses'],
            ),
            'payoutsByKind' => self::compare(
                array_combine(array_map(fn (PayoutKind $k) => $k->value, PayoutKind::cases()), array_map(fn (PayoutKind $k) => $k->label(), PayoutKind::cases())),
                $now['payouts'],
                $before['payouts'],
            ),
            'months' => self::months($period['from'], $period['to']),
            // تنبيهان لا يدخلان الأرقام: مدفوعاتٌ قديمة بلا تاريخ، ومصروفاتٌ لم تُعتمد بعد
            'undatedPaid' => RevenueSnapshot::paidWithoutDate(),
            'pendingExpenses' => Expense::where('status', ExpenseStatus::Pending->value)
                ->whereBetween('spent_on', [$period['from']->toDateString(), $period['to']->toDateString()])
                ->count(),
        ];
    }

    /** أنواع الإيراد — ترتيب `RevenueSnapshot::splitByKind` وتسمياته. */
    private const REVENUE_KINDS = [
        'consult' => 'الاستشارات',
        'case' => 'القضايا',
        'exec' => 'التنفيذ',
        'other' => 'غير مصنَّف',
    ];

    /**
     * أرقام فترةٍ واحدة.
     *
     * @return array{
     *   summary: array{revenue:int, revenueVat:int, expenses:int, expensesVat:int, payouts:int, profit:int},
     *   revenue: array<string,int>, expenses: array<string,int>, payouts: array<string,int>
     * }
     */
    public static function slice(CarbonInterface $from, CarbonInterface $to): array
    {
        $collected = RevenueSnapshot::collectedBetween($from, $to);
        $byKind = RevenueSnapshot::collectedByKindBetween($from, $to);
        $revenue = array_map(fn (int $riyals) => $riyals * 100, array_diff_key($byKind, ['total' => 0]));

        $expenseRows = Expense::counted()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, COALESCE(SUM(amount_halalas - vat_halalas), 0) AS net, COALESCE(SUM(vat_halalas), 0) AS vat')
            ->groupBy('category')
            ->toBase()
            ->get();
        $expenses = [];
        foreach ($expenseRows as $row) {
            $expenses[(string) $row->category] = (int) $row->net;
        }

        $payouts = StaffPayout::active()
            ->whereBetween('paid_at', [CarbonImmutable::instance($from)->startOfDay(), CarbonImmutable::instance($to)->endOfDay()])
            ->selectRaw('kind, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('kind')
            ->toBase()
            ->pluck('total', 'kind')
            ->map(fn ($riyals) => (int) $riyals * 100)
            ->all();

        $revenueNet = $byKind['total'] * 100;
        $expensesNet = array_sum($expenses);
        $payoutsTotal = array_sum($payouts);

        return [
            'summary' => [
                'revenue' => $revenueNet,
                'revenueVat' => $collected['vat'] * 100,
                'expenses' => $expensesNet,
                'expensesVat' => (int) $expenseRows->sum('vat'),
                'payouts' => $payoutsTotal,
                'profit' => $revenueNet - $expensesNet - $payoutsTotal,
            ],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'payouts' => $payouts,
        ];
    }

    /**
     * الأشهر داخل الفترة، والأوّل والأخير مقصوصان على حدّيها — فمجموع الأشهر = مجموع الفترة.
     *
     * @return list<array{month:string, label:string, revenue:int, expenses:int, payouts:int, profit:int}>
     */
    private static function months(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->endOfDay();
        $rows = [];

        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $sliceFrom = $month->max($start);
            $sliceTo = $month->endOfMonth()->min($end);
            $s = self::slice($sliceFrom, $sliceTo)['summary'];
            $rows[] = [
                'month' => $month->format('Y-m'),
                'label' => StaffEarnings::monthLabel($month),
                'revenue' => $s['revenue'],
                'expenses' => $s['expenses'],
                'payouts' => $s['payouts'],
                'profit' => $s['profit'],
            ];
        }

        return $rows;
    }

    /**
     * بنود التصنيف بقيمتيها (الفترة والسابقة)؛ ما كان صفراً في الاثنتين لا يُعرض.
     *
     * @param  array<string,string>  $labels
     * @param  array<string,int>  $now
     * @param  array<string,int>  $before
     * @return list<array{key:string, label:string, current:int, previous:int}>
     */
    private static function compare(array $labels, array $now, array $before): array
    {
        $rows = [];
        foreach ($labels as $key => $label) {
            $current = $now[$key] ?? 0;
            $previous = $before[$key] ?? 0;
            if ($current !== 0 || $previous !== 0) {
                $rows[] = ['key' => $key, 'label' => $label, 'current' => $current, 'previous' => $previous];
            }
        }

        return $rows;
    }

    /**
     * @param  array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string}  $period
     * @return array{key:string, label:string, from:string, to:string}
     */
    private static function periodInfo(array $period): array
    {
        return ['key' => $period['key'], 'label' => $period['label'], 'from' => $period['fromDate'], 'to' => $period['toDate']];
    }

    /** نسبة التغيّر بين فترتين — `null` حين لا أساس للمقارنة (السابقة صفر). */
    public static function change(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}
