<?php

namespace App\Http\Controllers;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Jobs\GenerateTicketReplyJob;
use App\Jobs\TriageDocumentJob;
use App\Jobs\TriageTicketOnOpenJob;
use App\Mail\TicketOpenedMail;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\ActiveLegalDepartment;
use App\Rules\ServiceOfDepartment;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\ConsultBooking;
use App\Support\ConversationFiles;
use App\Support\LawyerAvailability;
use App\Support\LegalCatalogue;
use App\Support\Live;
use App\Support\Notify;
use App\Support\ReferenceNumber;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use App\Support\TicketTriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    // امتدادات المستندات المسموح رفعها من العميل (عقود ممسوحة/صور هوية/مستندات نصّية) — لا تنفيذية/مضغوطة
    private const ALLOWED_DOC_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    // الحقن بلا استعمال: صفر مرجع لـ$this->ai في الملفّ كلّه (انتقل التحليل إلى
    // TriageTicketOnOpenJob). يُعلَّق لا يُحذف — إن عاد نداء AI مباشر فهذا موضعه.
    // public function __construct(private LegalAiService $ai) {}

    // قائمة تذاكر العميل الحالي
    public function index(Request $request): Response
    {
        $ticketsRaw = $request->user()->tickets()
            ->with(['assignedLawyer', 'documents', 'legalCase'])
            ->latest('id')
            ->get();

        $tickets = $ticketsRaw->map(fn (Ticket $t) => $t->toCard());

        $counts = [
            'total' => $tickets->count(),
            'active' => $tickets->where('isTerminal', false)->count(),
            'needsAction' => $tickets->filter(fn ($t) => ! empty($t['needsDoc']) || ! empty($t['needsBooking']))->count(),
            // من مجموعة التبويب نفسها (`TicketJourney::CLIENT_PHASES`) — العدّاد يعدّ ما يعرضه تبويبه
            'inAnalysis' => $tickets->where('phase', 'analysis')->count(),
            'inOpinion' => $tickets->where('phase', 'opinion')->count(),
            'completed' => $tickets->where('isTerminal', true)->count(),
        ];

        return Inertia::render('tickets', [
            'tickets' => $tickets,
            'counts' => $counts,
            // تسميات العميل لا كتالوج المكتب؛ وحالةُ صفٍّ قديم لديه تبقى خياراً فلا يتعذّر ترشيحه
            'availableStatuses' => array_values(array_unique(array_merge(
                TicketStatus::clientLabels(),
                $tickets->pluck('status')->all()
            ))),
        ]);
    }

    // فتح تذكرة جديدة (تُخزَّن وتظهر للعميل والموظف)
    /** نموذج فتح تذكرة — الأقسام والخدمات الفعّالة من الكتالوج (لا قائمة ثابتة في الواجهة). */
    public function create(): Response
    {
        return Inertia::render('newticket', [
            'catalogue' => LegalCatalogue::forSelect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // القسم والخدمة من كتالوج الأقسام: النموذج يرسل المعرّفين، والخادم يتحقّق أنّ الخدمة
            // تتبع قسمها وأنّ كليهما فعّال. الاسم النصّيّ مقبولٌ لمن يرسله بشرط أن يطابق قسماً فعّالاً —
            // كان يُقبل أيّ نصٍّ فيُحفظ قسمٌ لا يعرفه الإسناد ولا المرشّحات.
            'department_id' => ['nullable', 'integer', new ActiveLegalDepartment],
            'service_id' => ['nullable', 'integer', 'required_with:department_id', new ServiceOfDepartment($request->input('department_id'))],
            'type' => ['required_without:service_id', 'nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:190'],
            'department' => ['nullable', 'string', 'max:120', new ActiveLegalDepartment],
            'details' => ['nullable', 'string'],
            'opponent_name' => ['nullable', 'string', 'max:190'],
            'opponent_id' => ['nullable', 'string', 'max:60'],
            'claim_amount' => ['nullable', 'integer', 'min:0'],
            'court_name' => ['nullable', 'string', 'max:190'],
            // من الكتالوج لا نصّاً حرّاً: `max:20` كان يقبل أيّ مفردة فتدخل القاعدة قيمةٌ لا يعرفها مرشّح
            'priority' => ['nullable', 'string', Rule::in(TicketJourney::PRIORITIES)],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:'.self::ALLOWED_DOC_MIMES], // حتى 10MB لكل ملف
        ], [
            'service_id.required_with' => 'اختر الخدمة المتعلقة بالتذكرة.',
            'type.required_without' => 'اختر الخدمة المتعلقة بالتذكرة.',
        ]);

        // الاسم المعتمد في الكتالوج يُحفظ نصّاً مع معرّفه — فتعرض البطاقات والمرشّحات قسماً واحداً بصياغةٍ واحدة
        $department = LegalCatalogue::fromInput($data['department_id'] ?? $data['department'] ?? null);
        $service = LegalCatalogue::service(isset($data['service_id']) ? (int) $data['service_id'] : null);
        $data['type'] = $service !== null ? $service->name : $data['type'];

        $details = trim($data['details'] ?? '') ?: ('طلب جديد بخصوص: '.$data['type']);
        // المولّد الموحّد (بالشكل نفسه SB-YYYY-NNNN) — كانت حلقةٌ هنا تكرّر منطقه بلا سقف
        $number = ReferenceNumber::next(Ticket::class, 'number', 'SB');

        // ميلاد التذكرة أوّل سطرٍ في رحلتها — يُفتح بالمحرّك بحالته الأولى وباسم العميل الفاتح
        $ticket = Workflow::open('ticket.opened', fn () => $request->user()->tickets()->create([
            'number' => $number,
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'department' => $department?->name,
            'legal_department_id' => $department?->id,
            'legal_service_id' => $service?->id,
            'opponent_name' => $data['opponent_name'] ?? null,
            'opponent_id' => $data['opponent_id'] ?? null,
            'claim_amount' => $data['claim_amount'] ?? null,
            'court_name' => $data['court_name'] ?? null,
            'priority' => $data['priority'] ?? 'متوسطة',
            'status' => 'قيد التحليل',
            'tone' => TicketJourney::toneFor('قيد التحليل'),
            'last_message' => $details,
            'date_label' => 'الآن',
        ]), $request->user());

        /*
         * **لا إسناد تلقائيّ عند الفتح** (قرار المالك 2026-09-20): الإسناد قرارٌ بشريّ — الموظّف من
         * شاشة «تحويل التذاكر»، أو الإدارة من شاشة «توزيع التذاكر». كان النظام يختار المحامي المختصّ
         * لحظة الفتح، فيصل الملفّ محاميّاً لم يره أحد.
         *
         * ولا تُنسى تذكرة: إشعار الفتح أدناه يصل كلّ الموظّفين والإدارة، ومهمّة التصعيد الدوريّة
         * تُسندها للإدارة العليا إن بقيت بلا محامٍ بعد المهلة المضبوطة في الإعدادات (ساعتان).
         */

        // الرسالة الأولى من العميل
        $m1 = $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        Live::push(new TicketMessageBroadcast($m1));

        // المستندات الداعمة المرفوعة مع الطلب (إن وُجدت) — تُخزَّن فقط هنا؛ تحليلها يتولّاه
        // مسار الفتح (TicketTriage::onOpened) ضمن قرار واحد واعٍ بالمرفقات (لا فحص منفصل يسبق الترحيب).
        $opened = [];
        foreach ($data['files'] ?? [] as $file) {
            $path = $file->store("ticket-docs/{$ticket->id}");
            $ticket->increment('attachments');
            $opened[] = $ticket->documents()->create([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'status' => 'قيد الفحص',
            ]);
        }

        /*
         * **ما أرفقه العميل عند الفتح يظهر في محادثته** — كان يُخزَّن صفّاً في `ticket_documents`
         * بلا رسالة، فلا يراه أحد في المحادثة: لا العميل ولا الموظّف ولا المستشار (ولا شاشة
         * للطاقم تعرضه أصلاً)، فيُطلب من العميل مستندٌ أرسله فعلاً.
         *
         * رسالةٌ واحدة تجمع المرفقات بشاراتٍ قابلة للتنزيل — نظير `attach()` للإرفاق اللاحق،
         * وبالرابط نفسه الذي يحكم إذنه `ConversationFiles`. وموضعها هنا مقصود: **بعد** رسالة
         * العميل و**قبل** ترحيب الوكيل (يكتبه `TriageTicketOnOpenJob` المُرسَل أدناه)، فيقرأ
         * الترحيبُ سياقَ ما أرسله العميل وأرفقه قبله.
         */
        if ($opened !== []) {
            $chips = implode('', array_map(fn ($doc) => ConversationFiles::chip('ticket', $doc), $opened));
            $attached = $ticket->messages()->create([
                'who' => 'client',
                'name' => 'أنت',
                'role' => 'العميل',
                'body' => '<p>'.(count($opened) > 1 ? 'تم إرفاق المستندات:' : 'تم إرفاق مستند:').'</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => $this->clock(),
            ]);
            Live::push(new TicketMessageBroadcast($attached));
        }

        $type = $data['type'];
        TriageTicketOnOpenJob::dispatch($ticket, $details, $type);

        // تنبيهات فتح التذكرة: داخلي (موظفو المكتب + الإدارة العليا) + بريد (العميل والموظفين والإدارة)
        $this->notifyTicketOpened($ticket->fresh(), $request->user());

        Audit::log(
            action: 'فتح تذكرة',
            description: "فتح العميل {$request->user()->name} التذكرة {$ticket->number} — {$type}.",
            category: 'تذاكر',
            auditable: $ticket,
            auditableRef: $ticket->number,
        );

        // الفتح لا يُسنِد أحداً (قرار 2026-09-20) — إلّا ألّا يكون في المكتب محامٍ أصلاً، فالإدارة
        // العليا صاحبة الملفّ من لحظته بدل أن يبقى بلا صاحبٍ حتى انقضاء المهلة (سلسلة المالك 2026-09-25)
        TicketAssignment::escalateIfNoLawyer($ticket);

        return redirect()->route('tickets.show', $ticket);
    }

    /**
     * تنبيهات فتح التذكرة — كانت التذكرة الجديدة تصل صامتةً: لا يعلم بها الموظف/الإدارة
     * إلا بتصفّح القائمة، ولا يصل العميل تأكيد استلام. أفضل-جهد بعد الإسناد.
     */
    private function notifyTicketOpened(Ticket $ticket, User $client): void
    {
        $mail = app(MailService::class);

        // العميل: تأكيد استلام (بريد فقط — المحادثة نفسها أمامه)
        $mail->send($client, new TicketOpenedMail($ticket, 'client'));

        // موظفو المكتب النشطون (مجمّع الاستقبال المشترك): إشعار داخلي + بريد
        $employees = User::where('role', Role::Employee)->where('status', 'active')->get();
        foreach ($employees as $employee) {
            Notify::send($employee->id, 'folder', 't-blue', "تذكرة جديدة {$ticket->number} من العميل — {$ticket->type}. يُرجى المتابعة من لوحة التذاكر.");
        }
        if ($employees->isNotEmpty()) {
            $mail->send($employees->all(), new TicketOpenedMail($ticket, 'employee'));
        }

        // الإدارة العليا: إشعار داخلي + بريد
        $admins = User::where('role', Role::Admin)->get();
        foreach ($admins as $admin) {
            Notify::send($admin->id, 'folder', 't-blue', "تذكرة جديدة {$ticket->number} من العميل — {$ticket->type}.");
        }
        if ($admins->isNotEmpty()) {
            $mail->send($admins->all(), new TicketOpenedMail($ticket, 'admin'));
        }
    }

    // محادثة تذكرة واحدة
    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorizeTicket($request, $ticket);

        // العميل لا يرى الملاحظات الداخلية للموظفين
        $messages = $ticket->messages()->where('who', '!=', 'note')->orderBy('id')->get();

        // الاستشارة المرتبطة الأحدث (تُغذّي معالج الحجز: تسعير → فاتورة → دفع → موعد)
        $consult = $ticket->consults()->with('invoice')->latest('id')->first();

        return Inertia::render('ticketchat', [
            'ticket' => $ticket->toCard(),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => ConversationFiles::linkLegacyChips($messages->map(fn (TicketMessage $m) => $m->toMessage(forClient: true))->all(), 'ticket', $ticket->documents),
            'consult' => $consult?->toClientCard(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق (بثّ لحظي بلا إعادة تحميل)
    public function storeMessage(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        if ($ticket->isTerminal() || $ticket->is_frozen) {
            throw ValidationException::withMessages([
                'body' => 'لا يمكن إرسال رسائل على تذكرة مكتملة أو مغلقة.',
            ]);
        }

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $clientMsg = $ticket->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => e($data['body']),
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($clientMsg));

        $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);

        // الوكيل التشغيلي: كشف الشكوى/الاستعجال (حتمي وسريع) → تصعيد داخلي للموظفين
        TicketTriage::onClientMessage($ticket, $data['body']);

        // ردّ «الدعم الفني» عبر AI بعد إرسال الاستجابة (للعميل فقط — يمنع الرد على موظف أو محامٍ أو إدارة)
        if ($request->user()->isClient()) {
            $body = $data['body'];
            GenerateTicketReplyJob::dispatch($ticket, $body);
        }

        return response()->noContent();
    }

    // إرفاق مستند فعلي من العميل (رفع ملف + رسالة + بثّ)
    public function attach(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        if ($ticket->isTerminal() || $ticket->is_frozen) {
            throw ValidationException::withMessages([
                'file' => 'لا يمكن إرفاق مستندات على تذكرة مكتملة أو مغلقة.',
            ]);
        }

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:'.self::ALLOWED_DOC_MIMES]]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $path = $file->store("ticket-docs/{$ticket->id}");
        $ticket->increment('attachments');

        // سجل المستند — يحمل المسار الفعلي ونتيجة الفحص الذكي لاحقاً
        $doc = $ticket->documents()->create([
            'name' => $name,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'status' => 'قيد الفحص',
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list">'.ConversationFiles::chip('ticket', $doc).'</div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);

        if ($request->user()->isClient()) {
            TriageDocumentJob::dispatch($ticket, $doc);
        }

        return response()->noContent();
    }

    // أوقات التفرّغ بقسم التذكرة (JSON) للعميل — يغذّي منتقي الوقت؛ والإسناد بعد التأكيد لا باختياره.
    public function availability(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $day = LawyerAvailability::resolveDate($data['date'] ?? null);

        // التخصّص من قسم التذكرة (مصدر الخادم، لا تلاعب) — يجسر Specialties::normalize صياغات SVC.
        // والمخرَج للعميل: الأوقات وحدها بلا هويّة محامٍ ولا أداء (انظر `clientSlots`)
        return response()->json(array_merge(
            ['date' => $day->toDateString()],
            LawyerAvailability::clientSlots($ticket->department ?: '', $ticket->type, $day->toDateString()),
        ));
    }

    // الخطوة 1 من الحجز: طلب استشارة (النوع فقط) من داخل محادثة التذكرة — يُرسل للتسعير،
    // ولا يُنشئ موعداً بعد. (الفاتورة → الدفع → اختيار الموعد تتمّ لاحقاً عبر مسارات consults.*)
    public function book(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
        ]);

        // حارسة الرحلة: لا يُطلب حجز على تذكرة اكتملت/أُغلقت أو في مرحلة اعتماد نهائية.
        // مصدرٌ واحد لكلّ مداخل طلب الاستشارة (ع١٣، ع١٧)
        if ($why = TicketJourney::consultRequestBlocker($ticket)) {
            throw ValidationException::withMessages(['type' => $why]);
        }

        // منع طلبات التسعير المتكرّرة: طلب واحد قائم لكل تذكرة يكفي حتى يكتمل أو يُلغى
        if ($ticket->consults()->whereIn('status', Consult::PRE_SESSION_STATUSES)->exists()) {
            throw ValidationException::withMessages([
                'type' => 'يوجد طلب استشارة قائم لهذه التذكرة.',
            ]);
        }

        $m = ConsultBooking::meta($data['type']);
        ConsultBooking::request($request->user(), ['type' => $data['type']], $ticket);

        // ملاحظة في المحادثة بأن الطلب أُرسل للتسعير (الرحلة تُكمل عبر «استشاراتي» وبطاقة التذكرة)
        $msg = $ticket->messages()->create([
            'who' => 'system',
            'name' => 'النظام',
            'role' => 'نظام',
            'body' => "تم إرسال طلب استشارة ({$m['label']}) للمكتب لتحديد السعر. ستصلك الفاتورة لسدادها، ثمّ يُحدَّد موعد جلستك ويصلك إشعار به.",
            'time_label' => $this->clock(),
        ]);

        $ticket->update(['last_message' => 'طلب تسعير استشارة '.$m['label'], 'date_label' => 'الآن']);

        Live::push(new TicketMessageBroadcast($msg));

        return response()->noContent();
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->user_id === $request->user()->id, 403);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
