<?php

namespace App\Support\Reports;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Http\Controllers\Admin\JourneyTransitionController;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Support\CaseJourney;

/**
 * **لقطة الأداء — المصدر الواحد لشاشة `/admin/reports` ولتقريرها PDF** (نظير `Finance\RevenueSnapshot`).
 *
 * لماذا صنفٌ قائمٌ بذاته؟ كان الحساب مكتوباً مرّتين في `ReportController` (`reports()` و`reportsPdf()`)،
 * فتفترق الشاشة والتقرير عند أوّل تعديل — و«القضايا النشطة» فيهما كانت قائمةً يدويّة تُسقط «بانتظار
 * القيد» بينما `CaseJourney::ACTIVE` تضمّها. كلّ رقمٍ هنا من تعريفٍ في المجال لا من قائمةٍ تُكتب هنا:
 *
 * - **التذاكر المحسومة** = `TicketStatus::finals()` (المكتملة والمغلقة والمحوّلة) — والنشطة ما عداها.
 * - **القضايا** = مجموعات `CaseJourney` (النشطة · المحكومة · المغلقة).
 * - **مراحل التنفيذ** بـ`Execution::effectiveStage()` و`isClosed()`: كانت تُعدّ بعمود `stage` الخام، فالمغلق
 *   بحالته (مرحلته أقلّ من ٩) يُعدّ مرّتين — في مرحلته وفي «مكتمل» — والقديم بلا مرحلة لا يُعدّ في شيء.
 */
final class PerformanceSnapshot
{
    /** اختصار أسماء الأقسام للرسم البياني — الشاشة وحدها؛ التقرير يطبع الاسم كاملاً. */
    public const DEPT_SHORT = [
        'القسم التجاري' => 'تجاري', 'القسم العمالي' => 'عمالي', 'القسم العقاري' => 'عقاري',
        'الأحوال الشخصية' => 'أحوال', 'التنفيذ' => 'تنفيذ', 'عام' => 'عام',
    ];

    /**
     * @param  array<string,int>  $stats
     * @param  list<array{m:string,v:int}>  $executionsByStage
     * @param  list<array{dept:string,c:int}>  $ticketsByDept
     * @param  list<array{dept:string,c:int}>  $casesByDept
     */
    private function __construct(
        public readonly array $stats,
        public readonly array $executionsByStage,
        public readonly array $ticketsByDept,
        public readonly array $casesByDept,
    ) {}

    public static function build(): self
    {
        // ── التذاكر ──
        $totalTickets = Ticket::count();
        $convertedToCase = Ticket::where('status', TicketStatus::ConvertedToCase->value)->count();
        $convertedToExecution = Ticket::where('status', TicketStatus::ConvertedToExecution->value)->count();
        $finishedTickets = Ticket::whereIn('status', TicketStatus::finals())->count();
        // «مغلقة ومكتملة» بلا المحوّلة — المحوّلة لها عدّادها، وجمعُها هنا كان سيُعدّها مرّتين على الشاشة
        $closedTickets = Ticket::whereIn('status', [TicketStatus::Closed->value, TicketStatus::Completed->value])->count();
        $activeTickets = Ticket::whereNotIn('status', TicketStatus::finals())->where('is_frozen', false)->count();

        // ── الاستشارات ── (عدا الملغاة) — كان عنوان «إجمالي الاستشارات والتذاكر» يعرض التذاكر وحدها
        $totalConsults = Consult::where('status', '!=', ConsultStatus::Cancelled->value)->count();

        // ── القضايا ──
        $totalCases = LegalCase::count();
        $activeCases = LegalCase::whereIn('status', CaseJourney::ACTIVE)->count();
        $ruledCases = LegalCase::whereIn('status', array_merge(CaseJourney::JUDGED, CaseJourney::CLOSED))->count();
        // «مستأنفة» كانت تُقرأ هنا حالةً ولا يكتبها أيّ مسار — الاستئناف علَمُه `appeal_status`
        $appealedCases = LegalCase::whereNotNull('appeal_status')->count();

        // ── التنفيذ ──
        $buckets = ['study' => 0, 'najiz' => 0, 'court' => 0, 'closed' => 0];
        foreach (Execution::query()->get(['id', 'stage', 'status']) as $e) {
            $stage = $e->effectiveStage();
            $buckets[match (true) {
                $e->isClosed() => 'closed',
                $stage >= 8 => 'court',
                $stage === 7 => 'najiz',
                default => 'study',
            }]++;
        }
        $totalExecutions = array_sum($buckets);
        $totalDebtEnforced = (int) Execution::sum('amount');
        $totalCollectedDebts = (int) Execution::sum('collected');

        $stats = [
            'totalTickets' => $totalTickets,
            'totalConsults' => $totalConsults,
            'closureRate' => $totalTickets ? (int) round($finishedTickets / $totalTickets * 100) : 0,
            'convertedToCase' => $convertedToCase,
            'convertedToExecution' => $convertedToExecution,
            'conversionRate' => $totalTickets ? (int) round($convertedToCase / $totalTickets * 100) : 0,
            'finishedTickets' => $finishedTickets,
            'closedTickets' => $closedTickets,
            'activeTickets' => $activeTickets,
            'meetingsHeld' => Meeting::where('status', MeetingStatus::Ended->value)->count(),
            'totalCases' => $totalCases,
            'activeCases' => $activeCases,
            'ruledCases' => $ruledCases,
            'caseRulingRate' => $totalCases ? (int) round($ruledCases / $totalCases * 100) : 0,
            'appealedCases' => $appealedCases,
            'totalExecutions' => $totalExecutions,
            'activeExecutions' => $buckets['court'],
            'completedExecutions' => $buckets['closed'],
            'totalDebtEnforced' => $totalDebtEnforced,
            'totalCollectedDebts' => $totalCollectedDebts,
            'collectionSuccessRate' => $totalDebtEnforced > 0 ? (int) round($totalCollectedDebts / $totalDebtEnforced * 100) : 0,
            'totalTransitions' => JourneyTransition::count(),
        ];

        return new self(
            stats: $stats,
            executionsByStage: [
                ['m' => 'دراسة وأتعاب', 'v' => $buckets['study']],
                ['m' => 'بانتظار ناجز', 'v' => $buckets['najiz']],
                ['m' => 'قيد إجراءات المحكمة', 'v' => $buckets['court']],
                ['m' => 'مكتمل ومغلق', 'v' => $buckets['closed']],
            ],
            ticketsByDept: self::byDept(Ticket::query(), 'غير مصنّف', 6),
            casesByDept: self::byDept(LegalCase::query(), 'عام', 5),
        );
    }

