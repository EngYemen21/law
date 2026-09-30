<?php

namespace App\Services;

use App\Domain\Journey\Enums\ExecutionDecision;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AdminApprovalQueue;
use App\Support\ArabicCount;
use App\Support\Finance\RevenueSnapshot;
use App\Support\LawyerWorkload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    // v3: التحصيل بـ`paid_at` والرادار من `AdminApprovalQueue` — الشكل تغيّر، فمفتاحٌ جديد يتخطّى المخزَّن
    public const CACHE_KEY = 'admin:dashboard:360:metrics_v3';

    public function get360Data(bool $bypassCache = false): array
    {
        // v2: نسخة v1 المخزَّنة على الخوادم تحمل مجموعاتٍ لا تُقرأ — مفتاحٌ جديد يتخطّاها بلا مسحٍ يدويّ
        $cacheKey = self::CACHE_KEY;

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
        // **الصادر بنطاق النموذج** (`Invoice::issued` — تعريف تقرير الإيرادات وشاشة المالية نفسه):
        // كان يجمع الملغاة فيكبر المقام وتظهر نسبة التحصيل أدنى من حقيقتها.
        $totalBilled = (int) Invoice::issued()->sum('amount');
        $totalCollected = (int) Invoice::issued()->where('paid', true)->sum('amount');
        // **الذمّة والتأخّر بنطاقَي النموذج** — كان الشرط `paid = 0` وحده، فتُعدّ الملغاة والمعدومة
        // ديناً ومتأخّرة، ويختلف الرقم عن شاشة المالية وعن شارة «متأخرة» على الفاتورة نفسها.
        $totalUnpaid = (int) Invoice::outstanding()->sum('amount');
        $overdueCount = Invoice::overdue()->count();
        /*
         * **المحصَّل في شهرٍ = ما سُدّد فيه** (`paid_at`) — من المصدر الواحد للتقارير الماليّة
         * (`RevenueSnapshot::collectedBetween`). كان يُقاس بـ`updated_at`: أيّ تعديلٍ لاحق على فاتورةٍ
         * مسدَّدة قديمة (ملاحظة، تصحيح رقم) ينقل مبلغها إلى «تحصيل هذا الشهر» ويُضخّم النموّ.
         */
        $currentMonthCollected = RevenueSnapshot::collectedBetween($startOfMonth, $now)['total'];
        $prevMonthCollected = RevenueSnapshot::collectedBetween($startOfPrevMonth, $endOfPrevMonth)['total'];

        $collectionRate = $totalBilled > 0 ? (int) round(($totalCollected / $totalBilled) * 100) : 100;
        // **لا نموَّ بلا أساس:** كان الشهر السابق الصفريّ يُعطي «+100%» ثابتةً أيّاً كان المحصّل —
        // رقمٌ مختلَق على أوّل بطاقة. `null` = لا مقارنة، والواجهة تُخفي الشارة.
        $revenueGrowth = $prevMonthCollected > 0
            ? round((($currentMonthCollected - $prevMonthCollected) / $prevMonthCollected) * 100, 1)
            : null;

        /*
         * 2. التذاكر — **بنطاقات النموذج لا بقائمة حالاتٍ مكتوبةٍ هنا.** كان «المفتوح» يستثني «مكتملة»
         * و«مغلقة» وحدهما، فتُعدّ المحوّلة إلى قضيّة أو تنفيذ مفتوحةً، ويعدّ «بانتظار التوزيع» المجمَّدةَ
         * التي لا تظهر في شاشة التوزيع ولا تقبل إسناداً. الآن: `open()` كصفحة التذاكر، و`distributable()`
         * كشاشة التوزيع — فالرقم على البطاقة هو ما يجده المدير حين ينقر.
         */
        $ticketStats = (object) [
            'open_tickets' => Ticket::open()->count(),
            'unassigned_tickets' => Ticket::distributable()->whereNull('assigned_lawyer_id')->count(),
        ];

        // 3. القضايا
        // «النشطة» بنطاق النموذج (`LegalCase::active`) — المصدر نفسه لعدّادات اللوحات الأخرى
        $totalCases = LegalCase::count();
        $activeCases = LegalCase::active()->count();
        $caseStats = (object) [
            'total_cases' => $totalCases,
            'active_cases' => $activeCases,
            'closed_cases' => $totalCases - $activeCases,
        ];

        // 4. التنفيذ
        // **الطلب المرفوض ليس ملفّاً نشطاً.** `ExecService::reject` يكتب القرار ولا ينقل المرحلة
        // عمداً (المرفوض ليس مغلقاً)، فكان يُعَدّ في «التنفيذات النشطة» وتُجمع قيمة مطالبته في
        // المبلغ النشط — رقمٌ ماليّ في لوحة الإدارة يضمّ طلباتٍ لن تُنفَّذ. و`decision` تقبل NULL،
        // فالمقارنة تُكتب NULL-safe وإلّا أسقطت كلّ طلبٍ لم يُبتّ فيه بعد.
        // والشرط مرّةً واحدة (كان مكرّراً حرفياً في العدّ والمجموع)، وحالتا الإنهاء من `Execution`.
        $closedExecs = "'".implode("','", Execution::CLOSED_STATUSES)."'";
        $activeExec = "(stage IS NULL OR stage < 9) AND status NOT IN ({$closedExecs}) AND (decision IS NULL OR decision <> '".ExecutionDecision::Rejected->value."')";

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

        // ما ينتظر الإدارة وحدها — من تعريف مركز الاعتمادات نفسه (`AdminApprovalQueue`)، فلا يقول
        // الرادار «لا توجد طلبات» ومقترحُ مسارٍ أو محضرُ جلسةٍ أو موعدٌ ينتظر في المركز
        $approvals = AdminApprovalQueue::counts();
        $pendingSummaryApprovals = $approvals['summaries'];

        // 6. رادار الإجراءات العاجلة
        $actionRadar = [];

        if (($ticketStats->unassigned_tickets ?? 0) > 0) {
            $actionRadar[] = [
                'id' => 'unassigned-tickets',
                'title' => 'تذاكر جديدة بانتظار التوزيع',
                'count' => (int) $ticketStats->unassigned_tickets,
                'desc' => 'يوجد '.ArabicCount::of((int) $ticketStats->unassigned_tickets, 'تذكرة واحدة', 'تذكرتان', 'تذاكر', 'تذكرة', 'تذكرة').' بحاجة لتعيين مستشار مختص',
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
                'desc' => 'يوجد '.ArabicCount::of((int) $consultStats->pending_price, 'طلب واحد', 'طلبان', 'طلبات', 'طلباً', 'طلب').' بحاجة لتحديد السعر وإصدار الفاتورة',
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
                'desc' => 'يوجد '.ArabicCount::of($pendingMeetingApprovals, 'محضر اجتماع واحد', 'محضرا اجتماع', 'محاضر اجتماعات', 'محضر اجتماع', 'محضر اجتماع').' بحاجة لاعتماد الإدارة',
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
                'desc' => 'يوجد '.ArabicCount::of($pendingSummaryApprovals, 'ملخص قانوني واحد', 'ملخصان قانونيان', 'ملخصات قانونية', 'ملخصاً قانونياً', 'ملخص قانوني').' جاهز للمراجعة',
                'cta' => 'مراجعة الملخصات',
                'link' => route('admin.approvals', ['tab' => 'summaries']),
                'tone' => 'cyan',
                'icon' => 'folder',
            ];
        }

        if ($approvals['proposals'] > 0) {
            $actionRadar[] = [
                'id' => 'pending-tracks',
                'title' => 'مقترحات مسار المآل بانتظار الاعتماد',
                'count' => $approvals['proposals'],
                'desc' => 'يوجد '.ArabicCount::of($approvals['proposals'], 'مقترح مسار واحد', 'مقترحا مسار', 'مقترحات مسار', 'مقترحاً', 'مقترح').' بحاجة لقرار الإدارة ونشره للعميل',
                'cta' => 'اعتماد المسارات',
                'link' => route('admin.approvals', ['tab' => 'tracks']),
                'tone' => 'amber',
                'icon' => 'scale',
            ];
        }

        if ($approvals['sessions'] > 0) {
            $actionRadar[] = [
                'id' => 'pending-sessions',
                'title' => 'محاضر جلسات استشارة بانتظار الاعتماد',
                'count' => $approvals['sessions'],
                'desc' => 'يوجد '.ArabicCount::of($approvals['sessions'], 'محضر جلسة واحد', 'محضرا جلسة', 'محاضر جلسات', 'محضر جلسة', 'محضر جلسة').' اعتمده المستشار وينتظر نشره للعميل',
                'cta' => 'مراجعة المحاضر',
                'link' => route('admin.approvals', ['tab' => 'sessions']),
                'tone' => 'cyan',
                'icon' => 'video',
            ];
        }

        if ($approvals['appointments'] > 0) {
            $actionRadar[] = [
                'id' => 'pending-appointments',
                'title' => 'مواعيد استشارة بانتظار الاعتماد',
                'count' => $approvals['appointments'],
                'desc' => 'يوجد '.ArabicCount::of($approvals['appointments'], 'موعد واحد', 'موعدان', 'مواعيد', 'موعداً', 'موعد').' حجزه موظّف وينتظر اعتمادك قبل إبلاغ العميل',
                'cta' => 'اعتماد المواعيد',
                'link' => route('admin.approvals', ['tab' => 'appointments']),
                'tone' => 'blue',
                'icon' => 'cal',
            ];
        }

        if ($overdueCount > 0) {
            $actionRadar[] = [
                'id' => 'overdue-invoices',
                'title' => 'فواتير متجاوزة الاستحقاق',
                'count' => $overdueCount,
                'desc' => 'يوجد '.ArabicCount::of($overdueCount, 'فاتورة واحدة', 'فاتورتان', 'فواتير', 'فاتورة', 'فاتورة').' متأخرة السداد',
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
                // رابط ملفّ القضيّة نفسه — كان الصفّ يفتح قائمة القضايا كلّها
                'caseUrl' => $h->legalCase ? route('admin.cases.show', $h->legalCase) : null,
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
        // **تعريف الحِمل الواحد** (`LawyerWorkload`، قرار المالك 2026-09-30): كانت اللوحة تحسبه بأوزانٍ وعتباتٍ أخرى
        // (3/1.5/2 و8/20، بلا ملفّات التنفيذ) وبثلاثة استعلاماتٍ لكلّ محامٍ، فيُعرض المحامي نفسه «متاحاً» هنا
        // و«متوسّطاً» في صفحة المحامين. الآن الأوزان والعتبات (من الإعدادات) واحدة في الشاشات الثلاث.
        $activeLawyers = User::where('role', Role::Lawyer)->where('status', 'active')->get();
        $load = LawyerWorkload::forMany($activeLawyers->pluck('id')->map(fn ($id) => (int) $id)->all());
        $lawyers = $activeLawyers->map(fn (User $lawyer) => [
            'id' => $lawyer->id,
            'name' => $lawyer->name,
            'jobTitle' => $lawyer->job_title ?: 'مستشار ومحامٍ',
            'department' => $lawyer->department ?: 'الاستشارات العامة',
            'initials' => $lawyer->avatar_initials ?: 'مح',
            'activeCases' => $load[$lawyer->id]['cases'],
            'activeTickets' => $load[$lawyer->id]['tickets'],
            'activeExecutions' => $load[$lawyer->id]['executions'],
            'openConsults' => $load[$lawyer->id]['consults'],
            'status' => $load[$lawyer->id]['capacity'],
        ]);

        // 9. مسار الإيرادات الشهري (6 أشهر)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = $now->copy()->subMonths($i)->startOfMonth();
            $mEnd = $now->copy()->subMonths($i)->endOfMonth();
            $monthName = $mStart->locale('ar')->translatedFormat('F');

            $mBilled = (int) Invoice::whereBetween('created_at', [$mStart, $mEnd])->sum('amount');
            // التحصيل بتاريخ السداد من المصدر الواحد — كما في بطاقة الشهر أعلاه
            $mCollected = RevenueSnapshot::collectedBetween($mStart, $mEnd)['total'];

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

        /*
         * **مصفوفاتٌ صافية لا `Collection`.** الناتج يُخزَّن في الذاكرة المؤقّتة (`get360Data`)، والإعداد
         * `cache.serializable_classes = false` يمنع إعادة بناء أيّ كائنٍ عند القراءة (حمايةٌ من حقن
         * الكائنات) — فكانت المجموعات الأربع تعود `__PHP_Incomplete_Class` وتصل اللوحة فارغة:
         * «لم يتم تسجيل محامين نشطين» والمحامي موجود، وتوزيعٌ بلا تخصّصات، ونبضٌ «الآن · عام».
         * يظهر الصحيح في الطلب الأوّل وحده (قبل التخزين) ثمّ يفرغ ثلاث دقائق. رُصد في المتصفّح 2026-09-26،
         * ويحرسه `AdminDashboardCacheTest`.
         */
        return [
            'overview' => [
                'clientsCount' => User::where('role', Role::Client)->count(),
                // النشط وحده — كان السطر تحت العدد الكلّيّ يقول «حسابات نشطة وموثقة» والعدد يضمّ الموقوفين
                'activeClients' => User::where('role', Role::Client)->where('status', '!=', 'suspended')->count(),
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
            'upcomingHearings' => $upcomingHearings->values()->all(),
            'lawyersWorkload' => $lawyers->values()->all(),
            'revenueTrajectory' => $monthlyRevenue,
            'practiceAreas' => $practiceAreas->all(),
            'liveActivity' => $activity->all(),
        ];
    }
}
