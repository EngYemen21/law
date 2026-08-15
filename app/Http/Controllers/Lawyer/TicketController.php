<?php

namespace App\Http\Controllers\Lawyer;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateTicketSummaryJob;
use App\Mail\SummaryApprovedMail;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\MailService;
use App\Support\CaseConversion;
use App\Support\Live;
use App\Support\Notify;
use App\Support\ServiceDocs;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي (المستشار) — التذاكر المحالة وملخصاتها.
 * يراجع المحامي ملخص الملف الذي جهّزه الفريق القانوني (الذكاء الاصطناعي) ويعتمده،
 * فيصل اعتماده والرأي القانوني مباشرةً إلى محادثة العميل مع الموظف + إشعار للعميل.
 */
class TicketController extends Controller
{
    use ScopedToLawyer;

    // التذاكر المحالة للمستشار (التي لها ملخص ملف ومسندة إليه)
    public function index(Request $request): Response
    {
        $tickets = Ticket::with(['user', 'summary'])->withExists('legalCase')->whereHas('summary')
            ->where('assigned_lawyer_id', $request->user()->id)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['converted' => (bool) $t->legal_case_exists]));

        return Inertia::render('lawyer/tickets', ['tickets' => $tickets]);
    }

    // لوحة المحامي — مؤشرات حقيقية بالكامل
    public function dashboard(Request $request): Response
    {
        $lawyerId = $request->user()->id;
        // التذاكر المحالة لهذا المحامي (لها ملخص ومسندة إليه)
        $tickets = Ticket::with(['user', 'summary'])->whereHas('summary')
            ->where('assigned_lawyer_id', $lawyerId)->latest('id')->get();

        return Inertia::render('lawyer/dashboard', [
            'tickets' => $tickets->map(fn (Ticket $t) => $t->toEmployeeCard()),
            'pendingSummaries' => $tickets->where('summary.status', 'awaiting_lawyer')->count(),
            'todayMeetings' => Meeting::where('created_by', $lawyerId)
                ->whereIn('status', ['قادم', 'جارٍ'])->count(),
            'openTasks' => Task::where('assigned_to', $lawyerId)->where('status', '!=', 'منجزة')->count(),
        ]);
    }

    // محادثة التذكرة (قراءة سياق + الملخص) للمستشار
    public function show(Request $request, Ticket $ticket): Response
    {
        $this->guardAssigned($ticket);
        $ticket->load(['user', 'summary', 'legalCase']);

        return Inertia::render('lawyer/ticketchat', [
            // رقم القضية الحقيقي — كانت الواجهة تطبع نصّاً ثابتاً مكان الرقم
            'ticket' => array_merge($ticket->toEmployeeCard(), ['caseRef' => $ticket->legalCase?->number]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => $ticket->messages->map->toMessage(),
            'summary' => $ticket->summary?->toData(),
            'converted' => (bool) $ticket->legalCase,
            // الإدارة تفتح نفس الصفحة من مسارها — الروابط تُبنى من base لا مثبّتة على /lawyer
            'base' => $request->user()->isAdmin() ? '/admin' : '/lawyer',
        ]);
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
        $data = $request->validate([
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'attachments_summary' => ['nullable', 'string', 'max:5000'],
            'facts' => ['nullable', 'string', 'max:5000'],
            'key_points' => ['nullable', 'string', 'max:5000'],
        ]);
        $ticket->summary->update($data);

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

        GenerateTicketSummaryJob::dispatch($ticket, force: true);

        return back()->with('flash', 'تمت إعادة تشغيل التحليل الذكي للملخّص.');
    }

    // اعتماد الملخص: يصل اعتماد المستشار والرأي القانوني إلى محادثة العميل + إشعار
    public function approveSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $summary = $ticket->summary;
        abort_unless($summary, 404);

        // حفظ أي تعديلات مُرسلة مع الاعتماد
        $request->validate([
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'attachments_summary' => ['nullable', 'string', 'max:5000'],
            'facts' => ['nullable', 'string', 'max:5000'],
            'key_points' => ['nullable', 'string', 'max:5000'],
        ]);

        // حارس الصدق: لا يُعتمَد ملخّص قالبي لم يُحلَّل بالـAI ما لم يحرّره المحامي يدوياً —
        // فلا يُرسَل رأي أجوف للعميل (يمنع تكرار اعتماد القالب كما في SB-2026-1451).
        $fields = ['case_summary', 'attachments_summary', 'facts', 'key_points'];
        $before = $summary->only($fields);
        $summary->fill($request->only($fields));
        $edited = $summary->only($fields) != $before;
        if (! $summary->ai_generated && ! $summary->isApproved() && ! $edited) {
            throw ValidationException::withMessages([
                'case_summary' => 'لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — يُرجى انتظار التحليل الذكي أو تحرير الملخّص يدوياً قبل الاعتماد.',
            ]);
        }

        if ($summary->status !== 'approved') {
            $summary->status = 'approved';
            $summary->approved_at = now();
            $summary->lawyer_id = $request->user()->id;
        }
        $summary->save();

        // الرأي القانوني المبدئي يظهر للعميل (من النقاط المهمة المعتمدة)
        $opinion = trim((string) $summary->key_points) !== ''
            ? nl2br(e($summary->key_points))
            : 'تمت الدراسة المبدئية للملف.';
        $body = '<p>تم اعتماد ملخص ملفكم من المستشار القانوني، وفيما يلي الرأي القانوني المبدئي:</p>'
            .'<div class="doc-list" style="flex-direction:column">'.$opinion.'</div>'
            .'<p>ولإبداء الرأي الكامل ومناقشة التفاصيل نأمل حجز استشارة قانونية من قسم «حجز استشارة».</p>';

        $ticket->update([
            'status' => 'الرأي القانوني',
            'tone' => TicketJourney::toneFor('الرأي القانوني'),
            'last_message' => 'اعتمد المستشار ملخص الملف وأصدر الرأي القانوني المبدئي',
            'date_label' => 'الآن',
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'lawyer',
            'name' => $request->user()->name,
            'role' => 'مستشار قانوني',
            'body' => $body,
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        // إشعار للعميل
        Notify::send($ticket->user_id, 'scale', 't-cyan', "اعتمد المستشار ملخص ملفك وأصدر الرأي القانوني المبدئي على تذكرتك {$ticket->number}.");

        // بريد للعميل باعتماد الملخّص والرأي القانونيّ (أفضل-جهد — لا يعطّل الطلب إن فشل)
        $ticket->loadMissing('user');
        if ($ticket->user?->email) {
            app(MailService::class)->send($ticket->user, new SummaryApprovedMail($ticket, $summary->key_points));
        }

        return redirect()->route($request->user()->isAdmin() ? 'admin.summaries' : 'lawyer.summaries');
    }

    // اعتماد المستشار لنتيجة الجلسة → ترفع للإدارة للاعتماد النهائي (يطابق tfLawyerReview)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_lawyer', 404);

        $summary->update(['result_status' => 'pending_admin']);

        $ticket->update([
            'status' => 'بانتظار اعتماد الإدارة',
            'tone' => TicketJourney::toneFor('بانتظار اعتماد الإدارة'),
            'last_message' => 'اعتمد المستشار ملخص الجلسة ورفعه للإدارة',
            'date_label' => 'الآن',
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'lawyer',
            'name' => $request->user()->name,
            'role' => 'اعتماد',
            'body' => '<p>راجعتُ ملخص الجلسة والتوصيات والإجراءات المقترحة، وهي معتمدة ومرفوعة إلى الإدارة للاعتماد النهائي.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        return redirect()->route($request->user()->isAdmin() ? 'admin.tickets' : 'lawyer.tickets');
    }

    // تحويل التذكرة المكتملة إلى قضية قانونية (يطابق cfConvert) — قرار المستشار
    public function convertToCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        if ($ticket->status !== 'مكتملة') {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اكتمالها.',
            ]);
        }
        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً.',
            ]);
        }

        CaseConversion::convert($ticket, $request->user());

        return redirect()->route($request->user()->isAdmin() ? 'admin.tickets' : 'lawyer.tickets');
    }

    // قرار المستشار: إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية (يطابق cfClose)
    public function closeWithoutCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        if ($ticket->status !== 'مكتملة') {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن إغلاق التذكرة إلا بعد اكتمالها.',
            ]);
        }
        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً ولا يمكن إغلاقها.',
            ]);
        }

        $ticket->update(['status' => 'مغلقة', 'tone' => TicketJourney::toneFor('مغلقة'), 'last_message' => 'أُغلق الطلب بعد الاستشارة دون تحويله إلى قضية', 'date_label' => 'الآن']);
        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'إغلاق',
            'body' => '<p>تم إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية، وحُفظت كامل المخرجات داخل التذكرة.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        return redirect()->route($request->user()->isAdmin() ? 'admin.tickets' : 'lawyer.tickets');
    }

    // قرار المستشار: طلب مستندات إضافية قبل اتخاذ القرار (يطابق cfReqDocs)
    public function requestDocs(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardAssigned($ticket);
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'نواقص',
            'body' => '<p>لاستكمال تقييم الطلب قبل اتخاذ القرار، نأمل تزويدنا بالمستندات الإضافية التالية:</p><div class="doc-list">'.$chips.'</div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        $ticket->update(['last_message' => 'طلب المستشار مستندات إضافية', 'date_label' => 'الآن']);

        Notify::send($ticket->user_id, 'upload', 't-amber', "طلب المستشار مستندات إضافية على تذكرتك {$ticket->number}.");

        return back();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
