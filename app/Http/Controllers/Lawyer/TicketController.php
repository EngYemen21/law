<?php

namespace App\Http\Controllers\Lawyer;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transitions\Ticket\AwaitAdminResultApproval;
use App\Domain\Journey\Transitions\Ticket\AwaitAdminSummaryApproval;
use App\Domain\Journey\Transitions\Ticket\AwaitTicketDocuments;
use App\Domain\Journey\Transitions\Ticket\CloseTicketJustified;
use App\Domain\Journey\Transitions\Ticket\FinalApproveTicketSummary;
use App\Domain\Journey\Transitions\Ticket\LawyerApproveTicketResult;
use App\Domain\Journey\Transitions\Ticket\LawyerApproveTicketSummary;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\PublishLegalOpinion;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateTicketSummaryJob;
use App\Mail\SummaryApprovedMail;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Correspondence;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiReviewOutcome;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\CaseConversion;
use App\Support\ConversationFiles;
use App\Support\Live;
use App\Support\Notify;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use App\Support\ServiceDocs;
use App\Support\SummaryReport;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي (المستشار) — التذاكر المحالة وملخصاتها ومساحة العمل 360°.
 * يراجع المحامي ملخص الملف الذي جهّزه الفريق القانوني (الذكاء الاصطناعي) ويعتمده،
 * فيصل اعتماده والرأي القانوني مباشرةً إلى محادثة العميل مع الموظف + إشعار للعميل.
 */
class TicketController extends Controller
{
    use ScopedToLawyer;

