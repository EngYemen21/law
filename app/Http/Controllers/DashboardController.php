<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Document;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminDashboardService;
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

    // لوحة العميل — بوابة العميل والكونسيرج القانوني 360 درجة
    public function client(Request $request): Response
    {
        $user = $request->user();
        $uid = $user->id;

        // 1. التذاكر النشطة ورحلة آخر تذكرة
        $myTickets = Ticket::where('user_id', $uid)->with(['assignedLawyer'])->latest('id')->get();
        $activeTickets = $myTickets->filter(fn (Ticket $t) => ! in_array($t->status, self::CLOSED, true))->values();
        $lastTicket = $myTickets->first();

        // 2. المواعيد والاستشارات الحية
        $myAppts = Appointment::where('user_id', $uid)->with(['user', 'consult', 'lawyerUser'])->latest('id')->get();
        $upcomingAppts = $myAppts->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->values();

        // 3. الفواتير غير المسددة
        $unpaidInvoices = Invoice::where('user_id', $uid)->where('paid', false)
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->get();

        // 4. القضايا الجارية وجلسات المحاكم
        $myCases = LegalCase::where('user_id', $uid)->with(['hearings', 'assignedLawyer'])->latest('id')->get();
        $activeCases = $myCases->filter(fn (LegalCase $c) => ! in_array($c->status, ['مغلقة', 'مؤرشفة'], true))->values();

        // 5. ملفات التنفيذ القضائي
        $myExecutions = Execution::where('user_id', $uid)->latest('id')->get();
        $activeExecutions = $myExecutions->filter(fn (Execution $e) => $e->stage === null || $e->stage < 9)->values();

        // 6. الاجتماعات
        $myMeetings = Meeting::where('user_id', $uid)->get();
        $upcomingMeetings = $myMeetings->filter(fn (Meeting $m) => $m->isUpcoming())->values();

        // 7. المستندات والتقارير الصادرة
        $recentDocs = Document::where('user_id', $uid)->latest('id')->take(4)->get()
            ->map(fn (Document $d) => $d->toCard());

        // 8. المستشار القانوني المخصص (من القضايا أو التذاكر الحالية)
        $advisorUser = $activeCases->first()?->assignedLawyer
            ?? $activeTickets->first()?->assignedLawyer
            ?? User::where('role', Role::Lawyer)->where('status', 'active')->first();

        $assignedAdvisor = $advisorUser ? [
            'name' => $advisorUser->name,
            'title' => $advisorUser->title ?? 'المستشار القانوني',
            'jobTitle' => $advisorUser->job_title ?? 'مستشار ومحامٍ معتمد',
            'department' => $advisorUser->department ?? 'الاستشارات العامة',
            'initials' => $advisorUser->avatar_initials ?? 'مح',
        ] : null;

        // 9. مركز التنبيهات الذكي اللحظي (Smart Action Center)
        $actionAlerts = [];

        // أ) جلسة مرئية يمكن الانضمام لها أو موعد اليوم
        foreach ($upcomingAppts as $app) {
            $consult = $app->consult;
            if ($consult && $consult->canJoin()) {
                $actionAlerts[] = [
                    'id' => 'meet-'.$app->id,
                    'type' => 'video_ready',
                    'title' => 'جلستك المرئية جاهزة للانضمام الآن 🔴',
                    'desc' => "استشارة «{$consult->subject}» مع {$app->lawyer} ({$app->time})",
                    'cta' => 'دخول الجلسة الآن',
                    // الغرفة المضمّنة لا رابط Zoom الخام: الخام كان يقذف العميل خارج المنصّة (بلا noopener)
                    'link' => $consult->joinLink($user) ?: route('meetings'),
                    'tone' => 'b-red',
                ];
            } elseif ($app->when_kind === 'today' || ($app->starts_at && $app->starts_at->isToday())) {
                $actionAlerts[] = [
                    'id' => 'today-'.$app->id,
                    'type' => 'today_appt',
                    'title' => 'لديك موعد استشارة مجدول اليوم 📅',
                    'desc' => "{$app->type} مع {$app->lawyer} الساعة {$app->time} ({$app->place})",
                    'cta' => 'عرض التفاصيل',
                    'link' => route('appointments'),
                    'tone' => 'b-cyan',
                ];
            }
        }

        // ب) تذاكر بانتظار إرفاق مستندات من العميل
        foreach ($activeTickets->where('status', 'بانتظار مستندات') as $ticket) {
            $actionAlerts[] = [
                'id' => 'doc-'.$ticket->id,
                'type' => 'missing_doc',
                'title' => 'مطلوب إرفاق مستندات للتذكرة ⚠️',
                'desc' => "تذكرة {$ticket->number} — {$ticket->type} بانتظار تزويد الفريق بالوثائق المطلوبة",
                'cta' => 'إرفاق المستندات',
                'link' => "/tickets/{$ticket->number}",
                'tone' => 'b-amber',
            ];
        }

        // ج) فواتير متأخرة تجاوزت موعد الاستحقاق
        $overdueInvoices = $unpaidInvoices->filter(fn (Invoice $i) => $i->isOverdue());
        if ($overdueInvoices->isNotEmpty()) {
            $totalOverdue = $overdueInvoices->sum('amount');
            $actionAlerts[] = [
                'id' => 'inv-overdue',
                'type' => 'overdue_invoice',
                'title' => 'تنبيه: توجد فواتير متأخرة بانتظار السداد 💳',
                'desc' => 'إجمالي المبلغ المستحق: '.number_format($totalOverdue).' ريال سعودي',
                'cta' => 'سداد الفواتير',
                'link' => route('invoices'),
                'tone' => 'b-red',
            ];
        }

        return Inertia::render('dashboard', [
            'name' => $user->name,
            'counts' => [
                'openTickets' => $activeTickets->count(),
                'activeCases' => $activeCases->count(),
                'upAppts' => $upcomingAppts->count(),
                'upMeet' => $upcomingMeetings->count(),
                'dueInv' => $unpaidInvoices->count(),
                'overdueInv' => $overdueInvoices->count(),
                'myExec' => $activeExecutions->count(),
            ],
            'upcomingAppts' => $upcomingAppts->take(4)->map(fn (Appointment $a) => $a->toCard($user))->values(),
            'dueInvoices' => $unpaidInvoices->take(4)->map(fn (Invoice $i) => $i->toCard())->values(),
            'activeCases' => $activeCases->take(4)->map(fn (LegalCase $c) => array_merge($c->toCard(), [
                'department' => $c->department,
                'assignedLawyer' => $c->assigned_lawyer ?: ($c->assignedLawyer?->name ?? 'المستشار المكلف'),
                'nextHearingLabel' => $c->nextHearingLabel(),
            ]))->values(),
            'activeTickets' => $activeTickets->take(4)->map(fn (Ticket $t) => [
                'no' => $t->number,
                'type' => $t->type,
                'subject' => $t->subject,
                'status' => $t->status,
                'tone' => $t->tone,
                'priority' => $t->priority,
                'lawyer' => $t->assigned_lawyer ?: 'بانتظار الإسناد',
                'updatedAgo' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ])->values(),
            'activeExecutions' => $activeExecutions->take(3)->map(fn (Execution $e) => [
                'number' => $e->number,
                'subject' => $e->subject,
                'court' => $e->court,
                'stage' => $e->stage,
                'status' => $e->status,
                'tone' => $e->tone,
                'amount' => $e->amount,
                'lastAction' => $e->last_action,
            ])->values(),
            'lastTicket' => $lastTicket ? [
                'no' => $lastTicket->number,
                'step' => TicketJourney::indexOf($lastTicket->status),
                'status' => $lastTicket->status,
                'type' => $lastTicket->type,
            ] : null,
            'actionAlerts' => $actionAlerts,
            'assignedAdvisor' => $assignedAdvisor,
            'recentDocs' => $recentDocs,
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

    // لوحة الإدارة — نظرة شاملة 360 درجة حقيقية
    public function admin(Request $request, AdminDashboardService $adminDashboardService): Response
    {
        $bypassCache = $request->boolean('fresh');
        $data360 = $adminDashboardService->get360Data($bypassCache);

        // أحدث نشاط: آخر تذاكر/قضايا/استشارات
        $activity = collect()
            ->concat(Ticket::latest('id')->take(4)->get()->map(fn ($t) => ['ico' => 'folder', 'title' => 'تذكرة جديدة '.$t->number, 'sub' => $t->type]))
            ->concat(LegalCase::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'scale', 'title' => 'قضية '.$c->number, 'sub' => $c->status]))
            ->concat(Consult::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'video', 'title' => 'استشارة '.$c->ref, 'sub' => $c->channel]))
            ->take(8)->values();

        $stats = [
            'clients' => User::where('role', Role::Client)->count(),
            'openTickets' => Ticket::whereNotIn('status', self::CLOSED)->count(),
            'revenue' => (int) Invoice::where('paid', true)->sum('amount'),
            // الاعتماد لا يُطلب إلا بعد انعقاد الاجتماع — كان يعدّ القادمة أيضاً فيتضخّم الرقم
            'pendingMeetings' => Meeting::where('approve', '!=', 'معتمد')->where('status', 'منتهٍ')->count(),
        ];

        return Inertia::render('admin/dashboard', array_merge($data360, [
            'stats' => $stats,
            'activity' => $activity,
        ]));
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
        // كانت ثلاثة من سبعة: exec-docs و client-docs و invoice-proofs و recordings تبقى
        // على القرص بعد التصفير بلا صفوف تدلّ عليها. لاحظ الفخّ: ExecService يكتب في
        // `executions/{id}` بينما ExecFlowController يكتب في `exec-docs/{id}` — الاثنان لازمان.
        foreach ([
            'ticket-docs', 'case-docs', 'executions', 'exec-docs',
            'client-docs', 'invoice-proofs', 'recordings', 'transcripts',
        ] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }

        cache()->flush();

        return redirect()->route('admin.dashboard')->with('flash', 'تم تصفير جميع بيانات الاختبار بنجاح مع الاحتفاظ بالمستخدمين.');
    }
}
