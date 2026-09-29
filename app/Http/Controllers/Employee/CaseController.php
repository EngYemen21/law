<?php

namespace App\Http\Controllers\Employee;

use App\Domain\Journey\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Controllers\Concerns\ManagesCourtProceedings;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CaseFiling;
use App\Support\CaseJourney;
use App\Support\CaseTicketDocuments;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\Notify;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دور الموظف (خدمة العملاء) مع القضايا — متابعة وتنسيق والتواصل مع العميل.
 */
class CaseController extends Controller
{
    use ManagesCourtProceedings;

    /**
     * **الموظّف يدير إجراءات المحكمة** (قرار المالك 2026-09-11): رفع ناجز والقيد والجلسات والحكم.
     * المسارات محروسةٌ بصلاحيّة «إجراءات المحكمة والجلسات» التي تمنحها الإدارة من تبويب الموظّفين
     * (فوق «إدارة القضايا والأتعاب») — لا إسنادٌ يُشترط، وحرّاس الحالة في الـtrait.
     */
    protected function guardCourtAccess(LegalCase $case): void {}

    /**
     * **الحكم أضيقُ من بقيّة الإجراءات** (قرار المالك 2026-09-18): كان حاملُ «إجراءات المحكمة
     * والجلسات» يسجّل حكماً ويصحّحه على أيّ قضيّة في المكتب، ولا إسنادَ للموظّف يُعزل به.
     * فالحكم وتصحيحه وحكم الاستئناف يلزمها «تسجيل الأحكام» فوقها — لا تُمنح تلقائياً.
     */
    protected function guardRulingAccess(LegalCase $case): void
    {
        abort_unless(
            (bool) auth()->user()?->can(Permissions::RECORD_RULINGS),
            403,
            'تسجيل الأحكام للمحامي المسنَد والإدارة، أو لمن منحته الإدارة صلاحيّة «تسجيل الأحكام».'
        );
    }

    public function index(): Response
    {
        $allCases = LegalCase::with(['user', 'assignedLawyer', 'hearings'])
            ->latest('id')->get();

        $cases = $allCases->map(function (LegalCase $c) {
            $nextHearing = $c->nextHearingLive();

            return [
                'no' => $c->number,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'clientId' => $c->user_id,
                'type' => $c->type,
                'dept' => $c->department,
                // المحكمة المقيّدة في ناجز أوّلاً (الخطّة ب)، وإلّا من الجلسة كما يفعل `LegalCase::toCard`
                'court' => $c->court ?: ($nextHearing?->court ?: ($c->hearings->first()?->court ?? '—')),
                'najizCaseNo' => $c->najiz_case_no,
                'lawyer' => $c->assigned_lawyer ?: ($c->assignedLawyer?->name ?? '—'),
                'lawyerId' => $c->assigned_lawyer_id,
                'status' => $c->status,
                'tone' => $c->tone,
                ...$c->stateFlags(),
                'next' => $c->nextHearingLabel(),
                'hasNextHearing' => (bool) $nextHearing,
                'updatedAgo' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ];
        });

        $counts = [
            'total' => $allCases->count(),
            'active' => $allCases->filter(fn (LegalCase $c) => $c->isActive())->count(),
            'withHearings' => $allCases->filter(fn (LegalCase $c) => $c->nextHearingLive() !== null)->count(),
            'preparing' => $allCases->where('status', 'قيد التحضير')->count(),
            // رُفعت في ناجز وتنتظر قيد المحكمة (الخطّة ب)
            'awaiting' => $allCases->where('status', 'بانتظار القيد')->count(),
            'inCourt' => $allCases->where('status', 'منظورة')->count(),
            'ruled' => $allCases->where('status', 'صدر الحكم')->count(),
            'closed' => $allCases->whereIn('status', CaseJourney::CLOSED)->count(),
        ];

        $departments = $allCases->pluck('department')->filter()->unique()->values();
        $types = $allCases->pluck('type')->filter()->unique()->values();

        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('employee/cases', [
            'cases' => $cases,
            'counts' => $counts,
            'departments' => $departments,
            'types' => $types,
            'lawyers' => $lawyers,
        ]);
    }

