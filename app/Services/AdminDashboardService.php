<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    private const CLOSED_CASES = ['مغلقة', 'مؤرشفة'];

    public function get360Data(bool $bypassCache = false): array
    {
        $cacheKey = 'admin:dashboard:360:metrics_v1';

        if (app()->environment('testing') || $bypassCache) {
            Cache::forget($cacheKey);

            return $this->compute360Metrics();
        }

        return Cache::remember($cacheKey, 180, fn () => $this->compute360Metrics());
    }

    private function compute360Metrics(): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfPrevMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfPrevMonth = $now->copy()->subMonth()->endOfMonth();

        // 1. المالية (Invoices)
        $invoiceStats = DB::table('invoices')
            ->selectRaw('
                COALESCE(SUM(amount), 0) as total_billed,
                COALESCE(SUM(CASE WHEN paid = 1 THEN amount ELSE 0 END), 0) as total_collected,
                COALESCE(SUM(CASE WHEN paid = 0 THEN amount ELSE 0 END), 0) as total_unpaid,
                COUNT(CASE WHEN paid = 0 AND due_at IS NOT NULL AND due_at < ? THEN 1 END) as overdue_count,
                COALESCE(SUM(CASE WHEN paid = 1 AND updated_at >= ? THEN amount ELSE 0 END), 0) as current_month_collected,
                COALESCE(SUM(CASE WHEN paid = 1 AND updated_at >= ? AND updated_at <= ? THEN amount ELSE 0 END), 0) as prev_month_collected
            ', [$now->toDateString(), $startOfMonth, $startOfPrevMonth, $endOfPrevMonth])
            ->first();

        $totalBilled = (int) ($invoiceStats->total_billed ?? 0);
        $totalCollected = (int) ($invoiceStats->total_collected ?? 0);
        $totalUnpaid = (int) ($invoiceStats->total_unpaid ?? 0);
        $overdueCount = (int) ($invoiceStats->overdue_count ?? 0);
        $currentMonthCollected = (int) ($invoiceStats->current_month_collected ?? 0);
        $prevMonthCollected = (int) ($invoiceStats->prev_month_collected ?? 0);

        $collectionRate = $totalBilled > 0 ? (int) round(($totalCollected / $totalBilled) * 100) : 100;
        $revenueGrowth = $prevMonthCollected > 0
            ? round((($currentMonthCollected - $prevMonthCollected) / $prevMonthCollected) * 100, 1)
            : ($currentMonthCollected > 0 ? 100 : 0);

        // 2. التذاكر
        $ticketStats = DB::table('tickets')
            ->selectRaw("
                COUNT(*) as total_tickets,
                COUNT(CASE WHEN status NOT IN ('مكتملة', 'مغلقة') THEN 1 END) as open_tickets,
                COUNT(CASE WHEN status NOT IN ('مكتملة', 'مغلقة') AND assigned_lawyer_id IS NULL THEN 1 END) as unassigned_tickets
            ")
            ->first();

        // 3. القضايا
        $caseStats = DB::table('cases')
            ->selectRaw("
                COUNT(*) as total_cases,
                COUNT(CASE WHEN status NOT IN ('مغلقة', 'مؤرشفة') THEN 1 END) as active_cases,
                COUNT(CASE WHEN status IN ('مغلقة', 'مؤرشفة') THEN 1 END) as closed_cases
            ")
            ->first();

        // 4. التنفيذ
        // **الطلب المرفوض ليس ملفّاً نشطاً.** `ExecService::reject` يكتب القرار ولا ينقل المرحلة
        // عمداً (المرفوض ليس مغلقاً)، فكان يُعَدّ في «التنفيذات النشطة» وتُجمع قيمة مطالبته في
        // المبلغ النشط — رقمٌ ماليّ في لوحة الإدارة يضمّ طلباتٍ لن تُنفَّذ. و`decision` تقبل NULL،
        // فالمقارنة تُكتب NULL-safe وإلّا أسقطت كلّ طلبٍ لم يُبتّ فيه بعد.
        // والشرط مرّةً واحدة (كان مكرّراً حرفياً في العدّ والمجموع)، وحالتا الإنهاء من `Execution`.
        $closedExecs = "'".implode("','", Execution::CLOSED_STATUSES)."'";
        $activeExec = "(stage IS NULL OR stage < 9) AND status NOT IN ({$closedExecs}) AND (decision IS NULL OR decision <> 'مرفوض')";

        $execStats = DB::table('executions')
            ->selectRaw("
                COUNT(*) as total_execs,
                COUNT(CASE WHEN {$activeExec} THEN 1 END) as active_execs,
                COALESCE(SUM(CASE WHEN {$activeExec} THEN amount ELSE 0 END), 0) as active_amount
            ")
            ->first();

        /*
         * 5. الاستشارات والاجتماعات
         *
         * **«قادمة» تعني قادمةً فعلاً.** كان العدّاد `status IN ('جديدة','بانتظار الجلسة')`
         * بلا أيّ قيدٍ زمنيّ ولا قيدِ قناة، والشاشة تعرضه «{n} استشارة **مرئية قادمة**»:
         * فاستشارةٌ حالتُها «جديدة» منذ ثلاثة أسابيع فات موعدها تُعدّ موعداً آتياً. وفيه
         * خللٌ ثانٍ: `'بانتظار الجلسة'` قيمةٌ من عمود `session` لا من `status`
         * (‏`Consult::SESSIONS` مقابل `Consult::STATUSES`)، فنصف الشرط ميّتٌ لا يطابق صفّاً.
         * القياس الآن يطابق ما تقوله الشاشة: مرئيّةٌ، جلستُها لم تنتهِ، وموعدُها آتٍ.
         */
        $consultStats = DB::table('consults')
            ->selectRaw("
                COUNT(*) as total_consults,
                COUNT(CASE WHEN status = 'بانتظار التسعير' THEN 1 END) as pending_price,
                COUNT(CASE WHEN channel = 'مرئية' AND session NOT IN ('منتهية', 'لم تُعقد')
                    AND starts_at IS NOT NULL AND starts_at > ? THEN 1 END) as upcoming_sessions
            ", [now()])
            ->first();

        $pendingMeetingApprovals = Meeting::where('approve', '!=', 'معتمد')
            ->where('status', 'منتهٍ')
            ->count();

        // ما ينتظر الإدارة وحدها — كان `whereNull('approved_at')` يعدّ ما لم يعتمده المحامي بعد أيضاً
        $pendingSummaryApprovals = TicketSummary::where('status', 'awaiting_admin')->count();

        // 6. رادار الإجراءات العاجلة
        $actionRadar = [];

        if (($ticketStats->unassigned_tickets ?? 0) > 0) {
            $actionRadar[] = [
                'id' => 'unassigned-tickets',
                'title' => 'تذاكر جديدة بانتظار التوزيع',
                'count' => (int) $ticketStats->unassigned_tickets,
                'desc' => "يوجد {$ticketStats->unassigned_tickets} تذكرة بحاجة لتعيين مستشار مختص",
                'cta' => 'توزيع التذاكر',
                'link' => route('admin.distribute'),
                'tone' => 'amber',
                'icon' => 'reply',
            ];
        }

        if (($consultStats->pending_price ?? 0) > 0) {
            $actionRadar[] = [
                'id' => 'pending-prices',
                'title' => 'طلبات استشارة بانتظار التسعير',
                'count' => (int) $consultStats->pending_price,
                'desc' => "يوجد {$consultStats->pending_price} طلب بحاجة لتحديد السعر وإصدار الفاتورة",
                'cta' => 'تسعير الطلبات',
                'link' => route('admin.consult-requests'),
                'tone' => 'blue',
                'icon' => 'card',
            ];
        }

        if ($pendingMeetingApprovals > 0) {
            $actionRadar[] = [
                'id' => 'pending-meetings',
                'title' => 'محاضر اجتماعات بانتظار الاعتماد',
                'count' => $pendingMeetingApprovals,
                'desc' => "يوجد {$pendingMeetingApprovals} محضر اجتماع بحاجة لاعتماد الإدارة",
                'cta' => 'اعتماد المحاضر',
                'link' => route('admin.meetmgmt'),
                'tone' => 'purple',
                'icon' => 'video',
            ];
        }

        if ($pendingSummaryApprovals > 0) {
            $actionRadar[] = [
                'id' => 'pending-summaries',
                'title' => 'ملخصات ملفات بانتظار الاعتماد',
                'count' => $pendingSummaryApprovals,
                'desc' => "يوجد {$pendingSummaryApprovals} ملخص قانوني جاهز للمراجعة",
                'cta' => 'مراجعة الملخصات',
                'link' => route('admin.summaries'),
                'tone' => 'cyan',
                'icon' => 'folder',
            ];
        }

        if ($overdueCount > 0) {
            $actionRadar[] = [
                'id' => 'overdue-invoices',
                'title' => 'فواتير متجاوزة الاستحقاق',
                'count' => $overdueCount,
                'desc' => "يوجد {$overdueCount} فاتورة مستحقة متأخرة السداد",
                'cta' => 'متابعة المحاسبة',
                'link' => route('admin.finance', ['tab' => 'aging']),
                'tone' => 'red',
                'icon' => 'alert',
            ];
        }

        // 7. جلسات المحاكم القادمة
        $upcomingHearings = CaseHearing::with(['legalCase.assignedLawyer'])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->take(5)
            ->get()
            ->map(fn (CaseHearing $h) => [
                'id' => $h->id,
                'title' => $h->title,
                'caseNumber' => $h->legalCase?->number ?? '—',
                'caseType' => $h->legalCase?->type ?? 'قضية',
                'court' => $h->court ?: 'المحكمة العامة',
                'startsAt' => $h->starts_at?->toIso8601String(),
                'formattedDate' => $h->starts_at?->locale('ar')->translatedFormat('l d F Y') ?? (string) $h->day,
                'formattedTime' => $h->starts_at?->locale('ar')->translatedFormat('h:i A') ?? (string) $h->time,
                'isToday' => $h->starts_at?->isToday() ?? false,
                'lawyer' => $h->legalCase?->assignedLawyer?->name ?? ($h->legalCase?->assigned_lawyer ?: 'غير معين'),
            ]);

        // 8. مصفوفة فريق المحامين
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->get()
            ->map(function (User $lawyer) {
                $activeCases = LegalCase::where('assigned_lawyer_id', $lawyer->id)
                    ->whereNotIn('status', self::CLOSED_CASES)
                    ->count();

                $activeTickets = Ticket::where('assigned_lawyer_id', $lawyer->id)
                    ->open()
                    ->count();

                // **حملٌ لا إعلان.** يُقاس بالاستشارات المفتوحة على المحامي — والمفتوحُ عملٌ
                // قائمٌ مهما طال، فلا قيدَ زمنيّ هنا (بخلاف عدّاد «قادمة» أعلاه). وأُسقطت
                // `'بانتظار الجلسة'`: قيمةُ عمود `session` لا `status`، فلم تكن تطابق شيئاً.
                $openConsults = Consult::where('assigned_lawyer_id', $lawyer->id)
                    ->whereNotIn('status', Consult::CLOSED_STATUSES)
                    ->count();

                $workloadScore = ($activeCases * 3) + ($activeTickets * 1.5) + ($openConsults * 2);
                $status = $workloadScore < 8 ? 'available' : ($workloadScore <= 20 ? 'moderate' : 'high');

                return [
                    'id' => $lawyer->id,
                    'name' => $lawyer->name,
                    'jobTitle' => $lawyer->job_title ?: 'مستشار ومحامٍ',
                    'department' => $lawyer->department ?: 'الاستشارات العامة',
                    'initials' => $lawyer->avatar_initials ?: 'مح',
                    'activeCases' => $activeCases,
                    'activeTickets' => $activeTickets,
                    'upcomingConsults' => $openConsults,
                    'status' => $status,
                ];
            });

        // 9. مسار الإيرادات الشهري (6 أشهر)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = $now->copy()->subMonths($i)->startOfMonth();
            $mEnd = $now->copy()->subMonths($i)->endOfMonth();
            $monthName = $mStart->locale('ar')->translatedFormat('F');

            $mBilled = (int) Invoice::whereBetween('created_at', [$mStart, $mEnd])->sum('amount');
            $mCollected = (int) Invoice::where('paid', true)->whereBetween('updated_at', [$mStart, $mEnd])->sum('amount');

            $monthlyRevenue[] = [
                'month' => $monthName,
                'billed' => $mBilled,
                'collected' => $mCollected,
            ];
        }

        // 10. توزيع القضايا حسب التخصص
        $departments = LegalCase::whereNotNull('department')
            ->selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        $totalDeptCases = $departments->sum('count') ?: 1;
        $practiceAreas = $departments->map(fn ($d) => [
            'name' => $d->department,
            'count' => (int) $d->count,
            'percentage' => (int) round(($d->count / $totalDeptCases) * 100),
        ])->values();

        // 11. نبض العمليات الحية
        $activity = collect()
            ->concat(Ticket::with('user')->latest('id')->take(4)->get()->map(fn ($t) => [
                'id' => 't-'.$t->id,
                'ico' => 'folder',
                'title' => 'تذكرة جديدة #'.$t->number,
                'sub' => ($t->user?->name ?? 'عميل').' · '.($t->type ?: 'طلب عام'),
                'time' => $t->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
                // مرساةٌ زمنيّة للفرز — `time` نصٌّ مقروء لا يصلح مفتاحاً، و`id` نصٌّ ببادئة
                'at' => $t->created_at?->getTimestamp() ?? 0,
                'tag' => 'تذاكر',
                'tone' => 'cyan',
            ]))
            ->concat(LegalCase::with('user')->latest('id')->take(3)->get()->map(fn ($c) => [
                'id' => 'c-'.$c->id,
                'ico' => 'scale',
                'title' => 'قضية #'.$c->number,
                'sub' => ($c->user?->name ?? 'عميل').' · '.($c->type ?: 'دعوى قضائية'),
                'time' => $c->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
                // مرساةٌ زمنيّة للفرز — `time` نصٌّ مقروء لا يصلح مفتاحاً، و`id` نصٌّ ببادئة
                'at' => $c->created_at?->getTimestamp() ?? 0,
                'tag' => 'قضايا',
                'tone' => 'blue',
            ]))
            ->concat(Consult::with('user')->latest('id')->take(3)->get()->map(fn ($cn) => [
                'id' => 'cn-'.$cn->id,
                'ico' => 'video',
                'title' => 'استشارة #'.$cn->ref,
                'sub' => ($cn->user?->name ?? 'عميل').' · '.($cn->channel ?: 'مرئية'),
                'time' => $cn->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
                // مرساةٌ زمنيّة للفرز — `time` نصٌّ مقروء لا يصلح مفتاحاً، و`id` نصٌّ ببادئة
                'at' => $cn->created_at?->getTimestamp() ?? 0,
                'tag' => 'استشارات',
                'tone' => 'green',
            ]))
            // **الفرز بالزمن لا بالنصّ.** كان `$item['id']` نصّاً ببادئة (`'t-12'`/`'c-3'`)،
            // فيفرز معجميّاً: كلّ التذاكر أوّلاً ثمّ الاستشارات ثمّ القضايا، و`t-9` فوق `t-10`.
            ->sortByDesc('at')
            ->take(8)
            ->values();

        return [
            'overview' => [
                'clientsCount' => User::where('role', Role::Client)->count(),
                'activeCases' => (int) ($caseStats->active_cases ?? 0),
                'openTickets' => (int) ($ticketStats->open_tickets ?? 0),
                'activeExecutions' => (int) ($execStats->active_execs ?? 0),
                'activeExecAmount' => (int) ($execStats->active_amount ?? 0),
                'upcomingSessions' => (int) ($consultStats->upcoming_sessions ?? 0),
            ],
            'finance' => [
                'totalCollected' => $totalCollected,
                'totalUnpaid' => $totalUnpaid,
                'totalBilled' => $totalBilled,
                'collectionRate' => $collectionRate,
                'currentMonthCollected' => $currentMonthCollected,
                'prevMonthCollected' => $prevMonthCollected,
                'revenueGrowth' => $revenueGrowth,
                'overdueCount' => $overdueCount,
            ],
            'radar' => $actionRadar,
            'upcomingHearings' => $upcomingHearings,
            'lawyersWorkload' => $lawyers,
            'revenueTrajectory' => $monthlyRevenue,
            'practiceAreas' => $practiceAreas,
            'liveActivity' => $activity,
        ];
    }
}
