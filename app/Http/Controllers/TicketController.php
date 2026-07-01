<?php

namespace App\Http\Controllers;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Models\Appointment;
use App\Models\Ticket;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(private LegalAiService $ai)
    {
    }

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

        // الرسالة الأولى من العميل
        $m1 = $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        broadcast(new TicketMessageBroadcast($m1));

        // ردّ افتتاحي من الدعم الفني — يحلّل الطلب ويرحّب (ذكاء اصطناعي مع احتياط)
        $opening = $this->ai->reply($ticket, $details)
            ?? 'مرحباً بك، تم استلام طلبك بخصوص «'.$data['type'].'» وإحالته إلى القسم المختص. سنتابع معك خطوة بخطوة، ويمكنك الكتابة هنا في أي وقت.';
        $m2 = $ticket->messages()->create(['who' => 'ai', 'name' => LegalAiService::AGENT_NAME, 'role' => LegalAiService::AGENT_ROLE, 'body' => nl2br(e($opening)), 'time_label' => $this->clock()]);
        broadcast(new TicketMessageBroadcast($m2));

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

        // ردّ «الدعم الفني» الحقيقي عبر Claude API (مع احتياط عند تعذّر الاتصال)
        $aiText = $this->ai->reply($ticket, $data['body'])
            ?? 'تم استلام رسالتك، وسيوافيك المختص بالرد في أقرب وقت.';

        $aiMsg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => nl2br(e($aiText)),
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($aiMsg));

        $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);

        return response()->noContent();
    }

    // إرفاق مستند فعلي من العميل (رفع ملف + رسالة + بثّ)
    public function attach(Request $request, Ticket $ticket): HttpResponse
    {
        $this->authorizeTicket($request, $ticket);

        $request->validate(['file' => ['required', 'file', 'max:10240']]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $file->store("ticket-docs/{$ticket->id}");
        $ticket->increment('attachments');

        $msg = $ticket->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);

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

        $map = [
            'office' => ['label' => 'حضورية', 'ico' => 'office', 'branch' => 'الرياض — حي العليا'],
            'video' => ['label' => 'مرئية', 'ico' => 'video', 'branch' => 'اجتماع إلكتروني'],
            'phone' => ['label' => 'هاتفية', 'ico' => 'phone', 'branch' => 'مكالمة هاتفية'],
        ];
        $m = $map[$data['type']];
        // للحضورية يختار العميل الفرع؛ المرئية/الهاتفية لها قناة ثابتة
        $branch = ($data['type'] === 'office' && ! empty($data['branch'])) ? $data['branch'] : $m['branch'];
        $lawyer = ($data['lawyer'] ?? null) ?: ($ticket->assigned_lawyer ?: 'المستشار القانوني');

        $appt = Appointment::create([
            'user_id' => $ticket->user_id,
            'ticket_id' => $ticket->id,
            'ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999),
            'type' => 'استشارة '.$m['label'],
            'ico' => $m['ico'],
            'lawyer' => $lawyer,
            'day' => $data['day'],
            'time' => $data['time'],
            'branch' => $branch,
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        // بطاقة تأكيد الموعد داخل المحادثة
        $card = '<p>تم تأكيد موعد استشارتك:</p><div class="doc-list" style="flex-direction:column;align-items:stretch">'
            .'<span class="doc-chip">🗓️ '.e($appt->type).' — '.e($data['day']).' · '.e($data['time']).'</span>'
            .'<span class="doc-chip">👤 المستشار: '.e($lawyer).'</span>'
            .'<span class="doc-chip">📍 '.e($branch).' · رقم الموعد: '.e($appt->ext_id).'</span>'
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

        UserNotification::create([
            'user_id' => $ticket->user_id,
            'icon' => $m['ico'],
            'tone' => 't-green',
            'body' => "تم تأكيد موعد استشارتك ({$m['label']}) {$data['day']} الساعة {$data['time']} على التذكرة {$ticket->number}.",
            'time_label' => 'الآن',
            'is_read' => false,
        ]);

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
