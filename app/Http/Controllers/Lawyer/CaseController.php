<?php

namespace App\Http\Controllers\Lawyer;

use App\Domain\Journey\Enums\CaseStatus;
use App\Http\Controllers\Concerns\ManagesCourtProceedings;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Jobs\DraftCasePleadingJob;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Services\Ai\AiReviewOutcome;
use App\Support\Audit;
use App\Support\CaseExecutionRequest;
use App\Support\CaseFiling;
use App\Support\CaseJourney;
use App\Support\CasePleading;
use App\Support\CaseTicketDocuments;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\ExecutionCreation;
use App\Support\Notify;
use App\Support\UploadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي للقضايا — التحضير واعتماد اللائحة وجدولة الجلسات وتسجيل الحكم.
 */
class CaseController extends Controller
{
    use ManagesCourtProceedings;
    use ScopedToLawyer;

    /** إجراءات المحكمة (ناجز والجلسات والحكم) للمحامي المسنَد — والإدارة كما في `guardAssigned`. */
    protected function guardCourtAccess(LegalCase $case): void
    {
        $this->guardAssigned($case);
    }

    public function index(Request $request): Response
    {
        // بلا شرط التذكرة: كان يُخفي قضايا المحامي التي انفصلت عن تذكرتها (تنظيف التكرار
        // يُفرغ `ticket_id`، وحذف التذكرة كذلك) — وصفحتها نفسها تبقى متاحة له
        $cases = LegalCase::with('user')
            ->where('assigned_lawyer_id', $request->user()->id)->latest('id')->get()
            ->map(fn (LegalCase $c) => $this->card($c));

        // تبويبات الحالة من المصدر نفسه الذي تقرؤه قضايا الإدارة (`CaseJourney::adminTabs`)
        return Inertia::render('lawyer/cases', ['cases' => $cases, 'tabs' => CaseJourney::adminTabs()]);
    }

    public function show(LegalCase $case): Response
    {
        $this->guardAssigned($case);
        $case->load(['user', 'hearings', 'documents', 'ticket.summary', 'ticket.documents']);

        $viewer = auth()->user();
        $ticket = $case->ticket;
        $summary = $ticket?->summary;

        // **مرفقات الطلب قبل التحويل** — بلا ما رفضه الفحص «غير مرتبط» (`CaseTicketDocuments`)
        $ticketDocs = CaseTicketDocuments::for($case, $viewer);

        // **جاهزية اللائحة** — ما يلزم قبل الاعتماد النهائيّ، من الحرّاس نفسها لا من تقديرٍ في الشاشة
        $pending = $ticketDocs->where('summary', '')->count()
            + $case->documents->filter(fn ($d) => trim((string) $d->summary) === '')->count();
        $draft = CasePleading::latestDraft($case);
        $readiness = $case->pleading_status !== 'pending_lawyer' ? [] : [
            ['label' => 'ملخّص وقائع الطلب معتمد', 'ok' => (bool) $summary?->isApproved(), 'hint' => $summary ? null : 'لا ملخّص للطلب'],
            ['label' => 'مستندات الملف محلَّلة', 'ok' => $pending === 0, 'hint' => $pending > 0 ? "{$pending} بانتظار التحليل" : null],
            ['label' => 'مسودّة اللائحة جاهزة', 'ok' => $draft !== null, 'hint' => null],
            ['label' => 'لا تنبيهات معلّقة في المسودّة', 'ok' => $draft !== null && ! CasePleading::hasWarnings($case), 'hint' => null],
        ];

        return Inertia::render('lawyer/case', [
            // من يتولّى المحادثة الآن ومن تولّاها قبله — للطاقم وحده (`ConversationHandler`)
            'conversation' => ConversationHandler::history($case),
            // رفع الدعوى في ناجز ثمّ قيدها (الخطّة ب) — ما يجوز الآن من الحرّاس نفسها
            'filing' => CaseFiling::panel($case),
            'ticketDocuments' => $ticketDocs,
            'fileInfo' => [
                'ticketNo' => $ticket?->number,
                'subject' => $ticket?->subject,
                'opponent' => $ticket?->opponent_name,
                'claim' => $ticket?->claim_amount ? number_format((int) $ticket->claim_amount).' ر.س' : null,
                'court' => $ticket?->court_name,
            ],
            'fileFacts' => $summary ? [
                'summary' => $summary->case_summary,
                'facts' => $summary->facts,
                'keyPoints' => $summary->key_points,
                'approved' => $summary->isApproved(),
            ] : null,
            'readiness' => $readiness,
            'case' => $this->card($case),
            'channel' => 'case.'.$case->id,
            // المكتب يرى المحجوب ليراجعه — وهو الفاصل الذي لم يكن موجوداً
            'messages' => ConversationFiles::linkLegacyChips($case->messages()->visibleTo(true)->get()->map->toMessage()->all(), 'case', $case->documents),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $case->documents->map(fn ($d) => $d->toData(auth()->user())),
            'convertedExec' => $case->execution()->exists(),
            // زرّ الاعتماد يُعرض حين يجوز، وإلا فسببُ تعذّره — والخادم يرفض بالسبب نفسه
            'pleadingBlock' => CasePleading::blockReason($case),
            // نصّ أحدث مسودّة للمحرّر، ومن كتبها (آلة أم إنسان)
            'pleadingDraft' => CasePleading::draftText(CasePleading::latestDraft($case)),
            // فتح التنفيذ بطلبٍ تعتمده الإدارة العليا (قرار المالك 2026-09-29) — من الخادم لا من مقارنةٍ باليد
            'executionRequest' => CaseExecutionRequest::pending($case),
            'canRequestExecution' => ExecutionCreation::isEligible($case) && $case->execution_requested_at === null,
            // المبلغ المحكوم به يُقترح من مبلغ المطالبة في التذكرة — ويؤكّده رافع الطلب أو يصحّحه
            'executionAmountHint' => $case->ticketClaimAmount(),
        ]);
    }

