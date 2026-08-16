<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\CaseConversion;
use App\Support\Live;
use App\Support\Notify;
use App\Support\ServiceDocs;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use App\Support\TicketTriage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            // محامو الفرع — مودال التحويل في القائمة يحتاج القائمة الحقيقية لا بيانات ثابتة
            'lawyers' => User::where('role', Role::Lawyer)
                ->where('branch', $this->currentBranch())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $this->guardBranch($ticket);
        $ticket->load(['user', 'legalCase']);

        $lawyers = User::where('role', Role::Lawyer)
            ->where('branch', $this->currentBranch())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('employee/ticketchat', [
            // caseRef يخفي زرّ «تحويل إلى قضية» بعد التحويل ويعرض رابط ملف القضية بدله
            // mobile/openedAt لبطاقتَي «تفاصيل الطلب» ومعلومات التذكرة (يطابق tkDetailsCard المرجعي)
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            // الموظف يرى كل الرسائل بما فيها الملاحظات الداخلية
            'messages' => $ticket->messages->map->toMessage(),
            // مفردات الحالة من مصدر الرحلة — قائمة مكتوبة يدوياً كانت تُسقط حالات حقيقية
            'states' => TicketJourney::options(),
            // محامو الفرع لمودال جدولة الموعد المضمّن
            'lawyers' => $lawyers,
        ]);
    }

    // ردّ الموظف (يراه العميل ضمن نفس التذكرة) — بثّ لحظي معزول بالمعاملة
    public function reply(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $msg = null;
        DB::transaction(function () use ($ticket, $request, $data, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'staff',
                'name' => $request->user()->name,
                'role' => 'خدمة العملاء',
                'body' => nl2br(e($data['body'])),
                'time_label' => $this->clock(),
            ]);

            $ticket->update(['last_message' => $data['body'], 'date_label' => 'الآن']);
        });

        if ($msg) {
            DB::afterCommit(function () use ($msg) {
                Live::push(new TicketMessageBroadcast($msg));
            });
        }

        return response()->noContent();
    }

    // إرفاق مستند من الموظف بالتذكرة (يراه العميل) — نفس قيود رفع العميل (10MB + الصيغ المسموحة)
    public function attach(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx']]);

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $path = $file->store("ticket-docs/{$ticket->id}");
        $ticket->increment('attachments');

        $ticket->documents()->create([
            'name' => $name,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'status' => 'مرفق من المكتب',
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'staff',
            'name' => $request->user()->name,
            'role' => 'خدمة العملاء',
            'body' => '<p>تم إرفاق مستند من المكتب:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);

        return response()->noContent();
    }

    // طلب النواقص من العميل: يبني القائمة خادميّاً (تهريب اسم كل مستند فقط)، يبثّ الرسالة+الحالة،
    // ويضع التذكرة في «بانتظار مستندات» — فيمرّ رفع العميل لاحقاً عبر مسار إعادة التحليل/الإحالة للمستشار.
    public function requestDocs(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);

        // التذاكر المكتملة/المغلقة نهائية — لا تُعاد لطلب نواقص (يبقى الحجب النهائي تصميماً)
        if (in_array($ticket->status, ['مكتملة', 'مغلقة'], true)) {
            throw ValidationException::withMessages([
                'docs' => 'التذكرة مكتملة/مغلقة ولا يمكن طلب نواقص عليها.',
            ]);
        }

        $data = $request->validate([
            'docs' => ['required', 'array', 'min:1', 'max:20'],
            'docs.*' => ['required', 'string', 'max:190'],
        ]);

        // الغلاف حرفيّ ثابت، والتهريب على أسماء المستندات وحدها — لا HTML قادم من الواجهة (يمنع دمج الكود في الرسالة)
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', $data['docs']));

        $msg = null;
        DB::transaction(function () use ($ticket, $request, $chips, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'staff',
                'name' => $request->user()->name,
                'role' => 'نواقص',
                'body' => '<p>للتمكن من دراسة طلبكم وإكمال الإجراءات، نأمل تزويدنا بالمستندات التالية:</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => $this->clock(),
            ]);

            $ticket->update([
                'status' => 'بانتظار مستندات',
                'tone' => TicketJourney::toneFor('بانتظار مستندات'),
                'last_message' => 'طلب نواقص من خدمة العملاء',
                'date_label' => 'الآن',
            ]);
        });

        if ($msg) {
            DB::afterCommit(function () use ($msg, $ticket) {
                Live::push(new TicketMessageBroadcast($msg));
                Live::push(new TicketStatusBroadcast($ticket));
                Notify::send($ticket->user_id, 'upload', 't-amber', "يرجى إرفاق المستندات المطلوبة على تذكرتك {$ticket->number}.");
            });
        }

        return response()->noContent();
    }

    // ملاحظة داخلية (لا يراها العميل) — تُبثّ على قناة الموظفين فقط معزولة بالمعاملة
    public function note(Request $request, Ticket $ticket): HttpResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $msg = null;
        DB::transaction(function () use ($ticket, $request, $data, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'note',
                'name' => $request->user()->name,
                'role' => 'ملاحظة داخلية',
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
        if (in_array($ticket->status, ['مكتملة', 'مغلقة'], true) && ! in_array($data['status'], ['مكتملة', 'مغلقة'], true)) {
            throw ValidationException::withMessages([
                'status' => 'التذكرة مكتملة/مغلقة ولا يمكن إعادة فتحها. أنشئ تذكرة جديدة إن لزم.',
            ]);
        }

        // الترتيب محروس أيضاً: المفردات وحدها كانت تسمح بالقفز إلى «مكتملة» فيُتخطّى
        // مسار الرحلة كلّه ويُفتح تحويل التذكرة إلى قضية — باب خلفي يلتفّ على advance.
        if (! TicketJourney::canTransition($ticket->status, $data['status'])) {
            throw ValidationException::withMessages([
                'status' => 'انتقال غير مسموح: لا يمكن تخطّي مراحل الرحلة.',
            ]);
        }

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

        // «محالة للقسم القانوني» مرحلة عبور تُفضي دائماً إلى المستشار: سواء ننتقل إليها الآن أو
        // كانت التذكرة واقفة عليها (وصلتها مثلاً عبر فتحٍ بلا تحليل ذكي) — الوجهة «بانتظار اعتماد
        // المستشار» لا «الرأي القانوني»؛ فاعتماد المستشار وحده يفتح «الرأي القانوني».
        $referring = $stage['status'] === 'محالة للقسم القانوني' || $ticket->status === 'محالة للقسم القانوني';

        // بوابة المستندات: لا تُحال للقسم قبل التأكد من إرفاق المستندات
        if ($referring && $ticket->attachments < 1) {
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
        if ($referring) {
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
    // إعادة تشغيل تحليل الذكاء الاصطناعي للملخص من لوحة الموظف (يطابق cRerun)
    public function rerunSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardBranch($ticket);
        abort_unless($ticket->summary, 404);
        if ($ticket->summary?->isApproved()) {
            throw ValidationException::withMessages([
                'summary' => 'لا يمكن إعادة تشغيل التحليل لملخّص تم اعتماده رسمياً.',
            ]);
        }

        GenerateTicketSummaryJob::dispatch($ticket, force: true);

        return back()->with('flash', 'تمت إعادة تشغيل التحليل الذكي للملخّص.');
    }

    // تحويل التذكرة المكتملة إلى قضية (يشترط اعتماد المستشار المسبق للنتيجة)
    public function convertToCase(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardBranch($ticket);
        if ($ticket->status !== 'مكتملة') {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اكتمالها.',
            ]);
        }
        if (! $ticket->summary?->isApproved()) {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اعتماد النتيجة من المستشار القانوني.',
            ]);
        }
        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً.',
            ]);
        }

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
