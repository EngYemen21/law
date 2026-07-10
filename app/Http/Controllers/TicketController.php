<?php

namespace App\Http\Controllers;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Services\LegalAiService;
use App\Support\AfterResponse;
use App\Support\ConsultBooking;
use App\Support\TicketAssignment;
use App\Support\TicketTriage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
        $number = 'SB-'.now()->year.'-'.random_int(1000, 9999);

        $ticket = $request->user()->tickets()->create([
            'number' => $number,
            'type' => $data['type'],
            'department' => $data['department'] ?? null,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'last_message' => $details,
            'date_label' => 'الآن',
        ]);

        // الإسناد الأول (حتمي وفوري): الذكاء الاصطناعي يختار المحامي المختص ومنه يُختم فرع التذكرة،
        // فيراها موظف الفرع منذ الاستقبال ويُعزل عنها موظفو الفروع الأخرى.
        TicketAssignment::assign($ticket);

        // الرسالة الأولى من العميل
        $m1 = $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        broadcast(new TicketMessageBroadcast($m1));

        // ترحيب واحد بعد إرسال الاستجابة (يصل بالبث اللحظي) — بلا فيضان رسائل
        $type = $data['type'];
        AfterResponse::defer(function () use ($ticket, $details, $type) {
            if (TicketTriage::enabled()) {
                // الوكيل التشغيلي: تصنيف صامت + رسالة ترحيب إنسانية واحدة تطلب المستندات
                TicketTriage::onOpened($ticket, $details);

                return;
            }

            // الوكيل معطّل: ردّ ترحيبي واحد من خدمة العملاء
            $opening = app(LegalAiService::class)->reply($ticket, $details)
                ?? 'مرحباً بك، تم استلام طلبك بخصوص «'.$type.'» وإحالته إلى القسم المختص. سنتابع معك خطوة بخطوة، ويمكنك الكتابة هنا في أي وقت.';
            $m2 = $ticket->messages()->create(['who' => 'ai', 'name' => LegalAiService::AGENT_NAME, 'role' => LegalAiService::AGENT_ROLE, 'body' => nl2br(e($opening)), 'time_label' => $this->clock()]);
            broadcast(new TicketMessageBroadcast($m2));
        });

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
        broadcast(new TicketMessageBroadcast($clientMsg));

        $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);

        // الوكيل التشغيلي: كشف الشكوى/الاستعجال (حتمي وسريع) → تصعيد داخلي للموظفين
        TicketTriage::onClientMessage($ticket, $data['body']);

        // ردّ «الدعم الفني» عبر AI بعد إرسال الاستجابة (يصل للعميل بالبث اللحظي)
        $body = $data['body'];
        AfterResponse::defer(function () use ($ticket, $body) {
            $aiText = app(LegalAiService::class)->reply($ticket, $body)
                ?? 'تم استلام رسالتك، وسيوافيك المختص بالرد في أقرب وقت.';

            $aiMsg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => LegalAiService::AGENT_ROLE,
                'body' => nl2br(e($aiText)),
                'time_label' => $this->clock(),
            ]);
            broadcast(new TicketMessageBroadcast($aiMsg));
        });

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
        broadcast(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);

        // الوكيل التشغيلي: فحص محتوى المستند وارتباطه — بعد إرسال الاستجابة (فحص AI قد يطول)
        AfterResponse::defer(fn () => TicketTriage::onDocumentAttached($ticket, $doc));

        return response()->noContent();
    }

    // حجز موعد استشارة من داخل محادثة التذكرة (يُنشئ موعداً حقيقياً + يؤكّد المسار)
    public function book(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['required', 'string', 'max:32'],
            'branch' => ['nullable', 'string', 'max:80'],
            'lawyer' => ['nullable', 'string', 'max:80'],
        ]);

        // إنشاء الحجز الحقيقي (Appointment + Consult + Zoom + إشعار) عبر المصدر الموحّد
        $consult = ConsultBooking::create($request->user(), $data, $ticket);
        $m = ConsultBooking::meta($data['type']);

        // بطاقة تأكيد الموعد داخل المحادثة (رابط Zoom الحقيقي إن توفّر)
        $joinUrl = $consult->joinLink();
        $slink = $data['type'] === 'video'
            ? '<span class="doc-chip">🔗 رابط جلسة Zoom: '.e($joinUrl).'</span>'
            : '';
        $card = '<p>تم تأكيد موعد استشارتك:</p><div class="doc-list" style="flex-direction:column;align-items:stretch">'
            .'<span class="doc-chip">🗓️ استشارة '.e($m['label']).' — '.e($data['day']).' · '.e($data['time']).'</span>'
            .'<span class="doc-chip">👤 المستشار: '.e($consult->lawyer).'</span>'
            .'<span class="doc-chip">📍 '.e($consult->branch ?: $m['branch']).' · رقم الاستشارة: '.e($consult->ref).'</span>'
            .$slink
            .'</div>';

        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'مواعيد',
            'body' => $card,
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        $ticket->update([
            'status' => 'موعد مؤكد',
            'tone' => 'b-green',
            'last_message' => 'تم تأكيد موعد الاستشارة: '.$data['day'].' · '.$data['time'],
            'date_label' => 'الآن',
        ]);
        broadcast(new TicketStatusBroadcast($ticket));

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
