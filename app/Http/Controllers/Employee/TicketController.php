<?php

namespace App\Http\Controllers\Employee;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transitions\Ticket\AwaitTicketDocuments;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\TicketDocumentsReceived;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\Audit;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\LegalCatalogue;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketAssignment;
use App\Support\TicketDocumentRequirements;
use App\Support\TicketJourney;
use App\Support\TicketTriage;
use App\Support\TicketWritePolicy;
use App\Support\UploadLimits;
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
    public function index(): Response
    {
        $allTickets = Ticket::with(['user', 'assignedLawyer', 'summary'])->withExists(['legalCase', 'execution'])
            ->latest('id')->get();

        $tickets = $allTickets->map(function (Ticket $t) {
            $card = $t->toEmployeeCard();
            $card['converted'] = (bool) $t->legal_case_exists || (bool) $t->execution_exists;
            $card['convertedType'] = $t->execution_exists ? 'execution' : ($t->legal_case_exists ? 'case' : null);
            // شرط زرّ «تحويل لقضية»: الخادم يشترط ملخصاً معتمداً — القائمة كانت بلا هذا الحقل فتعرض الزرّ ثم 422
            $card['summaryApproved'] = (bool) $t->summary?->isApproved();
            $card['updatedAgo'] = $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن';
            $card['createdAgo'] = $t->created_at?->locale('ar')->diffForHumans() ?? 'الآن';

            return $card;
        });

        $counts = [
            'total' => $allTickets->count(),
            'needAction' => $allTickets->whereNotIn('status', TicketJourney::AWAITING_OTHERS)->whereNotIn('status', TicketStatus::finals())->count(),
            'missingDocs' => $allTickets->where('status', 'بانتظار مستندات')->count(),
            'referred' => $allTickets->where('status', 'محالة للقسم القانوني')->count(),
            // من الكتالوج: «حرجة/urgent/high» ثلاثُ مفرداتٍ لا كاتب لها، والكاتب الوحيد «عالية»
            'urgent' => $allTickets->filter(fn ($t) => TicketJourney::isUrgent($t->priority))->count(),
            'completed' => $allTickets->whereIn('status', [TicketStatus::Completed->value, TicketStatus::Closed->value])->count(),
        ];

        $departments = $allTickets->pluck('department')->filter()->unique()->values();

        return Inertia::render('employee/tickets', [
            'tickets' => $tickets,
            'counts' => $counts,
            // مجموعة «ينتظر طرفاً آخر» نفسها التي يعدّ بها الخادم — تبني الواجهة تبويبها عليها لا على نسخةٍ يدويّة
            'awaitingOthers' => TicketJourney::AWAITING_OTHERS,
            'departments' => $departments,
            // أقسام مودال التحويل من الكتالوج الفعّال — لا قائمة ثابتة تخلط الإداريّ بالقانونيّ
            'catalogueDepartments' => LegalCatalogue::departments()->pluck('name')->values(),
            // محامو المكتب — مودال التحويل في القائمة يحتاج القائمة الحقيقية لا بيانات ثابتة
            'lawyers' => User::where('role', Role::Lawyer)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'legalCase']);

        $client = $ticket->user;
        $clientStats = $client ? [
            'totalTickets' => Ticket::where('user_id', $client->id)->count(),
            'activeTickets' => Ticket::where('user_id', $client->id)->open()->count(),
            'totalCases' => LegalCase::where('user_id', $client->id)->count(),
            'memberSince' => $client->created_at?->locale('ar')->translatedFormat('F Y') ?? '—',
        ] : null;

        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('employee/ticketchat', [
            // من يتولّى المحادثة الآن ومن تولّاها قبله — للطاقم وحده (`ConversationHandler`)
            'conversation' => ConversationHandler::history($ticket),
            // مراحل الإحالة من `TicketTriage::REFERRABLE` — حارس `advance` نفسه؛ قائمةٌ لا علم لأنّ
            // الشاشة تقارنها بالحالة الحيّة (البثّ) فيظهر الزرّ ويختفي دون إعادة تحميل
            'referrable' => TicketTriage::REFERRABLE,
            // caseRef يخفي زرّ «تحويل إلى قضية» بعد التحويل ويعرض رابط ملف القضية بدله
            // mobile/openedAt لبطاقتَي «تفاصيل الطلب» ومعلومات التذكرة (يطابق tkDetailsCard المرجعي)
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
                'priority' => $ticket->priority ?: 'متوسطة',
                // الموظف لا يحوّل قبل اعتماد المحامي — الزرّ يُخفى بدل أن يُعرَض ويُرفض بـ422
                'summaryApproved' => (bool) $ticket->summary?->isApproved(),
                // زرّ «إعادة التحليل الذكي» بحارس `rerunSummary` نفسه
                'canRerunSummary' => $ticket->summaryRerunBlocker() === null,
                // اقتراح النظام لمودال التحويل — لغير المسنَدة وحدها، والإسناد يؤكّده الموظّف
                'lawyerSuggestion' => $ticket->assigned_lawyer_id ? null : TicketAssignment::suggest($ticket)->toArray(),
            ]),
            'clientStats' => $clientStats,
            'channel' => 'ticket.'.$ticket->id,
            // الموظف يرى كل الرسائل بما فيها الملاحظات الداخلية
            'messages' => ConversationFiles::linkLegacyChips($ticket->messages->map->toMessage()->all(), 'ticket', $ticket->documents),
            // مفردات الحالة من مصدر الرحلة — قائمة مكتوبة يدوياً كانت تُسقط حالات حقيقية
            'states' => TicketJourney::options(),
            // محامو المكتب لمودال جدولة الموعد المضمّن
            'lawyers' => $lawyers,
            // أقسام مودال التحويل من الكتالوج الفعّال
            'catalogueDepartments' => LegalCatalogue::departments()->pluck('name')->values(),
        ]);
    }

    // ردّ الموظف (يراه العميل ضمن نفس التذكرة) — بثّ لحظي معزول بالمعاملة
    public function reply(Request $request, Ticket $ticket): HttpResponse
    {
        TicketWritePolicy::assertWritable($ticket);

        $data = $request->validate(['body' => ['required', 'string']]);

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

            Audit::log(
                action: 'رد على تذكرة',
                description: "ردّ {$request->user()->name} (خدمة العملاء) على التذكرة {$ticket->number}.",
                category: 'تذاكر',
                auditable: $ticket,
                auditableRef: $ticket->number,
            );
        }

        return response()->noContent();
    }

    // إرفاق مستند من الموظف بالتذكرة (يراه العميل) — نفس قيود رفع العميل (`UploadLimits::ATTACHMENT_KB` + الصيغ المسموحة)
    public function attach(Request $request, Ticket $ticket): HttpResponse
    {
        TicketWritePolicy::assertWritable($ticket);

        $request->validate(['file' => ['required', 'file', UploadLimits::rule(UploadLimits::ATTACHMENT_KB), 'mimes:pdf,jpg,jpeg,png,doc,docx']]);

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $path = $file->store("ticket-docs/{$ticket->id}");
        $ticket->increment('attachments');

        $doc = $ticket->documents()->create([
            'name' => $name,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'status' => TicketDocument::FROM_OFFICE,
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'staff',
            'name' => $request->user()->name,
            'role' => 'خدمة العملاء',
            'body' => '<p>تم إرفاق مستند من المكتب:</p><div class="doc-list">'.ConversationFiles::chip('ticket', $doc).'</div>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        if ($ticket->status === 'بانتظار مستندات') {
            Workflow::run(new TicketDocumentsReceived, $ticket, $request->user(), [
                'last_message' => 'تم إرفاق مستند من المكتب: '.$name,
                'via' => 'employee.attach',
            ]);
            Live::push(new TicketStatusBroadcast($ticket));
        } else {
            $ticket->update(['last_message' => 'تم إرفاق مستند: '.$name, 'date_label' => 'الآن']);
        }

        return response()->noContent();
    }

    // طلب النواقص من العميل: يبني القائمة خادميّاً (تهريب اسم كل مستند فقط)، يبثّ الرسالة+الحالة،
    // ويضع التذكرة في «بانتظار مستندات» — فيمرّ رفع العميل لاحقاً عبر مسار إعادة التحليل/الإحالة للمستشار.
    public function requestDocs(Request $request, Ticket $ticket): HttpResponse
    {

        // **النواقص قبل اعتماد المستشار وحده** — كان يُقبل في أيّ مرحلة غير نهائيّة، فيُرجع تذكرةً
        // معتمدةً إلى «بانتظار مستندات» ثمّ يمحو رفعُ العميل اعتماد المستشار (ع١١).
        if (! in_array($ticket->status, TicketJourney::BEFORE_LAWYER_APPROVAL, true)) {
            throw ValidationException::withMessages([
                'docs' => 'طلب النواقص قبل اعتماد المستشار للملخّص — بعده يطلبها المستشار من التذكرة.',
            ]);
        }

        $data = $request->validate([
            'docs' => ['required', 'array', 'min:1', 'max:20'],
            'docs.*' => ['required', 'string', 'max:190'],
        ]);

        // الغلاف الواحد (`TicketDocumentRequirements::chips`) يهرّب كلّ اسم — لا HTML قادم من الواجهة (يمنع دمج الكود في الرسالة)
        $chips = TicketDocumentRequirements::chips($data['docs']);

        $msg = null;
        DB::transaction(function () use ($ticket, $request, $chips, &$msg) {
            $msg = $ticket->messages()->create([
                'who' => 'staff',
                'name' => $request->user()->name,
                'role' => 'نواقص',
                // **لا تنويه هنا**: هذه القائمة يكتبها الموظّف بنفسه لهذا الملفّ
                // ($data['docs'] من الطلب)، فوسمُها «عامّة بحسب النوع» يكذب عكسياً.
                'body' => '<p>للتمكن من دراسة طلبكم وإكمال الإجراءات، نأمل تزويدنا بالمستندات التالية:</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => $this->clock(),
            ]);

            Workflow::run(new AwaitTicketDocuments, $ticket, $request->user(), [
                'last_message' => 'طلب نواقص من خدمة العملاء',
                'via' => 'employee.request_docs',
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
        TicketWritePolicy::assertWritable($ticket);

        $data = $request->validate(['body' => ['required', 'string']]);

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
        /*
         * **لا تغيير يدويّ للحالة من الموظّف** (قرار المالك 2026-09-14).
         *
         * كانت القائمة تقبل أيّ تبديلٍ داخل رقم المرحلة: «مكتملة» من «بانتظار اعتماد الإدارة»
         * (ع٥)، و«مكتملة» من «مغلقة» فتعود أهليّة التحويل لقضيّة (ع٦)، والتبديل داخل مرحلة
         * الجلسة يكرّر «انعقدت الجلسة» (ع١٢). الرحلة تتقدّم بأفعالٍ صريحة، والتصحيح
         * الاستثنائيّ للإدارة العليا مع سببٍ مكتوب.
         */
        abort(403, 'تغيير الحالة يدوياً للإدارة العليا فقط مع ذكر السبب — استعمل إجراءات التذكرة.');
    }

    // تنفيذ المرحلة التالية من رحلة المعالجة (تحديث الحالة + رسالة + بثّ)
    public function advance(Request $request, Ticket $ticket): HttpResponse
    {
        /*
         * **فعلٌ واحد صريح للموظّف: الإحالة إلى المستشار.**
         *
         * كان «تنفيذ المرحلة التالية» يمشي بالتذكرة على أرقام المراحل: يكتب «بانتظار حجز
         * الاستشارة» بعد الرأي القانونيّ، و«انعقدت الجلسة» بعد الموعد — ولو لم يحضر العميل
         * (ع٢١) أو أُلغيت الاستشارة (ع١٠). الآن: طلب الاستشارة زرٌّ مستقلّ، وإكمال التذكرة
         * باعتماد الإدارة لملخّص الجلسة (`ConsultSessionOutcome`).
         */
        // القائمة من `TicketTriage::REFERRABLE` — مصدرٌ واحد مع إعادة الفحص تحت القفل في `referToLawyer`
        if (! in_array($ticket->status, TicketTriage::REFERRABLE, true)) {
            abort(422, match ($ticket->status) {
                'موعد مؤكد', 'بانتظار ملخّص الجلسة' => 'تكتمل التذكرة بعد الجلسة باعتماد الإدارة لملخّصها — لا إجراء للموظّف هنا.',
                'الرأي القانوني' => 'الخطوة التالية طلب استشارة من «خيارات التذكرة».',
                default => 'لا إجراء للموظّف في هذه المرحلة.',
            });
        }

        // **بوابة المستندات تعدّ ما يخصّ الملفّ** — كان العدّاد يزيد مع كلّ مرفق ولو رُفض (ع٢٥)
        $usable = $ticket->documents()->where('status', '!=', 'غير مرتبط')->exists();

        if (! $usable) {
            Workflow::run(new AwaitTicketDocuments, $ticket, $request->user(), [
                'last_message' => 'بانتظار إرفاق المستندات المطلوبة',
                'via' => 'employee.advance',
            ]);

            // نواقص قائمة القسم وحدها — ما ثبت إرفاقه لا يُطلب ثانيةً
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => TicketDocumentRequirements::requestHtml($ticket, 'لمساعدتنا في دراسة الطلب بشكل أدق، يرجى إرفاق المستندات التالية:'),
                'time_label' => $this->clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            Live::push(new TicketStatusBroadcast($ticket));

            return response()->noContent();
        }

        TicketTriage::referToLawyer($ticket, $request->user());

        return response()->noContent();
    }

    /**
     * اعتماد ملخص المستند وإرساله للعميل.
     * يُستدعى من لوحة الموظف عبر زر «اعتماد وإرسال للعميل» في الملاحظة الداخلية.
     */
    // إعادة تشغيل تحليل الذكاء الاصطناعي للملخص من لوحة الموظف (يطابق cRerun)
    public function rerunSummary(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->summary, 404);
        if (($why = $ticket->summaryRerunBlocker()) !== null) {
            throw ValidationException::withMessages(['summary' => $why]);
        }

        GenerateTicketSummaryJob::dispatch($ticket, force: true);

        return back()->with('flash', 'تمت إعادة تشغيل التحليل الذكي للملخّص.');
    }

    // مقترح الموظف لمسار مآل التذكرة مرفوعاً للإدارة العليا
    public function proposeTrack(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'track' => ['required', 'string', Rule::in(TicketOutcomeTrack::values())],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        Workflow::run(new ProposeOutcomeTrack, $ticket, $request->user(), $data);

        return back()->with('flash', 'تم رفع مقترح المسار للإدارة العليا للاعتماد بنجاح.');
    }

    /**
     * عقد الجلسة وتوثيق محضرها (يطابق tfSession)، ثم رفع النتيجة لاعتماد المستشار.
     */
    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
