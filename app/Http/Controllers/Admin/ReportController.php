<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Support\Finance\RevenueSnapshot;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use App\Support\Reports\PerformanceSnapshot;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقارير وإيرادات الإدارة العليا — تجميعات حقيقية ومؤشرات ذكاء أعمال (BI & KPIs) لكافة دورات النظام.
 *
 * **لا حساب هنا**: الأداء من `Reports\PerformanceSnapshot` والإيرادات من `Finance\RevenueSnapshot`،
 * والشاشة وتقريرها PDF يقرآن اللقطة نفسها — كان كلٌّ منهما يحسب بنفسه فيفترقان.
 */
class ReportController extends Controller
{
    public function reports(): Response
    {
        $snap = PerformanceSnapshot::build();

        $execClosureCounts = Execution::whereNotNull('closed_reason')
            ->selectRaw('closed_reason, COUNT(*) as count')
            ->groupBy('closed_reason')
            ->pluck('count', 'closed_reason')
            ->all();

        $byExecClosureReason = [];
        foreach ($execClosureCounts as $r => $cnt) {
            if ($cnt > 0) {
                $byExecClosureReason[] = [
                    'm' => (string) $r,
                    'v' => (int) $cnt,
                ];
            }
        }

        return Inertia::render('admin/reports', [
            'stats' => $snap->stats,
            'byDept' => PerformanceSnapshot::chart($snap->ticketsByDept),
            'byClosureReason' => PerformanceSnapshot::closureReasons(),
            'casesByDept' => PerformanceSnapshot::chart($snap->casesByDept),
            'executionsByStage' => $snap->executionsByStage,
            'byExecClosureReason' => $byExecClosureReason,
            'recentTransitions' => PerformanceSnapshot::recentTransitions(),
        ]);
    }

    /**
     * شاشة الإيرادات — كلّ أرقامها من `RevenueSnapshot` وحدها.
     * كان الحساب مكتوباً هنا وفي `revenuePdf()` حرفيّاً، فيُصلَح أحدهما ويبقى الآخر يكذب.
     */
    public function revenue(): Response
    {
        return Inertia::render('admin/revenue', RevenueSnapshot::build()->toArray());
    }

    /** تصدير تقرير الأداء ومؤشرات الإنجاز PDF — اللقطة نفسها التي تقرؤها الشاشة. */
    public function reportsPdf(): \Symfony\Component\HttpFoundation\Response
    {
        $snap = PerformanceSnapshot::build();
        $s = $snap->stats;

        $html = ReportPrint::html([
            'title' => 'تقرير الأداء المؤسسي ومؤشرات الإنجاز',
            'subtitle' => 'شامل دورات التذاكر، القضايا، والتنفيذ القضائي حتى '.now()->format('Y-m-d'),
            'ref' => 'RPT-'.now()->format('Ymd'),
            'blocks' => [
                [
                    'title' => '١. المؤشرات التنفيذية الرئيسية (Executive Scorecard)',
                    'cellRows' => [
                        [['إجمالي التذاكر', (string) $s['totalTickets']], ['إجمالي الاستشارات (عدا الملغاة)', (string) $s['totalConsults']]],
                        [['معدل تحويل التذاكر لقضايا', $s['conversionRate'].'%'], ['القضايا النشطة', (string) $s['activeCases']]],
                        [['الأحكام الصادرة', (string) $s['ruledCases']], ['طلبات التنفيذ القضائي', (string) $s['totalExecutions']]],
                        [['نسبة نجاح التحصيل', $s['collectionSuccessRate'].'%']],
                    ],
                ],
                [
                    'title' => '٢. التذاكر حسب القسم',
                    'cellRows' => array_map(fn (array $r) => [[$r['dept'], $r['c'].' تذكرة']], $snap->ticketsByDept),
                ],
                [
                    'title' => '٣. القضايا القضائية حسب القسم',
                    'cellRows' => array_map(fn (array $r) => [[$r['dept'], $r['c'].' قضية']], $snap->casesByDept),
                ],
                [
                    'title' => '٤. مؤشرات التنفيذ القضائي والتحصيل المالي',
                    'cellRows' => [
                        [['إجمالي الديون المنفذ بها', number_format($s['totalDebtEnforced']).' ر.س'], ['المبالغ المحصلة فعلياً', number_format($s['totalCollectedDebts']).' ر.س']],
                        [['نسبة استرداد الحقوق', $s['collectionSuccessRate'].'%'], ['حركات الانتقال الموثقة (FSM)', $s['totalTransitions'].' حركة']],
                    ],
                ],
            ],
        ]);

        return PdfRenderer::render($html, 'reports-'.now()->format('Y-m-d').'.pdf');
    }

    /** تصدير تقرير الإيرادات والتدفق المالي PDF. */
    public function revenuePdf(): \Symfony\Component\HttpFoundation\Response
    {
        // نفس اللقطة التي تقرؤها الشاشة — لا حسابَ ثانياً هنا
        $snap = RevenueSnapshot::build();

        $html = ReportPrint::html([
            'title' => 'تقرير الإيرادات ومؤشرات التدفق المالي والتحصيل',
            'subtitle' => 'شامل إيرادات الاستشارات، أتعاب القضايا، والتنفيذ حتى '.now()->format('Y-m-d'),
            'ref' => 'REV-'.now()->format('Ymd'),
            'blocks' => [
                [
                    'title' => '١. الإيرادات والتدفق النقدي للمكتب',
                    'cellRows' => $snap->printCellRows(),
                ],
                [
                    'title' => '٢. التحصيل المالي في قضايا التنفيذ',
                    'cellRows' => [
                        [['إجمالي المبالغ المنفذ بها', number_format($snap->totalDebtEnforced).' ر.س'], ['المبالغ المحصلة للعملاء', number_format($snap->totalCollectedDebts).' ر.س']],
                        [['نسبة نجاح التحصيل', $snap->collectionRate.'%'], ['أتعاب نسبة التحصيل', number_format($snap->execPercentFees).' ر.س']],
                    ],
                ],
                [
                    'title' => '٣. الإيراد حسب قناة الاستشارة',
                    'cellRows' => array_map(fn (array $r) => [[$r['m'], number_format($r['v']).' ر.س']], $snap->byService),
                ],
                [
                    'title' => '٤. الرواتب الثابتة الشهرية',
                    'cellRows' => array_merge(
                        [[['إجمالي الرواتب', number_format($snap->salaryTotal).' ر.س'], ['عدد الموظفين', (string) count($snap->salaries).' موظف']]],
                        [[['المصروف للموظفين (سجلّ الصرف)', number_format($snap->staffPaidTotal).' ر.س']]],
                        array_map(fn (array $u) => [[$u['name'], number_format($u['salary']).' ر.س']], $snap->salaries)
                    ),
                ],
            ],
        ]);

        return PdfRenderer::render($html, 'revenue-'.now()->format('Y-m-d').'.pdf');
    }
}
