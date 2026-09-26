<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\ApproveOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\CorrectTicketStatus;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\AdminApprovalQueue;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\Paginate;
use App\Support\SearchText;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشراف الإدارة العليا — رؤية كاملة لكل التذاكر وملخصاتها ومسار معالجتها وفلترتها.
 */
class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $dept = trim((string) $request->query('dept', ''));
        $lawyerId = trim((string) $request->query('lawyer_id', ''));
        $priority = trim((string) $request->query('priority', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = trim((string) $request->query('sort', 'latest'));

        // العميل وحده يُقرأ من علاقة — الملخّص والمحامي لا يعرضهما الصفّ (`listRow`)
        $query = Ticket::with(['user:id,name']);

        // 1. البحث النصي
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                // `SearchText` يطبّع الطرفين ويهرّب `%` و`_` — كان البحث يخفق على «٢٠٢٦»
                // وعلى «احمد» بلا همزة، ويُرجع الجدول كلّه على إبرةٍ فيها `%`.
                SearchText::apply($q, ['number', 'subject', 'type', 'opponent_name'], $search);
                $q->orWhereHas('user', fn ($uq) => SearchText::apply($uq, ['name', 'phone', 'national_id'], $search));
            });
        }

        // 2. الفلترة بحسب الحالة
        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'pending_admin') {
            self::awaitingAdmin($query);
        } elseif ($status === 'completed') {
            $query->terminal();
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        // 3. الفلترة بحسب القسم
        if ($dept !== '') {
            $query->where('department', $dept);
        }

        // 4. الفلترة بحسب المحامي المسند
        if ($lawyerId === 'unassigned') {
            $query->whereNull('assigned_lawyer_id');
        } elseif ($lawyerId !== '') {
            $query->where('assigned_lawyer_id', $lawyerId);
        }

        // 5. الفلترة بحسب الأولوية
        if ($priority !== '') {
            $query->where('priority', $priority);
        }

        // 6. الفلترة بحسب التاريخ
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // 7. الترتيب
        if ($sort === 'oldest') {
            $query->orderBy('id', 'asc');
        } elseif ($sort === 'priority') {
            // **من الكتالوج لا مكتوباً بيد.** كان `'عاجلة'` أوّلاً وهي لا وجود لها في القاعدة،
            // فتسقط `'عالية'` — الأولويّة العليا الفعليّة — في `ELSE` **دون** المتوسّطة: يضغط
            // المدير «الأعلى أولاً» فتنزل تذاكره العاجلة إلى الذيل.
            $query->orderByRaw(TicketJourney::prioritySql())
                ->orderByDesc('id');
        } else {
            $query->latest('id');
        }

        $tickets = $query->paginate(50)->withQueryString();

        // أقسام مميزة
        $departments = Ticket::whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department');

        // قائمة المحامين
        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('admin/tickets', [
            'tickets' => Paginate::shape($tickets, fn (Ticket $t) => self::listRow($t)),
            'filters' => [
                'q' => $search,
                'status' => $status,
                'dept' => $dept,
                'lawyer_id' => $lawyerId,
                'priority' => $priority,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sort' => $sort,
            ],
            'departments' => $departments,
            'lawyers' => $lawyers,
            'summaryStats' => [
                'total' => Ticket::count(),
                'open' => Ticket::open()->count(),
                // الشرط نفسه الذي يرشّح به التبويب — فالعدّاد يعدّ ما يعرضه
                'pending_admin' => self::awaitingAdmin(Ticket::query())->count(),
                'completed' => Ticket::terminal()->count(),
            ],
        ]);
    }

    /**
     * **«بانتظار الاعتماد» — ما ينتظر الإدارة العليا** (قرار المالك 2026-09-14)، شرطٌ واحد للتبويب وعدّاده.
     *
     * الرحلة الجديدة: ملخّص ملفٍّ اعتمده المحامي (`summary.status = awaiting_admin` وحالة
     * «بانتظار اعتماد الإدارة للملخّص»)، أو ملخّص جلسةٍ اعتمده المحامي ولم تعتمده الإدارة.
     * حُذف 2026-09-19 الشرطان القديمان: الحالة «بانتظار اعتماد الإدارة» و`result_status = pending_admin`
     * (مصدره الوحيد اعتماد المحامي للنتيجة، وحُذف) — لا يكتبهما شيء.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function awaitingAdmin(Builder $query): Builder
    {
        // كان يُغفل مقترحات المسار («بانتظار اعتماد الإدارة للمسار») — والتعريف الآن واحدٌ مع
        // مركز الاعتمادات ورادار اللوحة (`AdminApprovalQueue`)، فلا يقول تبويبٌ رقماً غير الآخر
        return AdminApprovalQueue::ticketsAwaitingAdmin($query);
    }

    /**
     * **صفّ القائمة على قدر ما تعرضه** — رقمٌ وعميلٌ ونوعٌ وقسمٌ ومحامٍ وحالةٌ وتاريخ.
     *
     * كان كلّ صفٍّ يُبنى بـ`toEmployeeCard()` كاملةً: مصفوفة الأفعال وكيان الدومين وملفّا التحويل
     * وحوكمة المسار (ومنها سجلّ الرحلة لسبب التجاوز) — نحو ستّة استعلامات للصفّ، خمسون صفّاً للصفحة،
     * والجدول لا يقرأ منها إلّا عشرة حقول. العميل مُحمَّل مسبقاً، والمحامي من عموده النصّيّ.
     *
     * @return array<string, mixed>
     */
    private static function listRow(Ticket $t): array
    {
        return [
            'no' => $t->number,
            'client' => $t->user?->name ?? '—',
            'clientId' => $t->user_id,
            'type' => $t->type,
            'subject' => $t->subject,
            'priority' => $t->priority ?: 'متوسطة',
            'dept' => $t->department,
            'lawyer' => $t->assigned_lawyer ?: '—',
            'lawyerId' => $t->assigned_lawyer_id,
            'status' => $t->status,
            'tone' => $t->tone,
            'date' => $t->created_at?->format('Y-m-d') ?: '—',
        ];
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary', 'legalCase', 'execution']);

        return Inertia::render('lawyer/ticketchat', [
            // من يتولّى المحادثة الآن ومن تولّاها قبله — للطاقم وحده (`ConversationHandler`)
            'conversation' => ConversationHandler::history($ticket),
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                'execRef' => $ticket->execution?->number,
                // الإدارة ترى الاسم الكامل + الجوال (بطاقة «تفاصيل الطلب»)
                'client' => $ticket->user?->name ?? '—',
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => ConversationFiles::linkLegacyChips($ticket->messages->map->toMessage()->all(), 'ticket', $ticket->documents),
            'summary' => $ticket->summary?->toData(),
            // كانت مفقودة ⇒ canConvert صحيح دائماً فيظهر زر التحويل حتى بعد التحويل
            'converted' => (bool) ($ticket->legalCase || $ticket->execution),
            'convertedType' => $ticket->execution ? 'execution' : ($ticket->legalCase ? 'case' : null),
            // بطاقة «تصحيح الحالة» من حارس الانتقال نفسه — لا قائمةً مكتوبةً في الواجهة
            'correction' => CorrectTicketStatus::form($ticket),
            'base' => '/admin',
        ]);
    }

    public function summaries(): Response
    {
        $summaries = TicketSummary::with(['ticket.user', 'lawyer'])
            ->whereHas('ticket')
            ->orderByRaw('COALESCE(approved_at, lawyer_approved_at, updated_at) DESC')
            ->get()
            ->map(fn (TicketSummary $s) => array_merge($s->toData(), [
                'type' => $s->ticket?->type,
                'client' => $s->ticket?->user?->name ?? '—',
            ]))->values();

        return Inertia::render('admin/summaries', ['summaries' => $summaries]);
    }

    /**
     * **تصحيح حالة التذكرة — استثناءٌ إداريّ مسبَّب** (يحلّ محلّ قائمة «تغيير الحالة» عند الموظّف).
     */
    public function correctStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        Workflow::run(new CorrectTicketStatus, $ticket, $request->user(), $data);

        return back()->with('flash', 'صُحّحت حالة التذكرة وسُجّل السبب.');
    }

    // مقترح الإدارة أو تحديث المقترح لمسار التذكرة
    public function proposeTrack(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'track' => ['required', 'string', Rule::in(TicketOutcomeTrack::values())],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            // المسار السريع للإدارة بلا ملخّصٍ معتمد — طوله وشرطه يحكمهما `OutcomeSummaryGate`
            OutcomeSummaryGate::WAIVER => ['nullable', 'string', 'max:1000'],
        ]);

        Workflow::run(new ProposeOutcomeTrack, $ticket, $request->user(), $data);

        return back()->with('flash', 'تم تسجيل مقترح المسار بنجاح.');
    }

    // اعتماد الإدارة العليا النهائي لمسار مآل التذكرة (أحد المسارات الأربعة) ونشره للعميل
    public function approveTrack(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'track' => ['required', 'string', Rule::in(TicketOutcomeTrack::values())],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'closure_reason_code' => ['nullable', 'string', Rule::in(ClosureReasonCode::values())],
            OutcomeSummaryGate::WAIVER => ['nullable', 'string', 'max:1000'],
        ]);

        // من رفع المقترح متجاوزاً بسببٍ مدوَّن لا يُطالَب به ثانيةً ليعتمده — يُورَث من سجلّ الرحلة
        // والحارس يبقى صارماً: السبب يصله في الحمولة كما لو كُتب الآن، ويُقيَّد في سطر الاعتماد
        $data[OutcomeSummaryGate::WAIVER] = filled($data[OutcomeSummaryGate::WAIVER] ?? null)
            ? $data[OutcomeSummaryGate::WAIVER]
            : OutcomeSummaryGate::inheritedWaiver($ticket, $request->user());

        Workflow::run(new ApproveOutcomeTrack, $ticket, $request->user(), $data);

        $trackEnum = TicketOutcomeTrack::from($data['track']);

        return back()->with('flash', "تم اعتماد مسار ({$trackEnum->label()}) بنجاح ونُشر القرار والتسبيب للعميل.");
    }
}
