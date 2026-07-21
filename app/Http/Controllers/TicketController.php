<?php

namespace App\Http\Controllers;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Jobs\GenerateTicketReplyJob;
use App\Jobs\TriageDocumentJob;
use App\Jobs\TriageTicketOnOpenJob;
use App\Models\Ticket;
use App\Rules\LawyerInBranch;
use App\Services\LegalAiService;
use App\Support\AppointmentCard;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use App\Support\TicketTriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
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
            'department' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $details = trim($data['details'] ?? '') ?: ('طلب جديد بخصوص: '.$data['type']);
        $year = now()->year;
        do {
            $number = "SB-{$year}-".random_int(1000, 9999);
        } while (Ticket::where('number', $number)->exists());

        $ticket = $request->user()->tickets()->create([
            'number' => $number,
            'type' => $data['type'],
            'department' => $data['department'] ?? null,
            'status' => 'قيد الدراسة',
            'tone' => TicketJourney::toneFor('قيد الدراسة'),
            'last_message' => $details,
            'date_label' => 'الآن',
        ]);

        // الإسناد الأول (حتمي وفوري): الذكاء الاصطناعي يختار المحامي المختص ومنه يُختم فرع التذكرة،
        // فيراها موظف الفرع منذ الاستقبال ويُعزل عنها موظفو الفروع الأخرى.
        TicketAssignment::assign($ticket);

        // الرسالة الأولى من العميل
        $m1 = $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        Live::push(new TicketMessageBroadcast($m1));

        $type = $data['type'];
        TriageTicketOnOpenJob::dispatch($ticket, $details, $type);

        return redirect()->route('tickets.show', $ticket);
    }

    // محادثة تذكرة واحدة
    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorizeTicket($request, $ticket);

        // العميل لا يرى الملاحظات الداخلية للموظفين
        $messages = $ticket->messages()->where('who', '!=', 'note')->orderBy('id')->get();

        return Inertia::render('ticketchat', [
            'ticket' => $ticket->toCard(),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => $messages->map->toMessage(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق (بثّ لحظي بلا إعادة تحميل)
    public function storeMessage(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        abort_if(
            in_array($ticket->status, ['مكتملة', 'مغلقة'], true),
            422,
            'لا يمكن إرسال رسائل على تذكرة مكتملة أو مغلقة.'
        );

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

        $request->validate(['file' => ['required', 'file', 'max:10240']]); // حتى 10MB

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

    // حجز موعد استشارة من داخل محادثة التذكرة (يُنشئ موعداً حقيقياً + يؤكّد المسار)
    public function book(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            // العميل يختار أي محامٍ نشط (بلا تقييد بفرع) — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['required', 'integer', new LawyerInBranch],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        // حارسة الرحلة: لا يمكن تأكيد موعد على تذكرة اكتملت/أُغلقت أو في مرحلة اعتماد نهائية.
        // (يختلف هذا عن canTransition الذي صُمِّم لمنع التقدّم اليدوي عبر قائمة الحالة؛
        // هنا نقبل الحجز من كل المراحل حتى الجلسة، ونرفضه فقط من المراحل اللاحقة.)
        // نُجرى الفحص قبل إنشاء الحجز لتجنّب إهدار موارد Zoom وقاعدة البيانات.
        abort_if(
            TicketJourney::indexOf($ticket->status) > TicketJourney::indexOf('موعد مؤكد')
            || in_array($ticket->status, ['مكتملة', 'مغلقة', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة'], true),
            422,
            'لا يمكن تأكيد الموعد من الحالة الحالية.'
        );

        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        // إنشاء الحجز الحقيقي (Appointment + Consult + Zoom + إشعار) عبر المصدر الموحّد:
        // المستشار المختار (lawyer_id) + وقت حقيقي يُفعّل حارس منع الحجز المزدوج
        $consult = ConsultBooking::create($request->user(), [
            'type' => $data['type'],
            'lawyer_id' => (int) $data['lawyer_id'],
            'starts_at' => $startsAt->toDateTimeString(),
            'duration' => LawyerAvailability::slotMinutes(),
            'day' => $startsAt->format('Y-m-d'),
            'time' => $data['time'],
        ], $ticket);
        $m = ConsultBooking::meta($data['type']);

        // بطاقة تأكيد الموعد داخل المحادثة (تصميم .appt الأصلي + رابط Zoom الحقيقي إن توفّر)
        $card = AppointmentCard::render($consult, $m);

        // كل كتابات قاعدة البيانات أولاً، ثم البثّ — البثّ أثر جانبي لا يجوز أن يتخلّل الحالة
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'مواعيد',
            'body' => $card,
            'time_label' => $this->clock(),
        ]);

        $ticket->update([
            'status' => 'موعد مؤكد',
            'tone' => TicketJourney::toneFor('موعد مؤكد'),
            'last_message' => 'تم تأكيد موعد الاستشارة: '.$consult->day.' · '.$consult->time,
            'date_label' => 'الآن',
        ]);

        Live::push(new TicketMessageBroadcast($msg), new TicketStatusBroadcast($ticket));

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