    // ردّ المحامي المسؤول على موكّله داخل ملفّ القضية (نظير رد الموظف) — بثّ لحظي.
    // كان المحامي يقرأ المحادثة ولا يملك وسيلة للردّ، فيضطر للخروج لقناة أخرى.
    public function reply(Request $request, LegalCase $case): \Illuminate\Http\Response
    {
        $this->guardAssigned($case);
        // الأرشيف للقراءة — كان الإرفاق يُرفض عليه والردّ يمرّ
        abort_if($case->status === CaseStatus::Archived->value, 422, 'القضية مؤرشفة — ملفها للقراءة فقط.');
        $data = $request->validate(['body' => ['required', 'string']]);

        $msg = $case->messages()->create([
            'who' => 'lawyer',
            'name' => $request->user()->name,
            'role' => 'المستشار القانوني',
            'body' => nl2br(e($data['body'])),
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);
        // البثّ يقع تلقائياً في CaseMessage::booted عند الإنشاء — لا تُكرّره هنا

        return response()->noContent();
    }

    // إرفاق مستند حقيقي من المحامي إلى ملف القضية (مذكرات/أدلة/مسودات) — يُحفظ ضمن مستندات القضية.
    public function attach(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_if($case->status === CaseStatus::Archived->value, 422, 'لا يمكن إرفاق مستندات على قضية مؤرشفة.');

        $data = $request->validate([
            'file' => ['required', 'file', UploadLimits::rule(UploadLimits::ATTACHMENT_KB), 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'hearing_id' => ['nullable', 'integer', 'exists:case_hearings,id'],
        ]);

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $hearingId = $data['hearing_id'] ?? null;
        $doc = $case->documents()->create([
            'case_id' => $case->id,
            'hearing_id' => $hearingId,
            'name' => $name,
            'path' => $file->store("case-docs/{$case->id}"),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => 'lawyer',
            'status' => 'قيد الفحص',
        ]);

        $hearingNote = '';
        if ($hearingId && ($h = $case->hearings()->find($hearingId))) {
            $hearingNote = ' — مرتبط بـ: <b>'.e($h->title).'</b>';
        }

        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'مستند',
            'body' => '<p>أرفق المحامي مستنداً بملف القضية'.$hearingNote.':</p><div class="doc-list">'.ConversationFiles::chip('case', $doc).'</div>',
            'time_label' => $this->clock(),
        ]);
        $case->update(['update_text' => 'أرفق المحامي مستنداً: '.$name]);
        $this->notify($case, 'upload', 't-cyan', "أُرفق مستند جديد بملف قضيتك {$case->number}.");

        // تحليل ذكي للمستند بالخلفية (تلخيص + تصنيف ثم ملخّص في المحادثة)
        AnalyzeCaseDocumentJob::dispatch($case, $doc);

        return back();
    }

