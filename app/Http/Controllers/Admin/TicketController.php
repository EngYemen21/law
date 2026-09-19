<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\AdminApprovePendingResult;
use App\Domain\Journey\Transitions\Ticket\ApproveOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\CorrectTicketStatus;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\ReadyForOutcome;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\ConversationFiles;
use App\Support\Live;
use App\Support\Notify;
use App\Support\Paginate;
use App\Support\SearchText;
use App\Support\TicketJourney;
use App\Support\TicketResult;
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

        $query = Ticket::with(['user', 'summary', 'assignedLawyer']);

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
            $query->whereNotIn('status', ['مكتملة', 'مغلقة']);
        } elseif ($status === 'pending_admin') {
            self::awaitingAdmin($query);
        } elseif ($status === 'completed') {
            $query->where('status', 'مكتملة');
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
            'tickets' => Paginate::shape(
                $tickets,
                fn (Ticket $t) => array_merge($t->toEmployeeCard(), [
                    'client' => $t->user?->name ?? '—',
                    'date' => $t->created_at?->format('Y-m-d') ?: '—',
                ])
            ),
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
                'open' => Ticket::whereNotIn('status', ['مكتملة', 'مغلقة'])->count(),
                // الشرط نفسه الذي يرشّح به التبويب — فالعدّاد يعدّ ما يعرضه
                'pending_admin' => self::awaitingAdmin(Ticket::query())->count(),
                'completed' => Ticket::where('status', 'مكتملة')->count(),
            ],
        ]);
    }

    /**
     * **«بانتظار الاعتماد» — ما ينتظر الإدارة العليا** (قرار المالك 2026-09-14)، شرطٌ واحد للتبويب وعدّاده.
     *
     * الرحلة الجديدة: ملخّص ملفٍّ اعتمده المحامي (`summary.status = awaiting_admin` وحالة
     * «بانتظار اعتماد الإدارة للملخّص»)، أو ملخّص جلسةٍ اعتمده المحامي ولم تعتمده الإدارة.
     * والشرطان القديمان باقيان لصفوفٍ قائمة («بانتظار اعتماد الإدارة» و`result_status = pending_admin`).
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function awaitingAdmin(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'بانتظار اعتماد الإدارة للملخّص')
                ->orWhereHas('summary', fn (Builder $sq) => $sq->where(
                    fn (Builder $w) => $w->where('status', 'awaiting_admin')->orWhere('result_status', 'pending_admin')
                ))
                ->orWhereHas('consults', fn (Builder $cq) => $cq
                    ->whereNotNull('summary_lawyer_approved_at')
                    ->whereNull('summary_approved_at'));
        });
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary', 'legalCase']);

        return Inertia::render('lawyer/ticketchat', [
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                // الإدارة ترى الاسم الكامل + الجوال (بطاقة «تفاصيل الطلب»)
                'client' => $ticket->user?->name ?? '—',
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => ConversationFiles::linkLegacyChips($ticket->messages->map->toMessage()->all(), 'ticket', $ticket->documents),
            'summary' => $ticket->summary?->toData(),
            // كانت مفقودة ⇒ canConvert صحيح دائماً فيظهر زر التحويل حتى بعد التحويل
            'converted' => (bool) $ticket->legalCase,
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

    // الاعتماد النهائي للإدارة → بطاقة النتيجة تصل العميل وتكتمل التذكرة (يطابق tfAdminReview→tfResult)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_admin', 404);
        // مسارٌ قديم للصفوف القائمة — لا يُعيد «مكتملة» على تذكرةٍ أُغلقت أو حُوّلت لقضيّة (ع٨)
        abort_if(
            in_array($ticket->status, ['مكتملة', 'مغلقة'], true) || $ticket->legalCase()->exists(),
            422,
            'التذكرة مكتملة أو مغلقة أو محوّلة لقضيّة — لا يُعاد اعتماد نتيجتها.'
        );

        // نظير حارس المحامي: نتيجةٌ خاوية لا تُعتمد ولا تُرسَل للعميل
        abort_if(
            ! TicketResult::hasSubstance($summary),
            422,
            'النتيجة بلا وقائع أو توصيات — استكملها قبل الاعتماد.'
        );

        Workflow::run(new AdminApprovePendingResult, $summary, $request->user());

        $ack = $ticket->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة',
            'role' => 'اعتماد',
            /*
             * **لا تُذكر «محضر الجلسة» إلّا حين يكون معتمَداً فعلاً.**
             * مسارُ الإدارة هذا لا يمسّ ملخّص الاستشارة إطلاقاً — اعتمادُه مسارٌ منفصل
             * (`consults/{consult}/summary/approve`). فكان يدّعي اعتماد ما لم يلمسه.
             */
            'body' => $ticket->consults()->whereNotNull('summary_approved_at')->exists()
                ? '<p>تم اعتماد نتيجة الملف ومحضر الجلسة من الإدارة. تُرسل النتيجة النهائية الآن.</p>'
                : '<p>تم اعتماد نتيجة الملف من الإدارة. تُرسل النتيجة النهائية الآن.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($ack));

        $result = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'النتيجة',
            'body' => TicketResult::card($ticket, $summary),
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($result));

        Workflow::run(new ReadyForOutcome, $ticket, $request->user());

        Notify::send($ticket->user_id, 'check', 't-green', "اكتملت معالجة تذكرتك {$ticket->number}، والنتيجة النهائية والتوصيات متاحة داخل التذكرة.");

        return redirect()->route('admin.tickets');
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
        ]);

        Workflow::run(new ApproveOutcomeTrack, $ticket, $request->user(), $data);

        $trackEnum = TicketOutcomeTrack::from($data['track']);

        return back()->with('flash', "تم اعتماد مسار ({$trackEnum->label()}) بنجاح ونُشر القرار والتسبيب للعميل.");
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
