<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Support\Finance\RevenueSnapshot;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقارير وإيرادات الإدارة العليا — تجميعات حقيقية ومؤشرات ذكاء أعمال (BI & KPIs) لكافة دورات النظام.
 */
class ReportController extends Controller
{
    // اختصار أسماء الأقسام للرسم البياني
    private const DEPT_SHORT = [
        'القسم التجاري' => 'تجاري', 'القسم العمالي' => 'عمالي', 'القسم العقاري' => 'عقاري',
        'الأحوال الشخصية' => 'أحوال', 'التنفيذ' => 'تنفيذ', 'عام' => 'عام',
    ];

    public function reports(): Response
    {
        // ── 1. دورة التذاكر والاستشارات (Tickets & Consultations) ──
        $totalTickets = Ticket::count();
        $convertedToCase = Ticket::where('status', TicketStatus::ConvertedToCase->value)->count();
        $closedTickets = Ticket::whereIn('status', ['مكتملة', 'مغلقة', TicketStatus::Closed->value])
            ->orWhereNotNull('closure_reason_code')
            ->count();
        $activeTickets = Ticket::whereNotIn('status', [
            TicketStatus::Closed->value,
            TicketStatus::ConvertedToCase->value,
            'مكتملة', 'مغلقة',
        ])->where('is_frozen', false)->count();

        $conversionRate = $totalTickets ? (int) round($convertedToCase / $totalTickets * 100) : 0;
        $closureRate = $totalTickets ? (int) round($closedTickets / $totalTickets * 100) : 0;

        // التذاكر حسب القسم (أعلى 6)
        $byDept = Ticket::selectRaw('COALESCE(NULLIF(department, ""), "غير مصنّف") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['m' => self::DEPT_SHORT[$r->dept] ?? $r->dept, 'v' => (int) $r->c])->values();

        // توزيع أسباب الإغلاق المسبب (ClosureReasonCode)
        $closureReasonCounts = Ticket::whereNotNull('closure_reason_code')
            ->selectRaw('closure_reason_code, COUNT(*) as count')
            ->groupBy('closure_reason_code')
            ->pluck('count', 'closure_reason_code')
            ->all();

        $byClosureReason = [];
        foreach (ClosureReasonCode::cases() as $reasonEnum) {
            $count = $closureReasonCounts[$reasonEnum->value] ?? 0;
            if ($count > 0) {
                $byClosureReason[] = [
                    'm' => $reasonEnum->label(),
                    'v' => (int) $count,
                ];
            }
        }
        foreach ($closureReasonCounts as $rawCode => $cnt) {
            if ($cnt > 0 && ClosureReasonCode::tryFrom($rawCode) === null) {
                $byClosureReason[] = [
                    'm' => (string) $rawCode,
                    'v' => (int) $cnt,
                ];
            }
        }

        // ── 2. دورة القضايا القضائية (Legal Cases & Appeals) ──
        $totalCases = LegalCase::count();
        $activeCases = LegalCase::whereIn('status', ['قيد التحضير', 'منظورة', CaseStatus::InPreparation->value, CaseStatus::InCourt->value])->count();
        $ruledCases = LegalCase::whereIn('status', ['صدر الحكم', 'مغلقة', 'مؤرشفة', CaseStatus::Judged->value, CaseStatus::Closed->value, CaseStatus::Archived->value])->count();
        $appealedCases = LegalCase::whereNotNull('appeal_status')
            ->orWhere('status', 'مستأنفة')
            ->count();
        $caseRulingRate = $totalCases ? (int) round($ruledCases / $totalCases * 100) : 0;

        $casesByDept = LegalCase::selectRaw('COALESCE(NULLIF(department, ""), "عام") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['m' => self::DEPT_SHORT[$r->dept] ?? $r->dept, 'v' => (int) $r->c])->values();

        // ── 3. دورة التنفيذ القضائي (ExecFlow Judicial Enforcement) ──
        $totalExecutions = Execution::count();
        $activeExecutions = Execution::where('stage', 8)->count(); // قيد الإجراءات بمحكمة التنفيذ
        $pendingNajizExecutions = Execution::where('stage', 7)->count(); // بانتظار الرفع في ناجز
        $underStudyExecutions = Execution::whereIn('stage', [0, 1, 2, 3, 4, 5, 6])->count();
        $completedExecutions = Execution::where('stage', 9)->orWhereIn('status', Execution::CLOSED_STATUSES)->count();

        $totalDebtEnforced = (int) Execution::sum('amount');
        $totalCollectedDebts = (int) Execution::sum('collected');
        $collectionSuccessRate = $totalDebtEnforced > 0 ? (int) round($totalCollectedDebts / $totalDebtEnforced * 100) : 0;

        $executionsByStage = [
            ['m' => 'دراسة وأتعاب', 'v' => $underStudyExecutions],
            ['m' => 'بانتظار ناجز', 'v' => $pendingNajizExecutions],
            ['m' => 'قيد إجراءات المحكمة', 'v' => $activeExecutions],
            ['m' => 'مكتمل ومغلق', 'v' => $completedExecutions],
        ];

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

        // ── 4. الرقابة وسجل الانتقالات الموثقة (Audit & Transition Trails) ──
        $totalTransitions = JourneyTransition::count();
        $recentTransitions = JourneyTransition::with('actor:id,name,role')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'ref' => $t->entity_ref ?: '#'.$t->entity_id,
                'type' => class_basename($t->entity_type),
                'transition' => $t->transition,
                'from' => $t->from_state,
                'to' => $t->to_state,
                'actor' => $t->actor?->name ?? 'النظام',
                'time' => $t->created_at?->diffForHumans() ?? '—',
            ]);

        return Inertia::render('admin/reports', [
            'stats' => [
                'totalTickets' => $totalTickets,
                'closureRate' => $closureRate,
                'convertedToCase' => $convertedToCase,
                'conversionRate' => $conversionRate,
                'closedTickets' => $closedTickets,
                'activeTickets' => $activeTickets,
                'meetingsHeld' => Meeting::where('status', 'منتهٍ')->count(),
                'totalCases' => $totalCases,
                'activeCases' => $activeCases,
                'ruledCases' => $ruledCases,
                'caseRulingRate' => $caseRulingRate,
                'appealedCases' => $appealedCases,
                'totalExecutions' => $totalExecutions,
                'activeExecutions' => $activeExecutions,
                'completedExecutions' => $completedExecutions,
                'totalDebtEnforced' => $totalDebtEnforced,
                'totalCollectedDebts' => $totalCollectedDebts,
                'collectionSuccessRate' => $collectionSuccessRate,
                'totalTransitions' => $totalTransitions,
            ],
            'byDept' => $byDept,
            'byClosureReason' => $byClosureReason,
            'casesByDept' => $casesByDept,
            'executionsByStage' => $executionsByStage,
            'byExecClosureReason' => $byExecClosureReason,
            'recentTransitions' => $recentTransitions,
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

    /** تصدير تقرير الأداء ومؤشرات الإنجاز PDF. */
    public function reportsPdf(): \Symfony\Component\HttpFoundation\Response
    {
        $totalTickets = Ticket::count();
        $convertedToCase = Ticket::where('status', TicketStatus::ConvertedToCase->value)->count();
        $closedTickets = Ticket::whereIn('status', ['مكتملة', 'مغلقة', TicketStatus::Closed->value])
            ->orWhereNotNull('closure_reason_code')
            ->count();
        $closureRate = $totalTickets ? (int) round($closedTickets / $totalTickets * 100) : 0;
        $conversionRate = $totalTickets ? (int) round($convertedToCase / $totalTickets * 100) : 0;

        $totalCases = LegalCase::count();
        $activeCases = LegalCase::whereIn('status', ['قيد التحضير', 'منظورة', CaseStatus::InPreparation->value, CaseStatus::InCourt->value])->count();
        $ruledCases = LegalCase::whereIn('status', ['صدر الحكم', 'مغلقة', 'مؤرشفة', CaseStatus::Judged->value, CaseStatus::Closed->value, CaseStatus::Archived->value])->count();

        $totalExecutions = Execution::count();
        $totalCollectedDebts = (int) Execution::sum('collected');
        $totalDebtEnforced = (int) Execution::sum('amount');
        $collectionRate = $totalDebtEnforced > 0 ? (int) round($totalCollectedDebts / $totalDebtEnforced * 100) : 0;

        $byDept = Ticket::selectRaw('COALESCE(NULLIF(department, ""), "غير مصنّف") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(6)->get();

        $casesByDept = LegalCase::selectRaw('COALESCE(NULLIF(department, ""), "عام") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(5)->get();

        $html = ReportPrint::html([
            'title' => 'تقرير الأداء المؤسسي ومؤشرات الإنجاز',
            'subtitle' => 'شامل دورات التذاكر، القضايا، والتنفيذ القضائي حتى '.now()->format('Y-m-d'),
            'ref' => 'RPT-'.now()->format('Ymd'),
            'blocks' => [
                [
                    'title' => '١. المؤشرات التنفيذية الرئيسية (Executive Scorecard)',
                    'cellRows' => [
                        [['إجمالي الاستشارات والتذاكر', (string) $totalTickets], ['معدل التحويل لقضايا', $conversionRate.'%']],
                        [['القضايا النشطة المنظورة', (string) $activeCases], ['الأحكام الصادرة', (string) $ruledCases]],
                        [['طلبات التنفيذ القضائي', (string) $totalExecutions], ['نسبة نجاح التحصيل', $collectionRate.'%']],
                    ],
                ],
                [
                    'title' => '٢. التذاكر والاستشارات حسب القسم',
                    'cellRows' => $byDept->map(fn ($r) => [[$r->dept, (string) $r->c.' تذكرة']])->all(),
                ],
                [
                    'title' => '٣. القضايا القضائية حسب القسم',
                    'cellRows' => $casesByDept->map(fn ($r) => [[$r->dept, (string) $r->c.' قضية']])->all(),
                ],
                [
                    'title' => '٤. مؤشرات التنفيذ القضائي والتحصيل المالي',
                    'cellRows' => [
                        [['إجمالي الديون المنفذ بها', number_format($totalDebtEnforced).' ر.س'], ['المبالغ المحصلة فعلياً', number_format($totalCollectedDebts).' ر.س']],
                        [['نسبة استرداد الحقوق', $collectionRate.'%'], ['حركات الانتقال الموثقة (FSM)', (string) JourneyTransition::count().' حركة']],
                    ],
                ],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — تقرير الأداء العام الداخلي',
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
                        array_map(fn (array $u) => [[$u['name'], number_format($u['salary']).' ر.س']], $snap->salaries)
                    ),
                ],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — تقرير ماليّ ورقابي داخلي',
        ]);

        return PdfRenderer::render($html, 'revenue-'.now()->format('Y-m-d').'.pdf');
    }
}
