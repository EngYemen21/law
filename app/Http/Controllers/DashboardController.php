<?php

namespace App\Http\Controllers;

use App\Domain\Journey\Enums\TicketStatus;
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
use App\Support\AppEnvironment;
use App\Support\LawyerName;
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
    // لوحة العميل — بوابة العميل والكونسيرج القانوني 360 درجة
    public function client(Request $request): Response
    {
        $user = $request->user();
        $uid = $user->id;

        // 1. التذاكر النشطة ورحلة آخر تذكرة
        $myTickets = Ticket::where('user_id', $uid)->with(['assignedLawyer'])->latest('id')->get();
        $activeTickets = $myTickets->filter(fn (Ticket $t) => $t->isOpen())->values();
        $lastTicket = $myTickets->first();

        // 2. المواعيد والاستشارات الحية
        // الموعد «بانتظار الاعتماد» شأنٌ داخليّ للمكتب — لا يُعرض للعميل قبل نشره
        $myAppts = Appointment::where('user_id', $uid)->where('status', '!=', 'بانتظار الاعتماد')->with(['user', 'consult', 'lawyerUser'])->latest('id')->get();
        $upcomingAppts = $myAppts->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->values();

        // 3. الفواتير غير المسددة
        $unpaidInvoices = Invoice::where('user_id', $uid)->owedByClient()
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->get();

        // 4. القضايا الجارية وجلسات المحاكم
        $myCases = LegalCase::where('user_id', $uid)->with(['hearings', 'assignedLawyer'])->latest('id')->get();
        $activeCases = $myCases->filter(fn (LegalCase $c) => $c->isActive())->values();

        // 5. ملفات التنفيذ القضائي
        $myExecutions = Execution::where('user_id', $uid)->latest('id')->get();
        // المغلق القديم (stage=null وحالته «مغلق»/«مكتمل») كان يُعدّ نشطاً — المصدر الواحد يفصل
        $activeExecutions = $myExecutions->filter(fn (Execution $e) => ! $e->isClosed())->values();

        // 6. الاجتماعات
        $myMeetings = Meeting::where('user_id', $uid)->get();
        $upcomingMeetings = $myMeetings->filter(fn (Meeting $m) => $m->isUpcoming())->values();

        // 7. المستندات والتقارير الصادرة
        $recentDocs = Document::where('user_id', $uid)->latest('id')->take(4)->get()
            ->map(fn (Document $d) => $d->toCard());

        // 8. المستشار القانوني المخصص (من القضايا أو التذاكر الحالية)
        //
        // **لا مستشارَ مخصّصاً لعميلٍ لم يُسنَد له أحد.** كان الاحتياط «أوّل محامٍ نشط في المكتب»،
        // فتعرض البطاقة («المستشار المخصص» بشارة «معتمد») محامياً لا صلة له بالعميل: ادّعاءُ إسنادٍ
        // لم يقع، وكشفُ اسم محامٍ ومسمّاه وقسمه لعميلٍ لا يخصّه. والبطاقة تختفي بلا مستشار (`dashboard.tsx`).
        $advisorUser = $activeCases->first()?->assignedLawyer
            ?? $activeTickets->first()?->assignedLawyer;

        $advisorName = $advisorUser ? LawyerName::forClient($advisorUser, $advisorUser->name, 'المستشار المكلف') : null;

        $assignedAdvisor = $advisorUser ? [
            // **مقنَّعٌ كبقيّة ما يصل العميل** (قرار المالك 2026-09-11): كان يُرسَل خاماً فتعرض
            // بطاقة «المستشار المخصص» الاسم كاملاً، بينما سطرا القضايا والتذاكر أدناه يقنّعان.
            // سهوُ بطاقةٍ واحدة لا قرارُ استثناء.
            'name' => $advisorName,
            'title' => $advisorUser->title ?? 'المستشار القانوني',
            'jobTitle' => $advisorUser->job_title ?? 'مستشار ومحامٍ معتمد',
            'department' => $advisorUser->department ?? 'الاستشارات العامة',
            // الأحرف من الاسم المعروض لا من `avatar_initials`: حقلٌ حرّ لا يمرّ بقاعدة الاختصار
            'initials' => self::initials((string) $advisorName),
        ] : null;

        // 9. مركز التنبيهات الذكي اللحظي (Smart Action Center)
        $actionAlerts = [];

        // أ) جلسة مرئية يمكن الانضمام لها أو موعد اليوم
        foreach ($upcomingAppts as $app) {
            $consult = $app->consult;
            // المحامي باسمه للعميل (`Appointment::lawyerForClient`) — العمود النصّيّ اسمٌ كامل
            $apptLawyer = $app->lawyerForClient(LawyerName::SPECIALIST);
            // **البنر لا يقوم إلا على موعدٍ محلَّل.** `canJoin` حارسٌ متساهل عمداً: جلسةٌ بلا
            // موعد تُبدأ يدوياً وتبقى قابلة للدخول (‏`Consult::isStartable`) — وهو صواب
            // للحارس. أمّا **الإعلان** «جاهزة للانضمام الآن» فيَعِد بحاضرٍ لا يعرفه: بلا
            // `starts_at` لا سقف زمنيّ يُنهيه، فيبقى أحمر عاجلاً إلى الأبد.
            if ($consult && $consult->starts_at !== null && $consult->canJoin()) {
                $actionAlerts[] = [
                    'id' => 'meet-'.$app->id,
                    'type' => 'video_ready',
                    'title' => 'جلستك المرئية جاهزة للانضمام الآن 🔴',
                    'desc' => "استشارة «{$consult->subject}» مع {$apptLawyer} ({$app->time})",
                    'cta' => 'دخول الجلسة الآن',
                    // الغرفة المضمّنة لا رابط Zoom الخام: الخام كان يقذف العميل خارج المنصّة (بلا noopener)
                    'link' => $consult->joinLink($user) ?: route('meetings'),
                    'tone' => 'b-red',
                ];
                // و«اليوم» تُقاس بالساعة لا بعمودٍ مخزَّن: `when_kind` لا يكتبه مسارٌ حيّ بقيمة
                // `'today'` أصلاً (البذر وحده)، وهو لا يتحدّث بمرور الوقت — فموعدٌ وُسم «اليوم»
                // مرّةً كان يُعلَن «اليوم» كلّ يوم. والموعد بلا `starts_at` ليس مجدولاً فلا يُعلَن.
            } elseif ($app->starts_at && $app->starts_at->isToday()) {
                $actionAlerts[] = [
                    'id' => 'today-'.$app->id,
                    'type' => 'today_appt',
                    'title' => 'لديك موعد استشارة مجدول اليوم 📅',
                    'desc' => "{$app->type} مع {$apptLawyer} الساعة {$app->time} ({$app->place})",
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

        // ج) تذاكر بانتظار حجز موعد الاستشارة من العميل
        foreach ($activeTickets->where('status', 'بانتظار حجز الاستشارة') as $ticket) {
            $actionAlerts[] = [
                'id' => 'book-'.$ticket->id,
                'type' => 'needs_booking',
                'title' => 'بانتظار حجز موعد الاستشارة 📅',
                'desc' => "تذكرة {$ticket->number} — تمت الدراسة المبدئية، يرجى حجز موعد الاستشارة لمناقشة الرأي القانوني",
                'cta' => 'حجز الموعد الآن',
                'link' => '/book',
                'tone' => 'b-blue',
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
                // عدّاد «مواعيد واجتماعات قادمة» — كان المواعيد وحدها، فيقول 0 واجتماعُ العميل جارٍ الآن
                // (`Meeting::isUpcoming` يشمل الجاري)
                'upcoming' => $upcomingAppts->count() + $upcomingMeetings->count(),
                'dueInv' => $unpaidInvoices->count(),
                'overdueInv' => $overdueInvoices->count(),
                'myExec' => $activeExecutions->count(),
            ],
            'upcomingAppts' => $upcomingAppts->take(4)->map(fn (Appointment $a) => $a->toCard($user))->values(),
            'dueInvoices' => $unpaidInvoices->take(4)->map(fn (Invoice $i) => $i->toCard())->values(),
            'activeCases' => $activeCases->take(4)->map(fn (LegalCase $c) => array_merge($c->toCard(), [
                'department' => $c->department,
                'assignedLawyer' => LawyerName::forClient($c->assigned_lawyer_id ? $c->assignedLawyer : null, $c->assigned_lawyer, 'المستشار المكلف'),
                'nextHearingLabel' => $c->nextHearingLabel(),
            ]))->values(),
            'activeTickets' => $activeTickets->take(4)->map(fn (Ticket $t) => [
                'no' => $t->number,
                'type' => $t->type,
                'subject' => $t->subject,
                // لوحة العميل: تسميته لا حالة الاعتماد الداخليّة
                'status' => TicketStatus::labelForClient($t->status),
                'tone' => $t->tone,
                'priority' => $t->priority,
                'lawyer' => LawyerName::forClient($t->assigned_lawyer_id ? $t->assignedLawyer : null, $t->assigned_lawyer, 'بانتظار الإسناد'),
                'updatedAgo' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ])->values(),
            'activeExecutions' => $activeExecutions->take(3)->map(fn (Execution $e) => [
                'number' => $e->number,
                'subject' => $e->subject,
                'court' => $e->court,
                // المرحلة الفعّالة كبقيّة الشاشات — كان العميل يرى `null` للملفّات المفتوحة من قضية
                'stage' => $e->effectiveStage(),
                'stageLabel' => $e->stageLabel(),
                'status' => $e->status,
                'tone' => $e->tone,
                'amount' => $e->amount,
                'lastAction' => $e->last_action,
            ])->values(),
            'lastTicket' => $lastTicket ? [
                'no' => $lastTicket->number,
                'step' => TicketJourney::indexOf($lastTicket->status),
                'status' => TicketStatus::labelForClient($lastTicket->status),
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
            ->open()->latest('id')->get();

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
            ->active()
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

        // 4. ملفات التنفيذ النشطة — استعلامٌ واحد يغذّي القائمة والعدّاد فلا يتباعدان
        $activeExecs = Execution::query()
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '<', 9))
            ->whereNotIn('status', Execution::CLOSED_STATUSES); // المنتهي القديم ليس نشطاً

        // العدّاد يقرأ القاعدة لا الصفحة: كان `$execs->count()` على مجموعةٍ مقصوصة بـtake(5)،
        // فيُعرض «٥ ملفّات نشطة» للمكتب وفيه عشرون — سقفٌ يُقرأ حقيقة.
        $activeExecsCount = (clone $activeExecs)->count();

        $execs = $activeExecs->with('user')
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
                'stageLabel' => $e->stageLabel(),
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
                'activeTickets' => Ticket::where('assigned_lawyer_id', $u->id)->open()->count(),
                'activeCases' => LegalCase::where('assigned_lawyer_id', $u->id)->active()->count(),
            ]);

        // 6. نشاط حديث
        $recentActivities = collect()
            ->concat(Ticket::latest('id')->take(3)->get()->map(fn ($t) => [
                'ico' => 'ticket',
                'tone' => 't-blue',
                'title' => 'تذكرة جديدة #'.$t->number,
                'sub' => $t->type.' · '.($t->user?->name ?? 'عميل'),
                'time' => $t->created_at?->diffForHumans() ?? 'الآن',
                'at' => $t->created_at?->getTimestamp() ?? 0,
            ]))
            ->concat(Consult::latest('id')->take(3)->get()->map(fn ($c) => [
                'ico' => 'video',
                'tone' => 't-cyan',
                'title' => 'استشارة '.$c->ref,
                'sub' => $c->channel.' · '.($c->subject ?: 'استشارة قانونية'),
                'time' => $c->created_at?->diffForHumans() ?? 'الآن',
                'at' => $c->created_at?->getTimestamp() ?? 0,
            ]))
            // **الفرز بالزمن لا بنصّه.** كان `sortByDesc('time')` و`time` نصٌّ عربيّ من
            // `diffForHumans()` («منذ ٣ دقائق») — فرزٌ أبجديّ، ثمّ `take(5)` يقتطع عليه
            // فيسقط أحدثُ عنصرٍ أحياناً.
            ->sortByDesc('at')->take(5)->values();

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
                'activeCases' => LegalCase::active()->count(),
                'activeExecs' => $activeExecsCount,
            ],
        ]);
    }

    // لوحة الإدارة — نظرة شاملة 360 درجة حقيقية
    public function admin(Request $request, AdminDashboardService $adminDashboardService): Response
    {
        $bypassCache = $request->boolean('fresh');
        $data360 = $adminDashboardService->get360Data($bypassCache);

        /*
         * كانت تُرسل هنا حزمتان قديمتان (`stats` و`activity`) تحسبان من جديد ما في `$data360` —
         * العملاء والتذاكر المفتوحة (بقائمة حالاتٍ مكتوبة) والإيراد والنشاط — والصفحة لا تقرؤهما
         * إلّا احتياطاً لا يقع. خمسة استعلامات في كلّ تحميل لأرقامٍ مكرّرة قد تختلف عن المعروض.
         */
        return Inertia::render('admin/dashboard', array_merge($data360, [
            // أداة التصفير تظهر حيث يقبلها الخادم وحده (`resetDatabase`) — لا زرٌّ يردّه 403 في الإنتاج
            'canReset' => self::resetAllowed(),
        ]));
    }

    /**
     * هل يُسمح بتصفير بيانات الاختبار هنا؟ شرطٌ واحد للزرّ وللمسار — في صندوق التجربة (`AppEnvironment`)، أو
     * بإذنٍ صريح `ALLOW_DB_RESET` (يكشفه `env:check` في الإنتاج). كان `! isProduction()` فيفتحه أيّ اسم بيئةٍ آخر.
     */
    private static function resetAllowed(): bool
    {
        return AppEnvironment::isSandbox() || (bool) config('app.allow_db_reset');
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
        abort_unless(self::resetAllowed(), 403, 'تصفير قاعدة البيانات معطّل في بيئة الإنتاج.');
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

    /** حرفا الصورة الرمزيّة من الاسم **المعروض** («محمد. ب» ⇐ «مب») — فلا تحمل أكثر ممّا يحمله الاسم. */
    private static function initials(string $shown): string
    {
        $words = preg_split('/[\s.]+/u', trim($shown), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)));
    }
}