    /** @return list<array{dept:string,c:int}> */
    private static function byDept($query, string $fallback, int $limit): array
    {
        return $query->selectRaw('COALESCE(NULLIF(department, ""), ?) as dept, COUNT(*) as c', [$fallback])
            ->groupBy('dept')->orderByDesc('c')->limit($limit)->get()
            ->map(fn ($r) => ['dept' => (string) $r->dept, 'c' => (int) $r->c])->values()->all();
    }

    /** @return list<array{m:string,v:int}> — للرسم: اسم القسم المختصر */
    public static function chart(array $rows): array
    {
        return array_map(fn (array $r) => ['m' => self::DEPT_SHORT[$r['dept']] ?? $r['dept'], 'v' => $r['c']], $rows);
    }

    /** توزيع أسباب إغلاق التذاكر بتسمياتها — والرمز المجهول يُعرض كما هو لا يُسقَط. */
    public static function closureReasons(): array
    {
        $counts = Ticket::whereNotNull('closure_reason_code')
            ->selectRaw('closure_reason_code, COUNT(*) as count')
            ->groupBy('closure_reason_code')
            ->pluck('count', 'closure_reason_code')
            ->all();

        $out = [];
        foreach (ClosureReasonCode::cases() as $reason) {
            if (($counts[$reason->value] ?? 0) > 0) {
                $out[] = ['m' => $reason->label(), 'v' => (int) $counts[$reason->value]];
            }
        }
        foreach ($counts as $raw => $cnt) {
            if ($cnt > 0 && ClosureReasonCode::tryFrom((string) $raw) === null) {
                $out[] = ['m' => (string) $raw, 'v' => (int) $cnt];
            }
        }

        return $out;
    }

    /**
     * آخر الحركات **بتسمياتها العربيّة** — من قاموس سجلّ الانتقالات نفسه (`JourneyTransitionController`)
     * لا نسخةٍ هنا. كانت الشاشة تعرض `class_basename` («LegalCase») ورمز الانتقال («ticket.convert_to_case»).
     */
    public static function recentTransitions(int $limit = 8): array
    {
        return JourneyTransition::with('actor:id,name,role')
            ->latest('id')->limit($limit)->get()
            ->map(function (JourneyTransition $t) {
                $type = class_basename((string) $t->entity_type);

                return [
                    'id' => $t->id,
                    'ref' => $t->entity_ref ?: '#'.$t->entity_id,
                    'type' => JourneyTransitionController::ENTITY_LABELS[$type] ?? $type,
                    'transition' => JourneyTransitionController::humanTransitionName($t->transition),
                    'from' => $t->from_state,
                    'to' => $t->to_state,
                    'actor' => $t->actor?->name ?? 'النظام',
                    'time' => $t->created_at?->diffForHumans() ?? '—',
                ];
            })->all();
    }
}