    public function show(LegalCase $case): Response
    {
        $case->load(['user', 'hearings', 'documents', 'assignedLawyer', 'ticket.documents']);

        $client = $case->user;
        $clientStats = $client ? [
            'totalTickets' => Ticket::where('user_id', $client->id)->count(),
            'activeTickets' => Ticket::where('user_id', $client->id)->open()->count(),
            'totalCases' => LegalCase::where('user_id', $client->id)->count(),
            'memberSince' => $client->created_at?->locale('ar')->translatedFormat('F Y') ?? '—',
        ] : null;

        $documents = $case->documents->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'by' => $d->uploaded_by === 'staff' ? 'المكتب' : ($d->uploaded_by === 'lawyer' ? 'المستشار' : 'العميل'),
            'status' => $d->status,
            'docType' => $d->doc_type,
            'summary' => $d->summary,
            'date' => $d->created_at?->locale('ar')->translatedFormat('j M Y') ?? '—',
            // قرار المالك 2026-09-11 (ينقض حجباً سابقاً): الموظّف يُنزّل مرفقات القضيّة التي يفتحها،
            // بالقاعدة الواحدة في ConversationFiles.
            'downloadUrl' => $d->path !== null && ConversationFiles::canDownload(auth()->user(), $d)
                ? ConversationFiles::url('case', $d->id)
                : null,
        ]);

        // نماذج المحكمة لمن منحته الإدارة الصلاحيّة؛ ومن سواه يرى بيانات ناجز للاطّلاع (والمسار يرفضه)
        $canCourt = (bool) auth()->user()?->can(Permissions::COURT_PROCEEDINGS);

        return Inertia::render('employee/case', [
            // من يتولّى المحادثة الآن ومن تولّاها قبله — للطاقم وحده (`ConversationHandler`)
            'conversation' => ConversationHandler::history($case),
            'case' => [
                'no' => $case->number,
                'client' => Ticket::maskClient($case->user?->name ?? ''),
                'type' => $case->type,
                'dept' => $case->department,
                'court' => $case->court ?: ($case->nextHearingLive()?->court ?: ($case->hearings->first()?->court ?? '—')),
                'lawyer' => $case->assigned_lawyer ?: ($case->assignedLawyer?->name ?? '—'),
                'status' => $case->status,
                'tone' => $case->tone,
                ...$case->stateFlags(),
                'next' => $case->nextHearingLabel(),
                // بيانات الرفع والقيد في ناجز — يسجّلها الموظّف كالمحامي (قرار المالك 2026-09-11)
                'najiz' => $case->najizCard(),
                'appeal' => $case->appealCard(),
            ],
            'canCourt' => $canCourt,
            // الحكم وتصحيحه وحكم الاستئناف — تُخفى نماذجها عمّن يصدّه `guardRulingAccess`
            'canRule' => $canCourt && (bool) auth()->user()?->can(Permissions::RECORD_RULINGS),
            // رفع الدعوى في ناجز والقيد — ما يجوز الآن من الحرّاس نفسها، لمن يملك الصلاحيّة
            'filing' => $canCourt ? CaseFiling::panel($case) : ['canFile' => false, 'canRegister' => false, 'data' => $case->najizCard()],
            'clientStats' => $clientStats,
            'channel' => 'case.'.$case->id,
            // المكتب يرى المحجوب ليراجعه — وهو الفاصل الذي لم يكن موجوداً
            'messages' => ConversationFiles::linkLegacyChips($case->messages()->visibleTo(true)->get()->map->toMessage()->all(), 'case', $case->documents),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $documents,
            // مرفقات الطلب قبل التحويل — بلا المرفوض «غير مرتبط» (`CaseTicketDocuments`)
            'ticketDocuments' => CaseTicketDocuments::for($case, auth()->user()),
        ]);
    }

    // إرفاق مستند من خدمة العملاء لملفّ القضية — نظير إرفاق المحامي والعميل.
    // كانت صفحة قضية الموظف بلا مستندات ولا إرفاق (عدم تماثل مع بقية الأدوار).
    public function attach(Request $request, LegalCase $case): RedirectResponse
    {
        abort_if($case->status === CaseStatus::Archived->value, 422, 'لا يمكن إرفاق مستندات على قضية مؤرشفة.');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
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
            'uploaded_by' => 'staff',
            'status' => 'قيد الفحص',
        ]);

        $hearingNote = '';
        if ($hearingId && ($h = $case->hearings()->find($hearingId))) {
            $hearingNote = ' — مرتبط بـ: <b>'.e($h->title).'</b>';
        }

        $case->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'مستند',
            'body' => '<p>أرفق المكتب مستنداً بملف القضية'.$hearingNote.':</p><div class="doc-list">'.ConversationFiles::chip('case', $doc).'</div>',
            'time_label' => $this->clock(),
        ]);
        $case->update(['update_text' => 'أرفق المكتب مستنداً: '.$name]);
        Notify::send($case->user_id, 'upload', 't-cyan', "أُرفق مستند جديد بملف قضيتك {$case->number}.");

        AnalyzeCaseDocumentJob::dispatch($case, $doc);

        return back();
    }

    // ردّ خدمة العملاء للعميل داخل القضية (بثّ لحظي)
    public function reply(Request $request, LegalCase $case): \Illuminate\Http\Response
    {
        // الأرشيف للقراءة — كان الإرفاق يُرفض عليه والردّ يمرّ
        abort_if($case->status === CaseStatus::Archived->value, 422, 'القضية مؤرشفة — ملفها للقراءة فقط.');
        $data = $request->validate(['body' => ['required', 'string']]);

        $case->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'خدمة العملاء',
            'body' => nl2br(e($data['body'])), 'time_label' => $this->clock(),
        ]);

        return response()->noContent();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
