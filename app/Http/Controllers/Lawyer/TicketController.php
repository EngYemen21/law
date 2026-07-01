<?php

namespace App\Http\Controllers\Lawyer;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\UserNotification;
use App\Support\ServiceDocs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي (المستشار) — التذاكر المحالة وملخصاتها.
 * يراجع المحامي ملخص الملف الذي جهّزه الفريق القانوني (الذكاء الاصطناعي) ويعتمده،
 * فيصل اعتماده والرأي القانوني مباشرةً إلى محادثة العميل مع الموظف + إشعار للعميل.
 */
class TicketController extends Controller
{
    // التذاكر المحالة للمستشار (التي لها ملخص ملف)
    public function index(): Response
    {
        $tickets = Ticket::with(['user', 'summary'])->withExists('legalCase')->whereHas('summary')->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['converted' => (bool) $t->legal_case_exists]));

        return Inertia::render('lawyer/tickets', ['tickets' => $tickets]);
    }

    // لوحة المحامي
    public function dashboard(): Response
    {
        $tickets = Ticket::with(['user', 'summary'])->whereHas('summary')->latest('id')->get();

        return Inertia::render('lawyer/dashboard', [
            'tickets' => $tickets->map(fn (Ticket $t) => $t->toEmployeeCard()),
            'pendingSummaries' => $tickets->where('summary.status', 'awaiting_lawyer')->count(),
        ]);
    }

    // محادثة التذكرة (قراءة سياق + الملخص) للمستشار
    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary']);

        return Inertia::render('lawyer/ticketchat', [
            'ticket' => $ticket->toEmployeeCard(),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => $ticket->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'summary' => $ticket->summary?->toData(),
            'converted' => $ticket->legalCase()->exists(),
        ]);
    }

    // قائمة الملخصات بانتظار اعتماد المستشار
    public function summaries(): Response
    {
        $summaries = Ticket::with('summary')->whereHas('summary')->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->summary->toData(), [
                'type' => $t->type,
                'client' => Ticket::maskClient($t->user?->name ?? ''),
            ]));

        return Inertia::render('lawyer/summaries', ['summaries' => $summaries]);
    }

    // عرض ملخص ملف واحد للمراجعة والاعتماد
    public function showSummary(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary']);
        abort_unless($ticket->summary, 404);

        return Inertia::render('lawyer/summary', [
            'ticket' => $ticket->toEmployeeCard(),
            'summary' => $ticket->summary->toData(),
        ]);
    }

    // حفظ تعديلات المحامي على نص الملخص
    public function updateSummary(Request $request, Ticket $ticket): RedirectResponse
    {
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

    // اعتماد الملخص: يصل اعتماد المستشار والرأي القانوني إلى محادثة العميل + إشعار
    public function approveSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $summary = $ticket->summary;
        abort_unless($summary, 404);

        // حفظ أي تعديلات مُرسلة مع الاعتماد
        $request->validate([
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'attachments_summary' => ['nullable', 'string', 'max:5000'],
            'facts' => ['nullable', 'string', 'max:5000'],
            'key_points' => ['nullable', 'string', 'max:5000'],
        ]);
        $summary->fill($request->only(['case_summary', 'attachments_summary', 'facts', 'key_points']));

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
            'tone' => 'b-cyan',
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
        broadcast(new TicketMessageBroadcast($msg));
        broadcast(new TicketStatusBroadcast($ticket));

        // إشعار للعميل
        UserNotification::create([
            'user_id' => $ticket->user_id,
            'icon' => 'scale',
            'tone' => 't-cyan',
            'body' => "اعتمد المستشار ملخص ملفك وأصدر الرأي القانوني المبدئي على تذكرتك {$ticket->number}.",
            'time_label' => 'الآن',
            'is_read' => false,
        ]);

        return redirect()->route('lawyer.summaries');
    }

    // اعتماد المستشار لنتيجة الجلسة → ترفع للإدارة للاعتماد النهائي (يطابق tfLawyerReview)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_lawyer', 404);

        $summary->update(['result_status' => 'pending_admin']);

        $ticket->update([
            'status' => 'بانتظار اعتماد الإدارة',
            'tone' => 'b-blue',
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
        broadcast(new TicketMessageBroadcast($msg));
        broadcast(new TicketStatusBroadcast($ticket));

        return redirect()->route('lawyer.tickets');
    }

    // تحويل التذكرة المكتملة إلى قضية قانونية (يطابق cfConvert) — قرار المستشار
    public function convertToCase(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->status === 'مكتملة', 422);
        abort_if($ticket->legalCase()->exists(), 409); // محوّلة مسبقاً

        \App\Support\CaseConversion::convert($ticket, $request->user());

        return redirect()->route('lawyer.tickets');
    }

    // قرار المستشار: إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية (يطابق cfClose)
    public function closeWithoutCase(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->status === 'مكتملة', 422);
        abort_if($ticket->legalCase()->exists(), 409);

        $ticket->update(['status' => 'مغلقة', 'tone' => 'b-grey', 'last_message' => 'أُغلق الطلب بعد الاستشارة دون تحويله إلى قضية', 'date_label' => 'الآن']);
        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'إغلاق',
            'body' => '<p>تم إغلاق الطلب بعد الاستشارة دون تحويله إلى قضية، وحُفظت كامل المخرجات داخل التذكرة.</p>',
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));
        broadcast(new TicketStatusBroadcast($ticket));

        return redirect()->route('lawyer.tickets');
    }

    // قرار المستشار: طلب مستندات إضافية قبل اتخاذ القرار (يطابق cfReqDocs)
    public function requestDocs(Request $request, Ticket $ticket): RedirectResponse
    {
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
        $msg = $ticket->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'نواقص',
            'body' => '<p>لاستكمال تقييم الطلب قبل اتخاذ القرار، نأمل تزويدنا بالمستندات الإضافية التالية:</p><div class="doc-list">'.$chips.'</div>',
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));
        $ticket->update(['last_message' => 'طلب المستشار مستندات إضافية', 'date_label' => 'الآن']);

        UserNotification::create([
            'user_id' => $ticket->user_id, 'icon' => 'upload', 'tone' => 't-amber',
            'body' => "طلب المستشار مستندات إضافية على تذكرتك {$ticket->number}.",
            'time_label' => 'الآن', 'is_read' => false,
        ]);

        return back();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
