<?php

namespace App\Http\Controllers\Employee;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Services\LegalAiService;
use App\Support\CaseConversion;
use App\Support\Live;
use App\Support\ServiceDocs;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use App\Support\TicketTriage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
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
            // مفردات الحالة من مصدر الرحلة — قائمة مكتوبة يدوياً كانت تُسقط حالات حقيقية
            'states' => TicketJourney::options(),
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
        Live::push(new TicketMessageBroadcast($msg));

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
        Live::push(new TicketMessageBroadcast($msg));

        return response()->noContent();
    }

    // تغيير حالة التذكرة (بثّ لحظي — يتقدّم المسار لدى الطرفين)
    public function status(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        // الحالة مقيّدة بمفردات الرحلة — نصّ حرّ كان يُحفظ ويُبثّ ثم يُعرض عند مرحلة خاطئة.
        // والنغمة تُشتقّ هنا ولا تُقبل من العميل: كانت أي نغمة تُقبل مع أي حالة.
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(TicketJourney::statuses())],
        ]);

        // التذاكر المكتملة/المغلقة نهائية — لا تُعاد لمرحلة سابقة (تمنع مسح اعتماد
        // المحامي/الإدارة عبر إعادة تنفيذ referToLawyer على تذكرة منصرفة). يُسمح بالتبديل
        // بين الحالات النهائية فقط (مكتملة ⇄ مغلقة)، لا التراجع لمراحل المعالجة.
        abort_if(
            in_array($ticket->status, ['مكتملة', 'مغلقة'], true)
            && ! in_array($data['status'], ['مكتملة', 'مغلقة'], true),
            422,
            'التذكرة مكتملة/مغلقة ولا يمكن إعادة فتحها. أنشئ تذكرة جديدة إن لزم.'
        );

        // الترتيب محروس أيضاً: المفردات وحدها كانت تسمح بالقفز إلى «مكتملة» فيُتخطّى
        // مسار الرحلة كلّه ويُفتح تحويل التذكرة إلى قضية — باب خلفي يلتفّ على advance.
        abort_unless(
            TicketJourney::canTransition($ticket->status, $data['status']),
            422,
            'انتقال غير مسموح: لا يمكن تخطّي مراحل الرحلة.',
        );

        $ticket->update(['status' => $data['status'], 'tone' => TicketJourney::toneFor($data['status'])]);
        Live::push(new TicketStatusBroadcast($ticket));

        return response()->noContent();
    }

    // تنفيذ المرحلة التالية من رحلة المعالجة (تحديث الحالة + رسالة + بثّ)
    public function advance(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);

        // التذاكر المكتملة/المغلقة نهائية — advance لا يفعل شيئاً (ولا يعيد تنفيذ البوابات).
        // دفاع بالعمق: حتى لو تراجعت status لأي سبب، يبقى advance no-op آمناً.
        if (in_array($ticket->status, ['مكتملة', 'مغلقة'], true)) {
            return response()->noContent();
        }

        // مراحل بيد المحامي/الإدارة/العميل — لا يتقدّم الموظف فيها (يشمل انتظار حجز العميل والسداد)
        if (in_array($ticket->status, TicketJourney::AWAITING_OTHERS, true)) {
            return response()->noContent();
        }

        // الموعد قائم: يعقد الموظف الجلسة ويوثّق محضرها، ثم تُرفع النتيجة للمستشار
        if (in_array($ticket->status, TicketJourney::SESSION_READY, true)) {
            $this->conductSession($ticket);

            return response()->noContent();
        }

        $stage = TicketJourney::next($ticket->status);
        if (! $stage) {
            return response()->noContent(); // اكتملت الرحلة
        }

        // بوابة المستندات: لا تُحال للقسم قبل التأكد من إرفاق المستندات
        if ($stage['status'] === 'محالة للقسم القانوني' && $ticket->attachments < 1) {
            $ticket->update(['status' => 'بانتظار مستندات', 'tone' => TicketJourney::toneFor('بانتظار مستندات'), 'last_message' => 'بانتظار إرفاق المستندات المطلوبة', 'date_label' => 'الآن']);

            $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => '<p>لمساعدتنا في دراسة الطلب بشكل أدق، يرجى إرفاق المستندات التالية (حسب نوع القضية):</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => $this->clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            Live::push(new TicketStatusBroadcast($ticket));

            return response()->noContent();
        }

        // الإحالة للقسم القانوني: تقدّم فوري بيد الموظف (بلا نداء AI)، ثم انتظار اعتماد المستشار
        if ($stage['status'] === 'محالة للقسم القانوني') {
            TicketTriage::referToLawyer($ticket);

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
            default => LegalAiService::AGENT_NAME,
        };
        $msg = $ticket->messages()->create([
            'who' => $stage['who'],
            'name' => $name,
            'role' => $stage['role'],
            'body' => nl2br(e($stage['msg'])),
            'time_label' => $this->clock(),
        ]);

        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        return response()->noContent();
    }

    /**
     * اعتماد ملخص المستند وإرساله للعميل.
     * يُستدعى من لوحة الموظف عبر زر «اعتماد وإرسال للعميل» في الملاحظة الداخلية.
     */
    public function approveDocSummary(Request $request, Ticket $ticket, TicketDocument $document): HttpResponse
    {
        $this->guardBranch($ticket);

        // تأكد أن المستند تابع لهذه التذكرة
        abort_unless($document->ticket_id === $ticket->id, 403);

        // لا إعادة اعتماد لما تم إرساله مسبقاً
        abort_if($document->summary_approved === true, 409, 'تم إرسال ملخص هذا المستند للعميل مسبقاً.');

        abort_if(empty($document->summary), 422, 'لا يوجد ملخص لهذا المستند.');

        // تسجيل الاعتماد
        $document->update(['summary_approved' => true]);

        // إنشاء رسالة ai مرئية للعميل بالملخص المعتمد
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'تحليل المستند',
            'body' => '<p>تم فحص المستند «'.e($document->name).'» والتحقق من محتواه.</p>'
                .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
                .'<span class="doc-chip">📄 النوع: '.e($document->doc_type).'</span>'
                .'<span class="doc-chip">📝 '.e($document->summary).'</span>'
                .'</div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

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
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'محضر الجلسة',
            'body' => '<p>انعقدت الجلسة، يعمل فريقنا على مراجعة ملخصها وإعداد التوصيات والمخرجات النهائية — ستصلكم النتيجة فور اعتمادها من المستشار القانوني.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        // تجهيز نتيجة الجلسة من الملخص المعتمد، ورفعها لمراجعة المستشار
        $ticket->summary?->update([
            'result' => TicketResult::compose($ticket, $ticket->summary),
            'result_status' => 'pending_lawyer',
        ]);

        $ticket->update([
            'status' => 'بانتظار اعتماد النتيجة',
            'tone' => TicketJourney::toneFor('بانتظار اعتماد النتيجة'),
            'last_message' => 'انتهت الجلسة، وملخصها بانتظار اعتماد المستشار',
            'date_label' => 'الآن',
        ]);
        Live::push(new TicketStatusBroadcast($ticket));
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
