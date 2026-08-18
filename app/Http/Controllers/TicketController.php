<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Jobs\GenerateTicketReplyJob;
use App\Jobs\TriageDocumentJob;
use App\Jobs\TriageTicketOnOpenJob;
use App\Mail\TicketOpenedMail;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use App\Support\TicketTriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    // امتدادات المستندات المسموح رفعها من العميل (عقود ممسوحة/صور هوية/مستندات نصّية) — لا تنفيذية/مضغوطة
    private const ALLOWED_DOC_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    public function __construct(private LegalAiService $ai) {}

    // قائمة تذاكر العميل الحالي
    public function index(Request $request): Response
    {
        $tickets = $request->user()->tickets()->latest('id')->get()
            ->map(fn (Ticket $t) => $t->toCard());

        return Inertia::render('tickets', [
            'tickets' => $tickets,
        ]);
    }

    // فتح تذكرة جديدة (تُخزَّن وتظهر للعميل والموظف)
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:190'],
            'department' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:5000'],
            'opponent_name' => ['nullable', 'string', 'max:190'],
            'opponent_id' => ['nullable', 'string', 'max:60'],
            'claim_amount' => ['nullable', 'integer', 'min:0'],
            'court_name' => ['nullable', 'string', 'max:190'],
            'priority' => ['nullable', 'string', 'max:20'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:'.self::ALLOWED_DOC_MIMES], // حتى 10MB لكل ملف
        ]);

        $details = trim($data['details'] ?? '') ?: ('طلب جديد بخصوص: '.$data['type']);
        $year = now()->year;
        do {
            $number = "SB-{$year}-".random_int(1000, 9999);
        } while (Ticket::where('number', $number)->exists());

        $ticket = $request->user()->tickets()->create([
            'number' => $number,
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'department' => $data['department'] ?? null,
            'opponent_name' => $data['opponent_name'] ?? null,
            'opponent_id' => $data['opponent_id'] ?? null,
            'claim_amount' => $data['claim_amount'] ?? null,
            'court_name' => $data['court_name'] ?? null,
            'priority' => $data['priority'] ?? 'متوسطة',
            'status' => 'قيد التحليل',
            'tone' => TicketJourney::toneFor('قيد التحليل'),
            'last_message' => $details,
            'date_label' => 'الآن',
        ]);

        // الإسناد الأول (حتمي وفوري): الذكاء الاصطناعي يختار المحامي المختص ومنه يُختم فرع التذكرة،
        // فيراها موظف الفرع منذ الاستقبال ويُعزل عنها موظفو الفروع الأخرى.
        TicketAssignment::assign($ticket);

        // الرسالة الأولى من العميل
        $m1 = $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        Live::push(new TicketMessageBroadcast($m1));

        // المستندات الداعمة المرفوعة مع الطلب (إن وُجدت) — تُخزَّن فقط هنا؛ تحليلها يتولّاه
        // مسار الفتح (TicketTriage::onOpened) ضمن قرار واحد واعٍ بالمرفقات (لا فحص منفصل يسبق الترحيب).
        foreach ($data['files'] ?? [] as $file) {
            $path = $file->store("ticket-docs/{$ticket->id}");
            $ticket->increment('attachments');
            $ticket->documents()->create([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'status' => 'قيد الفحص',
            ]);
        }

        $type = $data['type'];
        TriageTicketOnOpenJob::dispatch($ticket, $details, $type);

        // تنبيهات فتح التذكرة: داخلي (موظفو فرعها + الإدارة العليا) + بريد (العميل والموظفين والإدارة)
        $this->notifyTicketOpened($ticket->fresh(), $request->user());

        return redirect()->route('tickets.show', $ticket);
    }

    /**
     * تنبيهات فتح التذكرة — كانت التذكرة الجديدة تصل صامتةً: لا يعلم بها الموظف/الإدارة
     * إلا بتصفّح القائمة، ولا يصل العميل تأكيد استلام. أفضل-جهد بعد الإسناد (الفرع مختوم).
     */
    private function notifyTicketOpened(Ticket $ticket, User $client): void
    {
        $mail = app(MailService::class);

        // العميل: تأكيد استلام (بريد فقط — المحادثة نفسها أمامه)
        $mail->send($client, new TicketOpenedMail($ticket, 'client'));

        // موظفو فرع التذكرة (بلا فرع = كل الموظفين — مجمّع الاستقبال المشترك): إشعار داخلي + بريد
        $employees = User::where('role', Role::Employee)->where('status', 'active')
            ->when($ticket->branch, fn ($q) => $q->where('branch', $ticket->branch))
            ->get();
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
            'messages' => $messages->map->toMessage(),
            'consult' => $consult?->toClientCard(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق (بثّ لحظي بلا إعادة تحميل)
    public function storeMessage(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        if (in_array($ticket->status, ['مكتملة', 'مغلقة'], true)) {
            throw ValidationException::withMessages([
                'body' => 'لا يمكن إرسال رسائل على تذكرة مكتملة أو مغلقة.',
            ]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
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

        // ردّ «الدعم الفني» عبر AI بعد إرسال الاستجابة (يصل للعميل بالبث اللحظي)
        $body = $data['body'];
        GenerateTicketReplyJob::dispatch($ticket, $body);

        return response()->noContent();
    }

    // إرفاق مستند فعلي من العميل (رفع ملف + رسالة + بثّ)
    public function attach(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

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
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);

        TriageDocumentJob::dispatch($ticket, $doc);

        return response()->noContent();
    }

    // تفرّغ المستشارين المتخصّصين بقسم التذكرة (JSON) — يغذّي مُنتقي الحجز داخل التذكرة.
    public function availability(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['date' => ['nullable', 'date']]);

        $day = LawyerAvailability::resolveDate($data['date'] ?? null);
        // التخصّص من قسم التذكرة (مصدر الخادم، لا تلاعب) — يجسر Specialties::normalize صياغات SVC
        $lawyers = LawyerAvailability::rankedSpecialists($ticket->department ?: '', $ticket->type, $day->toDateString());

        return response()->json(['date' => $day->toDateString(), 'lawyers' => $lawyers]);
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
        if (TicketJourney::indexOf($ticket->status) > TicketJourney::indexOf('موعد مؤكد')
            || in_array($ticket->status, ['مكتملة', 'مغلقة', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة'], true)) {
            throw ValidationException::withMessages([
                'type' => 'لا يمكن طلب الحجز من الحالة الحالية.',
            ]);
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
            'body' => "تم إرسال طلب استشارة ({$m['label']}) للمكتب لتحديد السعر. ستصلك الفاتورة لسدادها ثم اختيار الموعد.",
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