    // فتح طلب تنفيذ من قضية بلغت «صدر الحكم» (تنفيذ الحكم)
    /**
     * **طلب فتح تنفيذ الحكم يُرفع للإدارة العليا** (قرار المالك 2026-09-29) — كان المحامي يفتح الملفّ مباشرةً.
     */
    public function requestExecution(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'integer', 'min:1'],
        ], ['amount.*' => 'أدخل المبلغ المحكوم به (ريال) — رقماً صحيحاً أكبر من صفر.']);

        CaseExecutionRequest::request($case, $request->user(), $data['reason'], (int) $data['amount']);

        return back()->with('flash', 'رُفع طلب فتح التنفيذ للإدارة العليا — يُفتح الملفّ فور اعتماده.');
    }

    // اعتماد اللائحة → القضية منظورة (يطابق cfApprove → cfTrack)
    public function approvePleading(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($case->pleading_status === 'pending_lawyer', 422, 'اللائحة ليست بانتظار اعتمادك — ربّما اعتُمدت من قبل، حدّث الصفحة.');
        // لا يُبلَّغ العميل باعتماد لائحةٍ لا نصَّ لها
        // بلا مسودّة، أو بنصٍّ احتياطيّ، أو بعد رفضها في الصندوق — لا اعتماد
        $blocked = CasePleading::blockReason($case);
        abort_if($blocked !== null, 422, (string) $blocked);

        // نصٌّ حرّره المحامي يُسجَّل «تعديلاً» لا «قبولاً» — يُقاس ما وقع لا ما أُعلن
        $edited = CasePleading::isHumanAuthored(CasePleading::latestDraft($case));

        // الإطلاق ورفع الدعوى معاً — المصدر نفسه الذي يناديه قبول صندوق المراجعة
        CasePleading::approve($case, $request->user());
        // والقرار يُسجَّل على قيد الذكاء، وإلا بقي «بانتظار المراجعة» لمخرجٍ اعتُمد وأُطلق
        AiReviewOutcome::recordFileApproval('case.pleading', $case->number, $request->user(), $edited);

        return back();
    }

    /**
     * **حفظ مسودّة اللائحة** (قرار المالك 2026-09-11): المحامي يكتب اللائحة أو يحرّر مسودّة الآلة.
     * كانت وثيقةٌ قضائيّة تصل العميل بصياغة النموذج حرفياً، و«تعديل واعتماد» في الصندوق لا يحفظ
     * نصّاً. الحفظ مسودّةٌ محجوبة قابلة للتعديل؛ والاعتماد النهائيّ وحده يُطلقها ويقفلها.
     */
    public function savePleading(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($case->pleading_status === 'pending_lawyer', 422, 'اعتُمدت اللائحة نهائياً — لا تُعدَّل بعد الاعتماد.');

        $data = $request->validate(['body' => ['required', 'string', 'min:20', 'max:30000']], [
            'body.required' => 'اكتب نصّ اللائحة قبل الحفظ.',
            'body.min' => 'نصّ اللائحة أقصر من أن يكون لائحة دعوى.',
        ]);

        CasePleading::save($case, $request->user(), $data['body']);

        Audit::log(
            action: 'حفظ مسودة لائحة',
            description: "حفظ {$request->user()->name} مسودّة لائحة الدعوى للقضية {$case->number} — محجوبة حتى الاعتماد النهائيّ.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
        );

        return back()->with('flash', 'حُفظت المسودّة — تبقى محجوبة عن العميل حتى الاعتماد النهائيّ.');
    }

    /**
     * **إعادة توليد المسودّة** — لم يكن لها مسار: «إعادة تشغيل» في الصندوق تسجّل القرار ولا تُعيد
     * شيئاً. تُنشئ مسودّةً آليّة جديدةً محجوبة (الأحدث)، وما حرّره المحامي يبقى في السجلّ.
     */
    public function regeneratePleading(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($case->pleading_status === 'pending_lawyer', 422, 'اعتُمدت اللائحة نهائياً — لا يُعاد توليدها.');

        DraftCasePleadingJob::dispatch($case);

        Audit::log(
            action: 'إعادة توليد لائحة',
            description: "طلب {$request->user()->name} إعادة توليد مسودّة لائحة الدعوى للقضية {$case->number}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
        );

        return back()->with('flash', 'جارٍ إعادة التوليد — تظهر المسودّة الجديدة في المحادثة وفي المحرّر حين تجهز.');
    }

    // الرفع في ناجز والقيد والجلسات والحكم: `ManagesCourtProceedings` — مصدرٌ واحد مع الموظّف

    private function card(LegalCase $c): array
    {
        return [
            'no' => $c->number,
            'client' => Ticket::maskClient($c->user?->name ?? ''),
            'type' => $c->type,
            'dept' => $c->department,
            'lawyer' => $c->assigned_lawyer ?: '—',
            'status' => $c->status,
            'tone' => $c->tone,
            ...$c->stateFlags(),
            'next' => $c->nextHearingLabel(),
            'pleadingStatus' => $c->pleading_status,
            'ruling' => $c->ruling,
            'appeal' => $c->appealCard(),
        ];
    }

    private function notify(LegalCase $case, string $icon, string $tone, string $body): void
    {
        Notify::send($case->user_id, $icon, $tone, $body);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
