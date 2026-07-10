<?php

namespace App\Http\Controllers\Employee;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\AfterResponse;
use App\Support\CaseConversion;
use App\Support\ServiceDocs;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use App\Support\TicketTriage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة الموظف — التذاكر بنطاق عام (كل العملاء) على نفس جداول العميل.
 * المحادثة مشتركة: ما يكتبه الموظف يراه العميل (عدا الملاحظات الداخلية).
 */
class TicketController extends Controller
{
    use BranchScoped;

    public function index(): Response
    {
        $tickets = Ticket::with('user')->withExists('legalCase')
            ->where('branch', $this->currentBranch())->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['converted' => (bool) $t->legal_case_exists]));

        return Inertia::render('employee/tickets', [
            'tickets' => $tickets,
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $this->guardBranch($ticket);
        $ticket->load('user');

        return Inertia::render('employee/ticketchat', [
            'ticket' => $ticket->toEmployeeCard(),
            'channel' => 'ticket.'.$ticket->id,
            // الموظف يرى كل الرسائل بما فيها الملاحظات الداخلية
            'messages' => $ticket->messages->map->toMessage(),
        ]);
    }

    // ردّ الموظف (يراه العميل ضمن نفس التذكرة) — بثّ لحظي
    public function reply(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $msg = $ticket->messages()->create([
            'who' => 'staff',
            'name' => $request->user()->name,
            'role' => 'خدمة العملاء',
            'body' => nl2br(e($data['body'])),
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);

        return response()->noContent();
    }

    // ملاحظة داخلية (لا يراها العميل) — تُبثّ على قناة الموظفين فقط
    public function note(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $msg = $ticket->messages()->create([
            'who' => 'note',
            'name' => $request->user()->name,
            'role' => 'ملاحظة داخلية',
            'body' => nl2br(e($data['body'])),
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        return response()->noContent();
    }

    // تغيير حالة التذكرة (بثّ لحظي — يتقدّم المسار لدى الطرفين)
    public function status(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate([
            'status' => ['required', 'string', 'max:60'],
            'tone' => ['required', 'string', 'max:16'],
        ]);

        $ticket->update(['status' => $data['status'], 'tone' => $data['tone']]);
        broadcast(new TicketStatusBroadcast($ticket));

        return response()->noContent();
    }

    // تنفيذ المرحلة التالية من رحلة المعالجة (تحديث الحالة + رسالة + بثّ)
    public function advance(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        // مراحل بيد المحامي/الإدارة — لا يتقدّم الموظف فيها
        if (in_array($ticket->status, ['بانتظار اعتماد المستشار', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة'], true)) {
            return response()->noContent();
        }

        // موعد مؤكد: يعقد الموظف الجلسة ويوثّق محضرها، ثم تُرفع النتيجة للمستشار
        if ($ticket->status === 'موعد مؤكد') {
            $this->conductSession($ticket);

            return response()->noContent();
        }

        $stage = TicketJourney::next($ticket->status);
        if (! $stage) {
            return response()->noContent(); // اكتملت الرحلة
        }

        // بوابة المستندات: لا تُحال للقسم قبل التأكد من إرفاق المستندات
        if ($stage['status'] === 'محالة للقسم القانوني' && $ticket->attachments < 1) {
            $ticket->update(['status' => 'بانتظار مستندات', 'tone' => 'b-amber', 'last_message' => 'بانتظار إرفاق المستندات المطلوبة', 'date_label' => 'الآن']);

            $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => 'الفريق القانوني',
                'role' => 'نواقص',
                'body' => '<p>لمساعدتنا في دراسة الطلب بشكل أدق، يرجى إرفاق المستندات التالية (حسب نوع القضية):</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => $this->clock(),
            ]);
            broadcast(new TicketMessageBroadcast($msg));
            broadcast(new TicketStatusBroadcast($ticket));

            return response()->noContent();
        }

        // الإحالة للقسم القانوني: تجهيز ملخص الملف للمستشار (AI بعد الاستجابة)، ثم انتظار اعتماده
        if ($stage['status'] === 'محالة للقسم القانوني') {
            AfterResponse::defer(fn () => TicketTriage::referToLawyer($ticket));

            return response()->noContent();
        }

        $ticket->update([
            'status' => $stage['status'],
            'tone' => $stage['tone'],
            'last_message' => $stage['msg'],
            'date_label' => 'الآن',
        ]);

        $name = match ($stage['who']) {
            'lawyer' => 'المستشار القانوني',
            'staff' => $request->user()->name,
            default => 'الفريق القانوني',
        };
        $msg = $ticket->messages()->create([
            'who' => $stage['who'],
            'name' => $name,
            'role' => $stage['role'],
            'body' => nl2br(e($stage['msg'])),
            'time_label' => $this->clock(),
        ]);

        broadcast(new TicketMessageBroadcast($msg));
        broadcast(new TicketStatusBroadcast($ticket));

        return response()->noContent();
    }

    // تحويل التذكرة المكتملة إلى قضية (يظهر للموظف بعد انتهاء الاستشارة)
    public function convertToCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardBranch($ticket);
        abort_unless($ticket->status === 'مكتملة', 422);
        abort_if($ticket->legalCase()->exists(), 409);

        CaseConversion::convert($ticket, $request->user());

        return back();
    }

    /**
     * عقد الجلسة وتوثيق محضرها (يطابق tfSession)، ثم رفع النتيجة لاعتماد المستشار.
     */
    private function conductSession(Ticket $ticket): void
    {
        $items = ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'تلخيص الجلسة', 'استخراج التوصيات', 'استخراج المهام'];
        $list = implode('', array_map(fn ($x) => '<li><span class="tick">✔</span> '.e($x).'</li>', $items));

        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => 'الفريق القانوني',
            'role' => 'محضر الجلسة',
            'body' => '<p>انعقدت الجلسة، ووثّق فريقنا محضرها:</p><ul class="checklist">'.$list.'</ul>',
            'time_label' => $this->clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        // تجهيز نتيجة الجلسة من الملخص المعتمد، ورفعها لمراجعة المستشار
        $ticket->summary?->update([
            'result' => TicketResult::compose($ticket, $ticket->summary),
            'result_status' => 'pending_lawyer',
        ]);

        $ticket->update([
            'status' => 'بانتظار اعتماد النتيجة',
            'tone' => 'b-blue',
            'last_message' => 'انتهت الجلسة، وملخصها بانتظار اعتماد المستشار',
            'date_label' => 'الآن',
        ]);
        broadcast(new TicketStatusBroadcast($ticket));
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