    // التذاكر المحالة للمستشار (المسندة إليه) — بيانات شاملة 360° مع ملخصات الذكاء الاصطناعي والعدادات
    public function index(Request $request): Response
    {
        $lawyerId = $request->user()->id;
        $ticketsQuery = Ticket::with(['user', 'summary', 'legalCase'])
            ->where('assigned_lawyer_id', $lawyerId)
            ->latest('id')
            ->get();

        $tickets = $ticketsQuery->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), [
            'hasSummary' => $t->summary !== null,
            'summaryStatus' => $t->summary?->status,
            'summaryRecommendation' => $t->summary?->recommendation,
            'summaryFacts' => $t->summary?->facts,
            'converted' => (bool) $t->legalCase,
            'caseRef' => $t->legalCase?->number,
            'awaitingSummary' => $t->summary === null || $t->summary->status === 'awaiting_lawyer',
            'priority' => $t->priority,
            'subject' => $t->subject,
            'updatedAgo' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            'createdAgo' => $t->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
        ]))->values();

        $departments = $ticketsQuery->pluck('department')->filter()->unique()->values()->all();

        $counts = [
            'total' => $tickets->count(),
            'needStudy' => $tickets->filter(fn ($t) => ! in_array($t['status'], ['مكتملة', 'مغلقة', 'محولة لقضية']))->count(),
            'awaitingSummary' => $tickets->filter(fn ($t) => ($t['summaryStatus'] ?? '') === 'awaiting_lawyer')->count(),
            'urgent' => $tickets->filter(fn ($t) => in_array($t['priority'] ?? '', ['عاجلة', 'طارئة', 'عاجل جداً', 'عالية']))->count(),
            'missingDocs' => $tickets->filter(fn ($t) => $t['status'] === 'بانتظار مستندات')->count(),
            'converted' => $tickets->filter(fn ($t) => $t['converted'])->count(),
            'completed' => $tickets->filter(fn ($t) => in_array($t['status'], ['مكتملة', 'مغلقة']))->count(),
        ];

        return Inertia::render('lawyer/tickets', [
            'tickets' => $tickets,
            'counts' => $counts,
            'departments' => $departments,
        ]);
    }

    // لوحة المحامي 360° — مؤشرات ورادار وإحاطة تشغيلية وقضائية شاملة
    public function dashboard(Request $request): Response
    {
        $lawyer = $request->user();
        $lawyerId = $lawyer->id;

        // 1. التذاكر المحالة لهذا المحامي
        $ticketsQuery = Ticket::with(['user', 'summary', 'legalCase'])
            ->where('assigned_lawyer_id', $lawyerId)
            ->latest('id')
            ->get();

        $tickets = $ticketsQuery->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), [
            'hasSummary' => $t->summary !== null,
            'summaryStatus' => $t->summary?->status,
            'summaryRecommendation' => $t->summary?->recommendation,
            'converted' => (bool) $t->legalCase,
            'caseRef' => $t->legalCase?->number,
            'priority' => $t->priority,
            'updatedAgo' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
        ]))->values();

        // 2. قضايا المحامي النشطة
        $casesQuery = LegalCase::with(['user', 'hearings'])
            ->where('assigned_lawyer_id', $lawyerId)
            ->latest('id')
            ->get();

        $cases = $casesQuery->map(function (LegalCase $c) {
            $nextHearing = $c->nextHearingLive();

            return [
                'id' => $c->id,
                'no' => $c->number,
                'client' => $c->user?->name ?? '—',
                'type' => $c->type,
                'dept' => $c->department,
                'status' => $c->status,
                'tone' => $c->tone,
                'pleadingStatus' => $c->pleading_status,
                'ruling' => $c->ruling,
                'nextHearing' => $c->nextHearingLabel(),
                'nextHearingDate' => $nextHearing?->starts_at?->format('Y-m-d'),
                'nextHearingTime' => $nextHearing?->starts_at?->locale('ar')->translatedFormat('h:i A'),
                'court' => $nextHearing?->court ?? 'المحكمة المختصة',
                'updatedAgo' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ];
        })->values();

        // 3. جلسات المحاكم القادمة للمحامي
        $caseIds = $casesQuery->pluck('id');
        $upcomingHearings = CaseHearing::with('legalCase.user')
            ->whereIn('case_id', $caseIds)
            ->where('status', 'مجدولة')
            // **`now()` لا `startOfDay()`.** الحدّ القديم يُبقي جلسةً انتهت التاسعة صباحاً
            // «قادمةً» حتى منتصف الليل، فيُعلَن عليها بنرٌ أحمر «جلسة قضائية اليوم 🔴»
            // مساءً وتُعدّ في مؤشّر «جلسات محاكم قادمة». ونظيرُها في لوحة الإدارة
            // (`AdminDashboardService`) يقيس بـ`now()` منذ البداية — فاتّسقت اللوحتان.
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->take(8)
            ->get()
            ->map(fn (CaseHearing $h) => [
                'id' => $h->id,
                'caseNo' => $h->legalCase?->number ?? '—',
                'caseType' => $h->legalCase?->type ?? 'قضية',
                'client' => $h->legalCase?->user?->name ?? '—',
                'court' => $h->court ?? 'المحكمة العامة',
                'label' => $h->label(),
                'startsAt' => $h->starts_at?->toIso8601String(),
                'formattedDate' => $h->starts_at?->locale('ar')->translatedFormat('l d F Y') ?? 'قريباً',
                'formattedTime' => $h->starts_at?->locale('ar')->translatedFormat('h:i A') ?? '—',
                'isToday' => $h->starts_at?->isToday() ?? false,
                'isTomorrow' => $h->starts_at?->isTomorrow() ?? false,
            ])->values();

        // 4. استشارات واجتماعات اليوم / القادمة للمحامي
        $consultsQuery = Consult::with(['user', 'appointment', 'ticket:id,number', 'ticket.legalCase:id,ticket_id,number'])
            // العزل بالإسناد وحده — مطابقة الاسم النصّيّ كانت تُدخل استشارة زميلٍ يشاركه الاسم
            ->where('assigned_lawyer_id', $lawyerId)
            ->whereNotIn('status', Consult::PRE_SESSION_STATUSES)
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->take(8)
            ->get();

        $todayConsults = $consultsQuery->map(fn (Consult $c) => array_merge($c->toCard(), [
            'joinLink' => $c->joinLink($lawyer),
            'isToday' => $c->starts_at?->isToday() ?? false,
        ]))->values();

        $openMeetings = Meeting::where('assigned_lawyer_id', $lawyerId)
            ->get()->filter(fn (Meeting $m) => $m->isUpcoming());

        // 5. ملفات التنفيذ القضائي
        // **ما يحرسه المسار تحرسه الحمولة.** `/lawyer/execs` يشترط «إدارة القضايا والأتعاب»،
        // ولوحةُ المحامي بلا وسيط صلاحيّة (عامّة للدور) كانت تشحن صفوف التنفيذ — أسماء موكّلين
        // ومواضيع ملفّاتهم — لكلّ محامٍ ولو نُزعت عنه الصلاحيّة. الحجب في الخادم لا في الشاشة.
        $canExecs = $lawyer->can('إدارة القضايا والأتعاب');

        $executions = ! $canExecs ? collect() : Execution::with('user')
            ->where('assigned_lawyer_id', $lawyerId)
            // قاعدة الالتقاط نفسها التي في تبويب التنفيذ (ExecFlowController::lawyer): غير مسنَد وبلغ
            // الدراسة. والحالات الثلاث السابقة («جارٍ»/«مفتوح»/«بانتظار الإجراء») لا يكتبها أيّ مسار.
            ->orWhere(fn ($q) => $q->whereNull('assigned_lawyer_id')->whereNotNull('stage')->where('stage', '>=', 2))
            ->latest('id')
            ->take(6)
            ->get()
            ->map(fn (Execution $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'client' => $e->user?->name ?? '—',
                'subject' => $e->subject,
                'court' => $e->court ?? 'محكمة التنفيذ',
                'stage' => $e->effectiveStage(),
                'status' => $e->status,
                'tone' => $e->tone,
                'amount' => (int) $e->amount,
                'lastAction' => $e->last_action,
            ])->values();

        // 6. المهام القانونية
        $tasks = Task::where('assigned_to', $lawyerId)
            ->orderBy('status')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->take(10)
            ->get()
            ->map(fn (Task $t) => $t->toData())
            ->values();

        // 7. المخاطبات الرسمية
        $correspondences = Correspondence::with('user')
            ->where('assigned_lawyer_id', $lawyerId)
            ->latest('id')
            ->take(6)
            ->get()
            ->map(fn (Correspondence $c) => [
                'id' => $c->id,
                'refNo' => $c->ref_no,
                'subject' => $c->subject,
                'client' => $c->user?->name ?? '—',
                'type' => $c->type,
                'status' => $c->status,
                'tone' => $c->tone,
                'updatedAgo' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ])->values();

        // 8. مركز التنبيهات ورادار الإجراءات الذكي (Smart Lawyer Action Radar)
        $actionAlerts = [];

        // أ) جلسة محكمة اليوم أو غداً
        $immediateHearings = $upcomingHearings->filter(fn ($h) => $h['isToday'] || $h['isTomorrow']);
        foreach ($immediateHearings as $ih) {
            $actionAlerts[] = [
                'id' => 'hearing-'.$ih['id'],
                'type' => 'hearing',
                'title' => ($ih['isToday'] ? 'جلسة قضائية اليوم 🔴' : 'جلسة قضائية غداً ⚖️').': قضية '.$ih['caseNo'],
                'desc' => "جلسة في {$ih['court']} للموكل {$ih['client']} ({$ih['formattedTime']})",
                'cta' => 'فتح ملف القضية',
                'link' => "/lawyer/cases/{$ih['caseNo']}",
                'tone' => 'b-red',
            ];
        }

        // ب) استشارة مرئية يمكن الانضمام لها الآن
        foreach ($consultsQuery as $con) {
            if ($con->canJoin()) {
                $actionAlerts[] = [
                    'id' => 'meet-'.$con->id,
                    'type' => 'video_ready',
                    'title' => 'جلستك الاستشارية جاهزة للانضمام الآن 🟢',
                    'desc' => "استشارة «{$con->subject}» مع {$con->user?->name} ({$con->whenLabel()})",
                    'cta' => 'دخول الجلسة الآن',
                    'link' => $con->joinLink($lawyer),
                    'tone' => 'b-cyan',
                ];
            }
        }

        // ج) ملخصات ذكاء اصطناعي بانتظار الاعتماد
        $pendingSummariesCount = $ticketsQuery->where('summary.status', 'awaiting_lawyer')->count();
        if ($pendingSummariesCount > 0) {
            $actionAlerts[] = [
                'id' => 'summaries-pending',
                'type' => 'summaries',
                'title' => "لديك {$pendingSummariesCount} ملخصات دراسة بانتظار اعتماد رأيك القانوني 🟡",
                'desc' => 'أعدّ الذكاء الاصطناعي ملخصات التذاكر المحالة، بانتظار مراجعتك واعتماد التوصية.',
                'cta' => 'مراجعة الملخصات',
                'link' => '/lawyer/summaries',
                'tone' => 'b-amber',
            ];
        }

        // د) مهام متأخرة تجاوزت موعد الاستحقاق
        $overdueTasksCount = Task::where('assigned_to', $lawyerId)->where('status', '!=', 'منجزة')
            ->get()->filter(fn (Task $t) => $t->isOverdue())->count();
        if ($overdueTasksCount > 0) {
            $actionAlerts[] = [
                'id' => 'tasks-overdue',
                'type' => 'tasks',
                'title' => "تنبيه: توجد {$overdueTasksCount} مهام قانونية متأخرة ⚠️",
                'desc' => 'تجاوزت بعض المذكرات والمهام الموكلة إليك موعد الاستحقاق النهائي.',
                'cta' => 'عرض المهام',
                'link' => '/lawyer/tasks',
                'tone' => 'b-red',
            ];
        }

        // 9. العدادات الشاملة (360° Stats)
        $stats = [
            'assignedTickets' => $tickets->count(),
            'activeCases' => $cases->count(),
            'upcomingHearings' => $upcomingHearings->count(),
            'pendingSummaries' => $pendingSummariesCount,
            'openMeetings' => $openMeetings->count(),
            'todayConsults' => $todayConsults->count(),
            'openTasks' => Task::where('assigned_to', $lawyerId)->where('status', '!=', 'منجزة')->count(),
            'overdueTasks' => $overdueTasksCount,
            'activeExecutions' => $executions->count(),
            'activeCorrespondences' => $correspondences->count(),
        ];

        return Inertia::render('lawyer/dashboard', [
            'lawyerName' => $lawyer->name,
            'lawyerTitle' => $lawyer->title ?? 'المستشار القانوني',
            'lawyerDept' => $lawyer->department ?? 'القسم القانوني',
            'stats' => $stats,
            'tickets' => $tickets,
            'cases' => $cases,
            'upcomingHearings' => $upcomingHearings,
            'todayConsults' => $todayConsults,
            'executions' => $executions,
            'tasks' => $tasks,
            'correspondences' => $correspondences,
            'actionAlerts' => $actionAlerts,
            // للتوافق مع أي شفرة قديمة تعتمد على أسماء props السابقة
            'pendingSummaries' => $pendingSummariesCount,
            'openMeetings' => $openMeetings->count(),
            'openTasks' => $stats['openTasks'],
            'overdueTasks' => $overdueTasksCount,
        ]);
    }

    // محادثة التذكرة (قراءة سياق + الملخص) للمستشار
    public function show(Request $request, Ticket $ticket): Response
    {
        $this->guardAssigned($ticket);
        $ticket->load(['user', 'summary', 'legalCase']);

        return Inertia::render('lawyer/ticketchat', [
            // رقم القضية الحقيقي — كانت الواجهة تطبع نصّاً ثابتاً مكان الرقم
            // mobile/openedAt لبطاقة «تفاصيل الطلب» (يطابق tkDetailsCard المرجعي)
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => ConversationFiles::linkLegacyChips($ticket->messages->map->toMessage()->all(), 'ticket', $ticket->documents),
            'summary' => $ticket->summary?->toData(),
            'converted' => (bool) $ticket->legalCase,
            // الإدارة تفتح نفس الصفحة من مسارها — الروابط تُبنى من base لا مثبّتة على /lawyer
            'base' => $request->user()->isAdmin() ? '/admin' : '/lawyer',
        ]);
    }

    // ردّ المستشار المباشر للعميل — بثّ لحظي معزول بالمعاملة وإشعار للعميل
    public function reply(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardAssigned($ticket);
        $data = $request->validate(['body' => ['required', 'string']]);

        $roleName = $request->user()->isAdmin() ? 'الإدارة' : 'المستشار القانوني';
        $msg = null;
        DB::transaction(function () use ($ticket, $request, $data, $roleName, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'lawyer',
                'name' => $request->user()->name,
                'role' => $roleName,
                'body' => nl2br(e($data['body'])),
                'time_label' => $this->clock(),
            ]);

            $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);
        });

        if ($msg) {
            Audit::log(
                action: 'رد على تذكرة',
                description: "ردّ {$request->user()->name} ({$roleName}) على التذكرة {$ticket->number}.",
                category: 'تذاكر',
                auditable: $ticket,
                auditableRef: $ticket->number,
            );

            DB::afterCommit(function () use ($msg, $ticket) {
                Live::push(new TicketMessageBroadcast($msg));
                // بلا اسم المستشار — العميل يرى أطراف المكتب باسم موحّد (سياسة الحجب، ويطابق clientNotify المرجعي)
                Notify::send($ticket->user_id, 'ticket', 't-blue', "رد جديد من المستشار القانوني على تذكرتك {$ticket->number}.");
            });
        }

        return response()->noContent();
    }

    // ملاحظة داخلية للمستشار (لا يراها العميل) — تُبثّ على قناة الطاقم فقط
    public function note(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardAssigned($ticket);
        $data = $request->validate(['body' => ['required', 'string']]);

        $roleName = $request->user()->isAdmin() ? 'ملاحظة إدارة' : 'ملاحظة مستشار';
        $msg = null;
        DB::transaction(function () use ($ticket, $request, $data, $roleName, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'note',
                'name' => $request->user()->name,
                'role' => $roleName,
                'body' => nl2br(e($data['body'])),
                'time_label' => $this->clock(),
            ]);
        });

        if ($msg) {
            DB::afterCommit(function () use ($msg) {
                Live::push(new TicketMessageBroadcast($msg));
            });
        }

        return response()->noContent();
    }

    // قائمة الملخصات بانتظار اعتماد المستشار (المسندة إليه)
    public function summaries(Request $request): Response
    {
        $summaries = Ticket::with('summary')->whereHas('summary')
            ->where('assigned_lawyer_id', $request->user()->id)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->summary->toData(), [
                'type' => $t->type,
                'client' => Ticket::maskClient($t->user?->name ?? ''),
            ]));

        return Inertia::render('lawyer/summaries', ['summaries' => $summaries]);
    }

    // عرض ملخص ملف واحد للمراجعة والاعتماد
    public function showSummary(Request $request, Ticket $ticket): Response
    {
        $this->guardAssigned($ticket);
        $ticket->load(['user', 'summary']);
        abort_unless($ticket->summary, 404);

        return Inertia::render('lawyer/summary', [
            'ticket' => $ticket->toEmployeeCard(),
            'summary' => $ticket->summary->toData(),
            // الإدارة تراجع/تعتمد من مسارها الخاص (صلاحيات مطلقة)؛ المحامي من مساره
            'base' => $request->user()->isAdmin() ? '/admin' : '/lawyer',
        ]);
    }

    // حفظ تعديلات المحامي على نص الملخص
    public function updateSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        abort_unless($ticket->summary, 404);

        /*
         * **الاعتماد نهائيّ — والحفظ قبله مسوّدة.**
         *
         * `rerunSummary` كان محروساً بـ`isApproved()` و`updateSummary` مكشوفاً: أي أنّ
         * إعادةَ التوليد ممنوعةٌ بعد الاعتماد والكتابةَ اليدويّة مسموحة — وهي الأخطر،
         * لأنّها تُبدّل نصّاً اعتُمد رسميّاً ووصل صاحبَه بلا أثرٍ ولا إعادة اعتماد.
         * (قاعدة المالك 2026-09-08: كلُّ حقلٍ له اعتمادٌ نهائيّ يُقفَل بعده.)
         */
        abort_if(
            (bool) $ticket->summary->isApproved(),
            422,
            'اعتُمد هذا الملخّص رسميًّا — لا يُعدَّل بعد الاعتماد.'
        );
        // اعتمده المحامي ورُفع للإدارة — يُقفل عليه، والإدارة تعدّله إن لزم عند اعتمادها
        abort_if(
            ! $request->user()->isAdmin() && $ticket->summary->isLawyerApproved(),
            422,
            'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ — لا يُعدَّل من جهتك.'
        );

        $data = $request->validate([
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'attachments_summary' => ['nullable', 'string', 'max:5000'],
            'facts' => ['nullable', 'string', 'max:5000'],
            'key_points' => ['nullable', 'string', 'max:5000'],
        ]);
        // «حُرّر بيد المستشار» — فلا تكتب فوقه إعادة التوليد الآليّة (ع٢٦)
        $ticket->summary->update($data + ['edited_at' => now()]);

        return back();
    }

    // إعادة تشغيل تحليل الذكاء الاصطناعي للملخص (يطابق cRerun)
    public function rerunSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        abort_unless($ticket->summary, 404);
        if ($ticket->summary?->isApproved()) {
            throw ValidationException::withMessages([
                'summary' => 'لا يمكن إعادة تشغيل التحليل لملخّص تم اعتماده رسمياً.',
            ]);
        }

        abort_if($ticket->summary->isLawyerApproved(), 422, 'اعتُمد هذا الملخّص من المستشار — لا يُعاد توليده.');

        GenerateTicketSummaryJob::dispatch($ticket, force: true);

        return back()->with('flash', 'تمت إعادة تشغيل التحليل الذكي للملخّص.');
    }

    // اعتماد الملخص: يصل اعتماد المستشار والرأي القانوني إلى محادثة العميل + إشعار
    public function approveSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $summary = $ticket->summary;
        abort_unless($summary, 404);
        $actor = $request->user();
        $isAdmin = $actor->isAdmin();

        // **الاعتماد نهائيّ** — كان يُقبل على ملخّصٍ معتمد فيعدّل نصّه ويُرجع التذكرة ويُعيد الرسالة للعميل (ع٧)
        abort_if($summary->isApproved(), 422, 'اعتُمد هذا الملخّص ونُشر للعميل — لا يُعاد اعتماده.');
        abort_if(! $isAdmin && $summary->isLawyerApproved(), 422, 'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ.');

        $request->validate([
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'attachments_summary' => ['nullable', 'string', 'max:5000'],
            'facts' => ['nullable', 'string', 'max:5000'],
            'key_points' => ['nullable', 'string', 'max:5000'],
        ]);

        $fields = ['case_summary', 'attachments_summary', 'facts', 'key_points'];
        $before = $summary->only($fields);
        $summary->fill($request->only($fields));
        $edited = $summary->only($fields) != $before;

        // حارس الصدق: لا يُعتمد قالبٌ لم يُحلَّل ولم يحرّره أحد (SB-2026-1451)
        if (! $summary->ai_generated && ! $edited && $summary->edited_at === null && ! $summary->isLawyerApproved()) {
            throw ValidationException::withMessages([
                'case_summary' => 'لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — يُرجى انتظار التحليل الذكي أو تحرير الملخّص يدوياً قبل الاعتماد.',
            ]);
        }

        // **ولا يُعتمد ملخّصٌ سيعلق:** بلا وقائع أو بلا توصيات لا تُعتمد نتيجته لاحقاً أبداً (ع١٤)
        if (! TicketResult::hasSubstance($summary)) {
            throw ValidationException::withMessages([
                'key_points' => 'أكمل الوقائع والتوصيات قبل الاعتماد — ملخّصٌ بلا أحدهما لا يُبنى عليه رأي.',
            ]);
        }

        // النصّ والأختام تُحفظ داخل انتقال الملخّص — المحرّك يعيد قراءة الصفّ مقفولاً، فما مُلئ
        // هنا لقياس «هل حُرّر؟» يصله في الحمولة لا على هذه النسخة
        $approval = ['fields' => $request->only($fields), 'edited' => $edited];

        // ── المرحلة الأولى: المستشار يعتمد ويرفع للإدارة — لا يصل العميلَ شيء ──
        if (! $isAdmin) {
            Workflow::run(new LawyerApproveTicketSummary, $summary, $actor, $approval);

            AiReviewOutcome::recordFileApproval('ticket.summary', $ticket->number, $actor, $edited);

            // التذكرة التي تقدّمت لا ترتدّ — وغير المقبول يُتجاوز بصمتٍ كما كان
            $awaitAdmin = new AwaitAdminSummaryApproval;
            if ($awaitAdmin->accepts((string) $ticket->status)) {
                Workflow::run($awaitAdmin, $ticket, $actor);
                Live::push(new TicketStatusBroadcast($ticket));
            }

            foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
                Notify::send($adminId, 'scale', 't-amber', "اعتمد المستشار {$actor->name} ملخّص التذكرة {$ticket->number} — بانتظار اعتمادكم النهائيّ قبل إرساله للعميل.");
            }

            Audit::log(
                action: 'اعتماد المستشار لملخّص تذكرة',
                description: "اعتمد {$actor->name} ملخّص التذكرة {$ticket->number} ورفعه للإدارة.",
                category: 'تذاكر',
                auditable: $ticket,
                auditableRef: $ticket->number,
            );

            return redirect()->route('lawyer.summaries')->with('flash', 'اعتُمد الملخّص ورُفع للإدارة لاعتماده النهائيّ قبل إرساله للعميل.');
        }

        // ── المرحلة الثانية: الإدارة تعتمد (وتعدّل إن لزم) فيُنشر للعميل ──
        // وإن كتبته الإدارة واعتمدته بنفسها يُعدّ اعتمادها للمرحلتين معاً.
        Workflow::run(new FinalApproveTicketSummary, $summary, $actor, $approval);

        AiReviewOutcome::recordFileApproval('ticket.summary', $ticket->number, $actor, $edited);

        // الغياب يُعلَن ولا يُملأ — والنمط من `TicketResult::NO_RECOMMENDATIONS`
        $body = '<p>تم اعتماد ملخص ملفكم، وفيما يلي الرأي القانوني المبدئي:</p>'
            .'<div class="doc-list" style="flex-direction:column">'.nl2br(e($summary->key_points)).'</div>'
            .'<p>ولإبداء الرأي الكامل ومناقشة التفاصيل نأمل طلب استشارة قانونية من داخل التذكرة.</p>';

        // **لا ترتدّ التذكرة** — كان يكتب «الرأي القانوني» بلا شرط فوق موعدٍ قائم (ج٩)
        $opinion = new PublishLegalOpinion;
        if ($opinion->accepts((string) $ticket->status)) {
            Workflow::run($opinion, $ticket, $actor);
        }

        $msg = $ticket->messages()->create([
            'who' => 'lawyer',
            'name' => $summary->lawyer?->name ?? 'المستشار القانوني',
            'role' => 'مستشار قانوني',
            'body' => $body,
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        Notify::send($ticket->user_id, 'scale', 't-cyan', "اعتُمد ملخص ملفك وصدر الرأي القانوني المبدئي على تذكرتك {$ticket->number}.");

        $ticket->loadMissing('user');
        if ($ticket->user?->email) {
            app(MailService::class)->send($ticket->user, new SummaryApprovedMail($ticket, $summary->key_points));
        }

        if ($summary->lawyer_approved_by && $summary->lawyer_approved_by !== $actor->id) {
            Notify::send($summary->lawyer_approved_by, 'check', 't-green', "اعتمدت الإدارة ملخّص التذكرة {$ticket->number} وأُرسل الرأي القانوني للعميل.");
        }

        return redirect()->route('admin.summaries')->with('flash', 'اعتُمد الملخّص وأُرسل الرأي القانوني للعميل.');
    }

    // توليد مسودة لائحة دعوى لمعايير منصة ناجز
    public function generateNajizDraft(Request $request, Ticket $ticket, LegalAiService $ai): JsonResponse
    {
        $this->guardAssigned($ticket);
        $result = $ai->najizStatementResult($ticket);
        $meta = $result['meta'];

        // قيدٌ في سجلّ القرارات — صحيفة الدعوى تُقدَّم للمحكمة وكانت تُنتَج بلا أثر:
        // لا كلفة ولا نموذج ولا إصدار تعليمة ولا مراجعة مطلوبة. وP0 يفرض أن **كل**
        // مخرج له حالة مصدر قابلة للعرض والتدقيق.

        AiRunLogger::log('najiz.statement', $result['source'], $meta, $ticket, (string) $ticket->number);

        return response()->json([
            'draft' => $result['draft'],
            // المصدر يصل الواجهة: مسودّةٌ لم يُطابَق استشهادها لا تُعرض كأنها مسنَدة
            'source' => $result['source']->value,
            'sourceLabel' => $result['source']->label(),
            'verdict' => $result['verdict'],
        ]);
    }

    // تصدير وطباعة تقرير دراسة الملف والرأي القانوني كـ PDF رسمي
    public function printSummary(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardAssigned($ticket);
        $ticket->loadMissing(['user', 'summary', 'documents']);
        $summary = $ticket->summary;
        abort_unless($summary, 404);

        $clientName = $ticket->user?->name ?? 'العميل';
        $lawyerName = $ticket->assigned_lawyer ?: ($request->user()->name ?: 'المستشار القانوني');

        $doc = SummaryReport::doc($ticket, $summary, $clientName, $lawyerName);

        $html = ReportPrint::html($doc);

        return PdfRenderer::render($html, 'Summary-'.$ticket->number.'.pdf');
    }

    // اعتماد المستشار لنتيجة الجلسة → ترفع للإدارة للاعتماد النهائي (يطابق tfLawyerReview)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_lawyer', 404);

        /*
         * **لا اعتمادَ لنتيجةٍ خاوية.**
         *
         * كان الشرط الوحيد `result_status`، ثمّ تُكتب باسم المحامي «راجعتُ ملخص الجلسة
         * والتوصيات… وهي معتمدة» — قولٌ بضمير المتكلّم عن مراجعةٍ لا يستطيع النظام أن
         * يعلم أنّها وقعت. والنمط مقرَّرٌ في هذا الملفّ نفسه: `approveSummary` يمنع
         * اعتماد قالبٍ لم يُحرَّر.
         */
        abort_if(
            ! TicketResult::hasSubstance($summary),
            422,
            'النتيجة بلا وقائع أو توصيات — استكملها قبل الاعتماد.'
        );

        Workflow::run(new LawyerApproveTicketResult, $summary, $request->user());
        Workflow::run(new AwaitAdminResultApproval, $ticket, $request->user());

        $msg = $ticket->messages()->create([
            'who' => 'lawyer',
            'name' => $request->user()->name,
            'role' => 'اعتماد',
            // **لا ادّعاءَ بضمير المتكلّم**: النظام يشهد بالاعتماد لا بالمراجعة
            'body' => '<p>اعتُمدت نتيجة الملف ورُفعت إلى الإدارة للاعتماد النهائي.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        return redirect()->route($request->user()->isAdmin() ? 'admin.tickets' : 'lawyer.tickets');
    }

    /**
     * تحويل التذكرة المكتملة إلى قضية قانونية — قرار المستشار.
     *
     * لا يُشترط هنا اعتماد الملخّص عمداً: **المحامي هو المعتمِد** فاشتراط اعتماد سابق
     * عليه دور، **والإدارة العليا هي الاعتماد النهائي** فتمرّ بلا قيد. المسار الموظفيّ
     * وحده ينتظر اعتماد المحامي (Employee\TicketController::convertToCase).
     * القاعدة الثلاثية مثبّتة باختبارات في CaseConversionTest كي لا تُوحَّد سهواً.
     */
    public function convertToCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        CaseConversion::assertEligible($ticket, requireApprovedSummary: false);

        CaseConversion::convert($ticket, $request->user());

        // البقاء على المحادثة كما يفعل مسار الموظف — كان التحويل ينقل المحامي/الإدارة
        // إلى قائمة التذاكر فيغادران السياق وتظهر رسالة النجاح على صفحة أخرى.
        return back();
    }

    // قرار المستشار: إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية (يطابق cfClose)
    public function closeWithoutCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        if (! in_array($ticket->status, [TicketStatus::ReadyForOutcome->value, TicketStatus::Completed->value], true)) {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن إغلاق التذكرة إلا بعد اكتمالها.',
            ]);
        }
        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً ولا يمكن إغلاقها.',
            ]);
        }

        $data = $request->validate([
            'closure_reason_code' => ['nullable', 'string', Rule::in(ClosureReasonCode::values())],
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reasonCode = $data['closure_reason_code'] ?? ClosureReasonCode::OpinionSatisfied->value;
        $reasonEnum = ClosureReasonCode::tryFrom($reasonCode) ?? ClosureReasonCode::OpinionSatisfied;
        $notes = trim($data['closure_notes'] ?? '') ?: $reasonEnum->label();

        Workflow::run(new CloseTicketJustified, $ticket, $request->user(), [
            'closure_reason_code' => $reasonCode,
            'closure_notes' => $notes,
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'إغلاق',
            'body' => '<p>تم إغلاق الطلب بعد دراسة الملف وتحديد الموقف القانوني. سبب الإغلاق: <b>'.e($reasonEnum->label()).'</b>.</p><p>'.nl2br(e($notes)).'</p>',
            'time_label' => $this->clock(),
        ]);

        // قرارٌ نهائيّ على الملفّ — إشعار وسجل تدقيق
        Notify::send($ticket->user_id, 'check', 't-grey', "أُغلقت تذكرتك {$ticket->number} بعد الاستشارة دون تحويلها إلى قضية، ومخرجاتها محفوظة داخلها.");
        Audit::log(
            action: 'إغلاق تذكرة دون قضية',
            description: "أغلق {$request->user()->name} التذكرة {$ticket->number} بسبب «{$reasonEnum->label()}».",
            category: 'تذاكر',
            auditable: $ticket,
            auditableRef: $ticket->number,
            beforeState: ['الحالة' => 'مكتملة'],
            afterState: ['الحالة' => 'مغلقة', 'سبب_الإغلاق' => $reasonCode],
        );
        Live::push(new TicketMessageBroadcast($msg));

        return redirect()->route($request->user()->isAdmin() ? 'admin.tickets' : 'lawyer.tickets');
    }

    // قرار المستشار: طلب مستندات إضافية قبل اتخاذ القرار (يطابق cfReqDocs)
    public function requestDocs(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'نواقص',
            'body' => '<p>لاستكمال تقييم الطلب قبل اتخاذ القرار، نأمل تزويدنا بالمستندات الإضافية التالية:</p><div class="doc-list">'.$chips.'</div>'
                .'<p class="muted">'.ServiceDocs::NOTE.'</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        // **الطلب يُتابَع:** قبل اعتماد الملخّص تنتقل التذكرة إلى «بانتظار مستندات» (ج٨)؛
        // وبعده تبقى حيث هي — لا يُمحى اعتمادٌ وقع بطلب مستند.
        $early = in_array($ticket->status, TicketJourney::BEFORE_LAWYER_APPROVAL, true);
        if ($early) {
            Workflow::run(new AwaitTicketDocuments, $ticket, $request->user(), [
                'last_message' => 'طلب المستشار مستندات إضافية',
                'via' => 'lawyer.request_docs',
            ]);
            Live::push(new TicketStatusBroadcast($ticket));
        } else {
            $ticket->update(['last_message' => 'طلب المستشار مستندات إضافية', 'date_label' => 'الآن']);
        }

        Notify::send($ticket->user_id, 'upload', 't-amber', "طلب المستشار مستندات إضافية على تذكرتك {$ticket->number}.");

        return back();
    }

    // مقترح المستشار لمسار مآل التذكرة (أحد المسارات الأربعة) مرفوعاً للإدارة العليا
    public function proposeTrack(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $data = $request->validate([
            'track' => ['required', 'string', Rule::in(TicketOutcomeTrack::values())],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        Workflow::run(new ProposeOutcomeTrack, $ticket, $request->user(), $data);

        return back()->with('flash', 'تم رفع مقترح المسار للإدارة العليا للاعتماد بنجاح.');
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
