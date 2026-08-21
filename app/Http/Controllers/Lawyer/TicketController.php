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
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Support\CaseConversion;
use App\Support\Live;
use App\Support\Notify;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use App\Support\ServiceDocs;
use App\Support\TicketJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
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

    // التذاكر المحالة للمستشار (المسندة إليه) — بما فيها ما لم يُنتَج ملخصه بعد.
    // كان whereHas('summary') يُخفيها كلياً حتى تنتهي المهمة الخلفية، فيراها الموظف ولا يراها هو.
    public function index(Request $request): Response
    {
        $tickets = Ticket::with(['user', 'summary'])->withExists('legalCase')
            ->where('assigned_lawyer_id', $request->user()->id)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), [
                'converted' => (bool) $t->legal_case_exists,
                'awaitingSummary' => $t->summary === null,
            ]));

        return Inertia::render('lawyer/tickets', ['tickets' => $tickets]);
    }

    // لوحة المحامي — مؤشرات حقيقية بالكامل
    public function dashboard(Request $request): Response
    {
        $lawyerId = $request->user()->id;
        // التذاكر المحالة لهذا المحامي (المسندة إليه، بملخص أو بانتظاره)
        $tickets = Ticket::with(['user', 'summary'])
            ->where('assigned_lawyer_id', $lawyerId)->latest('id')->get();

        return Inertia::render('lawyer/dashboard', [
            'tickets' => $tickets->map(fn (Ticket $t) => $t->toEmployeeCard()),
            'pendingSummaries' => $tickets->where('summary.status', 'awaiting_lawyer')->count(),
            // created_by عمود نصّي (اسم) — مقارنته بالمعرّف كانت تُصفّر العدّاد لكل محامٍ،
            // والحالة المخزّنة لا تتحدّث بمرور الوقت — الاشتقاق الحي هو الفيصل
            'openMeetings' => Meeting::where(fn ($q) => $q->where('assigned_lawyer_id', $lawyerId)
                ->orWhere('created_by', $request->user()->name))
                ->get()->filter(fn (Meeting $m) => $m->isUpcoming())->count(),
            'openTasks' => Task::where('assigned_to', $lawyerId)->where('status', '!=', 'منجزة')->count(),
            // المتأخرة وحدها (تجاوزت استحقاقها) — كانت غائبة فلا يميّز المحامي العاجل من المفتوح
            'overdueTasks' => Task::where('assigned_to', $lawyerId)->where('status', '!=', 'منجزة')
                ->get()->filter(fn (Task $t) => $t->isOverdue())->count(),
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
            'messages' => $ticket->messages->map->toMessage(),
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
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

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
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

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

    // توليد مسودة لائحة دعوى لمعايير منصة ناجز
    public function generateNajizDraft(Request $request, Ticket $ticket, LegalAiService $ai): JsonResponse
    {
        $this->guardAssigned($ticket);
        $draft = $ai->generateNajizDraft($ticket);

        return response()->json(['draft' => $draft]);
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

        $doc = [
            'title' => 'تقرير دراسة الملف والرأي القانوني المبدئي',
            'subtitle' => "التذكرة: {$ticket->number} · {$ticket->type}",
            'ref' => "REF-{$ticket->number}",
            'blocks' => [
                [
                    'title' => 'بيانات القضية والموكل',
                    'cellRows' => [
                        [['رقم التذكرة', $ticket->number], ['اسم العميل', $clientName]],
                        [['نوع القضية', $ticket->type], ['القسم', $ticket->department ?: '—']],
                        [['المستشار المسؤول', $lawyerName], ['حالة الدراسة', $summary->isApproved() ? 'معتمد رسمياً' : 'قيد الدراسة']],
                    ],
                ],
                [
                    'title' => 'أولاً: ملخص موضوع النزاع',
                    'lines' => $summary->case_summary ?: ($ticket->details ?: '—'),
                ],
                [
                    'title' => 'ثانياً: فحص المرفقات والمستندات الثبوتية',
                    'lines' => $summary->attachments_summary ?: 'تم فحص المرفقات ومطابقتها وفق نظام الإثبات السعودي.',
                ],
                [
                    'title' => 'ثالثاً: سرد الوقائع التعاقدية والإجرائية',
                    'lines' => $summary->facts ?: '—',
                ],
                [
                    'title' => 'رابعاً: الرأي القانوني المعتمد والتوصيات',
                    'lines' => $summary->key_points ?: 'تم اعتماد الدراسة وإصدار التوصية بالمتابعة.',
                ],
            ],
            'approval' => [
                'qrSeed' => "https://salaselbabel.net/verify?ref={$ticket->number}&approved=1",
                'rows' => [
                    ['المستشار المعتمد', $lawyerName],
                    ['تاريخ الاعتماد', $summary->approved_at ? $summary->approved_at->format('Y-m-d H:i') : date('Y-m-d H:i')],
                    ['الاعتماد الإلكتروني', 'موثق ومعتمد برقم مرجعي'],
                ],
            ],
            'note' => 'إشعار سرية: هذا التقرير صادر إلكترونياً من النظام الإداري لمكاتب المحاماة ويخضع للسرية المهنية والمصادقة المعتمدة.',
            'footer' => 'النظام الإداري لمكاتب المحاماة — منظومة المحاماة والاستشارات القانونية بالمملكة العربية السعودية',
        ];

        $html = ReportPrint::html($doc);

        return PdfRenderer::render($html, 'Summary-'.$ticket->number.'.pdf');
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
