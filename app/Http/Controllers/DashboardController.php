<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحات المعلومات — عدّادات حقيقية من قاعدة البيانات لكل دور (بدل الأرقام الثابتة).
 */
class DashboardController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    // لوحة العميل — عدّاداته ورحلة آخر تذكرة له
    public function client(Request $request): Response
    {
        $uid = $request->user()->id;
        $last = Ticket::where('user_id', $uid)->latest('id')->first();

        // القادمة تُشتق من الزمن الحقيقي (liveState) — when_kind المخزّنة لا تتحدّث بمرور الوقت
        $myAppts = Appointment::where('user_id', $uid)->with(['user', 'consult'])->latest('id')->get();
        $upcoming = $myAppts->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->values();

        // غير المدفوعة مرتّبة بالاستحقاق (الأقرب أولاً، بلا استحقاق آخراً) — كانت بالأحدث إنشاءً
        $unpaid = Invoice::where('user_id', $uid)->where('paid', false)
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->get();

        return Inertia::render('dashboard', [
            'name' => $request->user()->name,
            'counts' => [
                'openTickets' => Ticket::where('user_id', $uid)->whereNotIn('status', self::CLOSED)->count(),
                'upAppts' => $upcoming->count(),
                // الحالة المخزّنة لا تتحدّث بمرور الوقت — الاشتقاق الحي (liveState) هو الفيصل
                'upMeet' => Meeting::where('user_id', $uid)->get()
                    ->filter(fn (Meeting $m) => $m->isUpcoming())->count(),
                'dueInv' => $unpaid->count(),
                // المتأخرة وحدها (تجاوزت استحقاقها) — كانت مدموجة في «المستحقة» فلا يميّز العميل العاجل
                'overdueInv' => $unpaid->filter(fn (Invoice $i) => $i->isOverdue())->count(),
                'myExec' => Execution::where('user_id', $uid)
                    ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '<', 9))
                    ->count(),
            ],
            // أقرب 3 مواعيد قادمة و3 فواتير مستحقّة — لبطاقتَي «مواعيدك القادمة» و«فواتير بانتظار السداد»
            'upcomingAppts' => $upcoming->take(3)->map(fn (Appointment $a) => $a->toCard($request->user()))->values(),
            'dueInvoices' => $unpaid->take(3)->map(fn (Invoice $i) => $i->toCard())->values(),
            'lastTicket' => $last ? ['no' => $last->number, 'step' => TicketJourney::indexOf($last->status)] : null,
        ]);
    }

    // لوحة الموظف — غرفة عمليات 360 درجة (تذاكر + استشارات اليوم + جلسات المحاكم + التنفيذ + تفرغ الفريق)
    public function employee(Request $request): Response
    {
        $active = Ticket::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', self::CLOSED)->latest('id')->get();

        // 1. تذاكر تحتاج إجراء
        $tickets = $active->map(fn (Ticket $t) => $t->toEmployeeCard());

        // 2. مواعيد واستشارات اليوم
        $todayAppts = Appointment::with(['user', 'consult', 'lawyerUser'])
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', now()->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(function (Appointment $a) use ($request) {
                $card = $a->toCard($request->user());
                $card['channel'] = $a->consult?->channel ?? ($a->ico === 'video' ? 'مرئية' : ($a->ico === 'phone' ? 'هاتفية' : 'حضورية'));
                $card['rawStartsAt'] = $a->starts_at?->toIso8601String();

                return $card;
            });

        // 3. قضايا المكتب وجلسات المحاكم القادمة
        $casesWithHearings = LegalCase::with(['user', 'assignedLawyer', 'hearings'])
            ->whereNotIn('status', ['مغلقة', 'مؤرشفة', 'صدر الحكم'])
            ->latest('id')
            ->take(6)
            ->get()
            ->map(function (LegalCase $c) {
                $next = $c->nextHearingLive();

                return [
                    'no' => $c->number,
                    'type' => $c->type,
                    'client' => $c->user?->name ?? '—',
                    'lawyer' => $c->assignedLawyer?->name ?? $c->assigned_lawyer ?? '—',
                    'status' => $c->status,
                    'tone' => $c->tone,
                    'nextHearing' => $next ? $next->label() : ($c->nextHearingLabel()),
                    'hearingDate' => $next?->starts_at?->format('Y-m-d') ?? null,
                    'court' => $next?->court ?? 'المحكمة العامة',
                ];
            });

        // 4. ملفات التنفيذ النشطة
        $execs = Execution::with('user')
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '<', 9))
            ->latest('id')
            ->take(5)
            ->get()
            ->map(fn (Execution $e) => [
                'no' => $e->number,
                'client' => $e->user?->name ?? '—',
                'subject' => $e->subject,
                'court' => $e->court ?? 'محكمة التنفيذ',
                'status' => $e->status,
                'tone' => $e->tone,
                'amount' => (int) $e->amount,
                'stage' => $e->effectiveStage(),
            ]);

        // 5. تفرغ ومستشاري المكتب
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'department'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'dept' => $u->department ?: 'القسم القانوني',
                'activeTickets' => Ticket::where('assigned_lawyer_id', $u->id)->whereNotIn('status', self::CLOSED)->count(),
                'activeCases' => LegalCase::where('assigned_lawyer_id', $u->id)->whereNotIn('status', ['مغلقة', 'مؤرشفة'])->count(),
            ]);

        // 6. نشاط حديث
        $recentActivities = collect()
            ->concat(Ticket::latest('id')->take(3)->get()->map(fn ($t) => [
                'ico' => 'ticket',
                'tone' => 't-blue',
                'title' => 'تذكرة جديدة #'.$t->number,
                'sub' => $t->type.' · '.($t->user?->name ?? 'عميل'),
                'time' => $t->created_at?->diffForHumans() ?? 'الآن',
            ]))
            ->concat(Consult::latest('id')->take(3)->get()->map(fn ($c) => [
                'ico' => 'video',
                'tone' => 't-cyan',
                'title' => 'استشارة '.$c->ref,
                'sub' => $c->channel.' · '.($c->subject ?: 'استشارة قانونية'),
                'time' => $c->created_at?->diffForHumans() ?? 'الآن',
            ]))
            ->sortByDesc('time')->take(5)->values();

        return Inertia::render('employee/dashboard', [
            'name' => $request->user()->name,
            'tickets' => $tickets,
            'todayAppts' => $todayAppts,
            'cases' => $casesWithHearings,
            'execs' => $execs,
            'lawyers' => $lawyers,
            'recentActivities' => $recentActivities,
            'counts' => [
                // يستثني ما ينتظر طرفاً آخر (محامٍ/إدارة/عميل) — الموظف لا يتقدّم فيه بنفسه
                'needAction' => $active->whereNotIn('status', TicketJourney::AWAITING_OTHERS)->count(),
                'missingDocs' => $active->where('status', 'بانتظار مستندات')->count(),
                'todayAppts' => $todayAppts->count(),
                'referred' => Ticket::where('status', 'محالة للقسم القانوني')->count(),
                'activeCases' => LegalCase::whereNotIn('status', ['مغلقة', 'مؤرشفة', 'صدر الحكم'])->count(),
                'activeExecs' => $execs->count(),
            ],
        ]);
    }

    // لوحة الإدارة — نظرة شاملة حقيقية
    public function admin(): Response
    {
        // أحدث نشاط: آخر تذاكر/قضايا/استشارات
        $activity = collect()
            ->concat(Ticket::latest('id')->take(4)->get()->map(fn ($t) => ['ico' => 'folder', 'title' => 'تذكرة جديدة '.$t->number, 'sub' => $t->type]))
            ->concat(LegalCase::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'scale', 'title' => 'قضية '.$c->number, 'sub' => $c->status]))
            ->concat(Consult::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'video', 'title' => 'استشارة '.$c->ref, 'sub' => $c->channel]))
            ->take(8)->values();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'clients' => User::where('role', Role::Client)->count(),
                'openTickets' => Ticket::whereNotIn('status', self::CLOSED)->count(),
                'revenue' => (int) Invoice::where('paid', true)->sum('amount'),
                // الاعتماد لا يُطلب إلا بعد انعقاد الاجتماع — كان يعدّ القادمة أيضاً فيتضخّم الرقم
                'pendingMeetings' => Meeting::where('approve', '!=', 'معتمد')->where('status', 'منتهٍ')->count(),
            ],
            'activity' => $activity,
        ]);
    }

    /**
     * تصفير بيانات الاختبار — يحذف جميع سجلات الجداول التشغيلية مع الإبقاء على جدول المستخدمين والأدوار.
     *
     * إجراء لا رجعة فيه (truncate) على كل بيانات الموكلين. لذا ثلاث طبقات فوق فحص الدور:
     * (1) محظور في الإنتاج ما لم يُفتح صراحةً بـALLOW_DB_RESET — الدور وحده لا يكفي لأن
     *     أي حقن في جلسة مدير كان يكفي لاستدعائه، (2) عبارة تأكيد صريحة في الطلب،
     *     (3) سطر تدقيق يُبقي أثراً لمن نفّذه ومتى.
     */
    public function resetDatabase(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if(app()->isProduction() && ! config('app.allow_db_reset'), 403, 'تصفير قاعدة البيانات معطّل في بيئة الإنتاج.');
        $request->validate(['confirm' => ['required', 'string', 'in:RESET']]);

        Log::warning('Database reset executed', [
            'user_id' => $request->user()->id,
            'name' => $request->user()->name,
            'ip' => $request->ip(),
        ]);

        Schema::disableForeignKeyConstraints();

        $tablesToTruncate = [
            'appointments',
            'case_documents',
            'case_hearings',
            'case_messages',
            'cases',
            'consults',
            'correspondences',
            'documents',
            'execution_documents',
            'execution_messages',
            'execution_procedures',
            'executions',
            'invoices',
            'meet_requests',
            'meetings',
            'payments',
            'tasks',
            'ticket_documents',
            'ticket_messages',
            'ticket_summaries',
            'tickets',
            'user_notifications',
            'jobs',
            'failed_jobs',
            'job_batches',
        ];

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        // تنظيف الملفات المؤقتة للاختبار
        Storage::disk('local')->deleteDirectory('ticket-docs');
        Storage::disk('local')->deleteDirectory('case-docs');
        Storage::disk('local')->deleteDirectory('executions');

        cache()->flush();

        return redirect()->route('admin.dashboard')->with('flash', 'تم تصفير جميع بيانات الاختبار بنجاح مع الاحتفاظ بالمستخدمين.');
    }
}
