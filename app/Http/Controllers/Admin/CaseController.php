<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\ClosureCaseReasonCode;
use App\Domain\Journey\Transitions\LegalCase\ArchiveCase as ArchiveCaseTransition;
use App\Domain\Journey\Transitions\LegalCase\CloseCase as CloseCaseTransition;
use App\Domain\Journey\Transitions\LegalCase\ReopenCase as ReopenCaseTransition;
use App\Domain\Journey\Transitions\LegalCase\SetFee as SetFeeTransition;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\CaseStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Mail\CaseFeeSetMail;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\CaseFee;
use App\Support\CaseJourney;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\ExecutionCreation;
use App\Support\Finance\InvoiceDue;
use App\Support\Finance\InvoiceFactory;
use App\Support\Live;
use App\Support\Notify;
use App\Support\Paginate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الإدارة العليا — الإشراف والمتابعة على كافة القضايا القضائية وتحديد أتعابها وإغلاقها.
 */
class CaseController extends Controller
{
    public function fees(): Response
    {
        $cases = LegalCase::with(['user', 'hearings'])->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('admin/casefees', [
            'cases' => Paginate::shape($cases, fn (LegalCase $c) => [
                'no' => $c->number,
                'type' => $c->type,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'lawyer' => $c->assigned_lawyer ?: '—',
                'status' => $c->status,
                'tone' => $c->tone,
                'fee' => $c->fee,
                'lawyerFee' => $c->lawyer_fee,
                'lawyerPct' => $c->lawyer_pct,
                'feeStatus' => $c->fee_status,
                // حكم انتقال `SetFee` (حالته المصدر + صلاحيّة الفاعل) — كانت الواجهة تقارن نصّ الحالة
                'canSetFee' => $c->fee_status === 'none'
                    && (new SetFeeTransition)->accepts((string) $c->status)
                    && (new SetFeeTransition)->deny($c, auth()->user()) === null,
                // خطّة التقسيط — كانت `installments` بلا فرعٍ في الشاشة فتقع على «لا إجراء مطلوب»
                'installmentsTotal' => $c->installments_total,
                'installmentsPaid' => $c->installments_paid,
            ]),
        ]);
    }

    // تحديد قيمة الأتعاب → القضية بانتظار سداد العميل
    public function setFee(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'بانتظار اعتماد الأتعاب', 422, 'تُحدَّد الأتعاب والقضية بانتظار اعتمادها فقط — حدّث الصفحة لترى حالتها الحاليّة.');

        $data = $request->validate([
            'fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lawyer_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        // **الأتعاب صفراً قضيّةٌ بلا أتعاب** (قرار المالك 2026-09-11). كانت تُصدر فاتورةً بصفر
        // ريال «مستحقّة» وتعلّق القضيّة بانتظار سدادٍ لا شيء فيه — فتُفعَّل مباشرةً كأنها سُدّدت.
        if ((int) $data['fee'] === 0) {
            Workflow::run(new SetFeeTransition, $case, $request->user(), [
                'fee' => 0,
                'lawyer_pct' => $data['lawyer_pct'] ?? 0,
                'lawyer_fee' => 0,
            ]);
            $case->messages()->create([
                'who' => 'admin', 'name' => 'الإدارة العليا', 'role' => 'أتعاب',
                'body' => '<p>اعتمدت الإدارة القضية <b>بلا أتعاب</b>، فتُفعَّل مباشرةً دون فاتورة.</p>',
                'time_label' => $this->clock(),
            ]);
            Audit::log(
                action: 'قضية بلا أتعاب',
                description: "اعتمد {$request->user()->name} القضية {$case->number} بلا أتعاب — فُعّلت دون فاتورة.",
                category: 'قضايا وتنفيذ',
                auditable: $case,
                auditableRef: $case->number,
                afterState: ['الأتعاب' => 0],
            );
            CaseFee::activate($case->fresh());
            Notify::send($case->user_id, 'scale', 't-green', "اعتُمدت قضيتك {$case->number} بلا أتعاب، وبدأ العمل عليها.");

            return back();
        }

        $vat = Setting::vatOn($data['fee']);
        $total = $data['fee'] + $vat;
        $pct = $data['lawyer_pct'] ?? 0;
        $lawyerFee = (int) round($data['fee'] * $pct / 100);

        Workflow::run(new SetFeeTransition, $case, $request->user(), [
            'fee' => $data['fee'],
            'lawyer_pct' => $pct,
            'lawyer_fee' => $lawyerFee,
            'vat' => $vat,
            'total' => $total,
        ]);

        // فاتورة أتعاب حقيقية للعميل (يطابق cfInvoice) — بالأساس والضريبة اللذين اعتُمدا للتوّ
        // على صفّ القضيّة نفسه، فلا يُحسب الرقم مرّتين ولا يتباعد الاثنان.
        InvoiceFactory::fromFrozen((int) $data['fee'], $vat, [
            'user_id' => $case->user_id,
            'case_id' => $case->id,
            'description' => "أتعاب قضية {$case->number} — {$case->type}",
            ...InvoiceDue::caseFee(), // المهلة من الإعدادات — التاريخ ونصّه من رقمٍ واحد
        ], $request->user());

        $case->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة العليا',
            'role' => 'أتعاب',
            'body' => '<p>تم تحديد واعتماد أتعاب القضية وإصدار الفاتورة للعميل، وتُفعّل القضية فور إتمام السداد.</p>',
            'time_label' => $this->clock(),
        ]);

        Notify::send($case->user_id, 'card', 't-amber', "صدرت فاتورة أتعاب قضيتك {$case->number} بمبلغ {$total} ر.س. سدّدها لتفعيل القضية.");

        Audit::log(
            action: 'تحديد أتعاب القضية',
            description: "حدّدت الإدارة ({$request->user()->name}) أتعاب القضية {$case->number} بمبلغ {$data['fee']} ر.س (الإجمالي مع الضريبة {$total} ر.س).",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => 'بانتظار اعتماد الأتعاب'],
            afterState: [
                'الحالة' => 'بانتظار سداد الأتعاب',
                'الأتعاب' => $data['fee'],
                'الضريبة' => $vat,
                'الإجمالي' => $total,
                'نسبة_المحامي' => $pct,
                'أتعاب_المحامي' => $lawyerFee,
            ],
        );

        // بريد للعميل بتحديد الأتعاب وإصدار الفاتورة (أفضل-جهد — لا يعطّل الطلب إن فشل)
        $case->loadMissing('user');
        if ($case->user?->email) {
            app(MailService::class)->send($case->user, new CaseFeeSetMail($case, $total));
        }

        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // إشراف الإدارة على كل القضايا
    public function index(): Response
    {
        $rawCases = LegalCase::with(['user', 'hearings', 'ticket', 'assignedLawyer'])
            ->latest('id')
            ->get();

        $cases = $rawCases->map(function (LegalCase $c) {
            $nextHearing = $c->nextHearingLive();

            return [
                'id' => $c->id,
                'no' => $c->number,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'realClientName' => $c->user?->name ?? 'عميل المنصة',
                'type' => $c->type ?: 'قضية عامة',
                'dept' => $c->department ?: ($c->ticket?->department ?: 'القسم العام'),
                'lawyer' => $c->assigned_lawyer ?: ($c->assignedLawyer?->name ?: '—'),
                'lawyerId' => $c->assigned_lawyer_id,
                'status' => $c->status,
                'tone' => $c->tone ?: 'b-blue',
                'courtName' => $c->ticket?->court_name ?: 'المحكمة المختصة',
                'claimAmount' => $c->ticket?->claim_amount,
                'opponent' => $c->ticket?->opponent_name,
                'fee' => $c->fee,
                'feeStatus' => $c->fee_status,
                'updateText' => $c->update_text,
                'ruling' => $c->ruling,
                'hearingsCount' => $c->hearings->count(),
                // `session_date` لا عمود له — الموعد بصياغته الموحّدة (`CaseHearing::label`) حين لا طابع زمنيّ
                'nextHearingDate' => $nextHearing ? ($nextHearing->starts_at?->format('Y-m-d H:i') ?? $nextHearing->label()) : null,
                'nextHearingNotes' => $nextHearing?->notes,
                'date' => $c->created_at?->format('Y-m-d'),
                'canClose' => $c->status === 'صدر الحكم',
                'canArchive' => $c->status === 'مغلقة',
                'canExecute' => ExecutionCreation::isEligible($c),
            ];
        });

        // تصنيفات أنواع القضايا
        $types = $cases->groupBy('type')->map(fn ($group, $name) => [
            'name' => $name ?: 'عامة',
            'count' => $group->count(),
        ])->values()->all();

        // مؤشرات أداء القضايا — مجموعاتها من `CaseJourney`
        $tabs = CaseJourney::adminTabs();
        $kpis = [
            'total' => $cases->count(),
            // «قيد الترافع» كان يعدّ أربع حالاتٍ لا يكتبها أيّ مسار — صفرٌ أبداً
            'active' => $cases->whereIn('status', $tabs['active'])->count(),
            'judged' => $cases->whereIn('status', $tabs['judged'])->count(),
            'closed' => $cases->whereIn('status', $tabs['closed'])->count(),
            'pendingFee' => $cases->whereIn('status', $tabs['pendingFee'])->count(),
        ];

        return Inertia::render('admin/cases', [
            'cases' => $cases,
            'types' => $types,
            'kpis' => $kpis,
            'tabs' => $tabs,
            // أسباب الإغلاق من الكتالوج (`ClosureCaseReasonCode::options`) — نافذة القائمة والتفاصيل من مصدرٍ واحد
            'closureReasons' => ClosureCaseReasonCode::options(),
        ]);
    }

    /**
     * **صفحة تفاصيل القضيّة للإدارة** (قرار المالك 2026-09-11). كانت الإدارة تحدّد الأتعاب
     * وتُغلق وتؤرشف ولا ترى المحادثة ولا الجلسات ولا المستندات — حارسُ الأدوار يمنعها من
     * مسارات المحامي. اطّلاعٌ كامل (بما فيه المحجوب والملاحظات الداخليّة) وأزرارُها
     * الإداريّة — ولا تعديلَ للجلسات: هي عملُ المحامي المسنَد.
     */
    public function show(Request $request, LegalCase $case): Response
    {
        $case->load(['user', 'hearings', 'documents', 'assignedLawyer']);

        return Inertia::render('admin/case', [
            // من يتولّى المحادثة الآن ومن تولّاها قبله — للطاقم وحده (`ConversationHandler`)
            'conversation' => ConversationHandler::history($case),
            'case' => [
                'no' => $case->number,
                'client' => $case->user?->name ?? '—',
                'type' => $case->type,
                'dept' => $case->department,
                'lawyer' => $case->assigned_lawyer ?: ($case->assignedLawyer?->name ?? '—'),
                'lawyerId' => $case->assigned_lawyer_id,
                'status' => $case->status,
                'tone' => $case->tone ?: CaseJourney::toneFor($case->status),
                'next' => $case->nextHearingLabel(),
                'pleadingStatus' => $case->pleading_status,
                'ruling' => $case->ruling,
                'fee' => $case->fee,
                'feeStatus' => $case->fee_status,
                'invoice' => $case->invoice_text,
                // مقترح التصنيف الذكيّ بانتظار المراجعة — يُعرض ولا يُطبَّق هنا
                'aiClassification' => $case->ai_classification,
                'najiz' => $case->najizCard(),
                'appeal' => $case->appealCard(),
                'canClose' => $case->status === 'صدر الحكم',
                'canArchive' => $case->status === 'مغلقة',
                // حكم الانتقال نفسه (`ReopenCase`: حالته المصدر + صلاحيّة الفاعل) — لا «status === مغلقة» في الواجهة
                'canReopen' => (new ReopenCaseTransition)->accepts((string) $case->status)
                    && (new ReopenCaseTransition)->deny($case, $request->user()) === null,
                'canExecute' => ExecutionCreation::isEligible($case),
                'canReassign' => $case->status !== 'مؤرشفة',
                'feePending' => $case->status === 'بانتظار اعتماد الأتعاب',
            ],
            'channel' => 'case.'.$case->id,
            // الإدارة ترى ما يراه المكتب: الملاحظات الداخليّة والمحجوب بانتظار الاعتماد
            'messages' => ConversationFiles::linkLegacyChips($case->messages()->visibleTo(true)->get()->map->toMessage()->all(), 'case', $case->documents),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $case->documents->map(fn ($d) => $d->toData($request->user())),
            'convertedExec' => $case->execution()->exists(),
            'closureReasons' => ClosureCaseReasonCode::options(),
            // لإعادة الإسناد — المحامون النشطون وحدهم (قاعدة `ActiveLawyer` نفسها)
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get()
                ->filter(fn (User $u) => $u->isActive())
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values(),
        ]);
    }

    // الإغلاق بعد الحكم مع التسبيب النظامي (المرحلة ب — 2026-09-16)
    public function closeCase(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'صدر الحكم', 422, 'تُغلق القضية بعد صدور الحكم فقط.');

        // **السبب إلزاميّ ورمزٌ من الكتالوج** — كان اختياريّاً يسقط إلى «حكم نهائي» حين يغيب، فزرّ
        // الإغلاق في القائمة (يرسل `{}`) كان يسجّل كلّ إغلاقٍ «صدور حكم نهائي» وإن كان صلحاً أو تنازلاً.
        // وخريطة التسميات العربيّة القديمة أُزيلت: لا واجهة ترسلها، والواجهتان ترسلان الرمز.
        $data = $request->validate([
            'closure_reason' => ['required', 'string', Rule::in(ClosureCaseReasonCode::values())],
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['closure_reason' => 'سبب الإغلاق']);

        $reasonEnum = ClosureCaseReasonCode::from($data['closure_reason']);
        $notes = $data['closure_notes'] ?? null;
        $reasonLabel = $reasonEnum->label();

        // «وتنفيذه» تُقال حين يوجد تنفيذٌ فعلاً — والإغلاق قبله لم يعد يمنعه
        $executed = $case->execution()->exists();

        Workflow::run(new CloseCaseTransition, $case, $request->user(), [
            'closure_reason' => $reasonEnum->value,
            'closure_notes' => $notes,
            'reason' => $reasonLabel,
        ]);

        $bodyNote = '<p>بعد صدور الحكم'.($executed ? ' وتنفيذه' : '').'، أغلقت الإدارة القضية.</p>'
            .'<div class="result-card"><div class="result-sec">'
            .'<div class="t">سبب الإغلاق</div><div>'.e($reasonLabel).'</div>'
            .($notes ? '<div class="t" style="margin-top:8px">ملاحظات الإغلاق</div><div style="white-space:pre-line">'.e($notes).'</div>' : '')
            .'</div></div>'
            .'<p>'.($executed ? 'وستُؤرشف القضية نهائياً بعد استيفاء كامل الإجراءات.' : 'ويبقى فتح طلب تنفيذ الحكم متاحاً قبل الأرشفة.').'</p>';

        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'إغلاق',
            'body' => $bodyNote,
            'time_label' => $this->clock(),
        ]);
        Notify::send($case->user_id, 'check', 't-green', "أُغلقت قضيتك {$case->number} ({$reasonLabel}).");
        Audit::log(
            action: 'إغلاق قضية',
            description: 'أغلقت الإدارة ('.(auth()->user()?->name ?? '—').") القضية {$case->number} — السبب: {$reasonLabel}".($executed ? ' بعد تنفيذ الحكم.' : ' — بلا تنفيذٍ مفتوح بعد.'),
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => 'صدر الحكم'],
            afterState: [
                'الحالة' => 'مغلقة',
                'سبب_الإغلاق' => $reasonEnum->value,
                'وصف_السبب' => $reasonLabel,
                'ملاحظات_الإغلاق' => $notes,
            ],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // إعادة فتح القضية المغلقة (المرحلة ب — 2026-09-16)
    public function reopenCase(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'مغلقة', 422, 'لا يُعاد فتح إلا القضايا المغلقة قبل أرشفتها.');

        if (! $request->filled('reason') && ($request->filled('reopen_reason') || $request->filled('reopen_notes'))) {
            $combined = trim($request->input('reopen_reason', '').' — '.$request->input('reopen_notes', ''));
            $request->merge(['reason' => $combined]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $actor = $request->user();
        $prevReason = $case->closure_reason;

        Workflow::run(new ReopenCaseTransition, $case, $actor, [
            'reason' => $data['reason'],
        ]);

        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'إعادة فتح',
            'body' => '<p>أعادت الإدارة فتح القضية لمتابعة الإجراءات.</p>'
                .'<div class="result-card"><div class="result-sec">'
                .'<div class="t">سبب إعادة الفتح</div><div style="white-space:pre-line">'.e($data['reason']).'</div>'
                .'</div></div>',
            'time_label' => $this->clock(),
        ]);

        Notify::send($case->user_id, 'scale', 't-blue', "أُعيد فتح ملف قضيتك {$case->number} لاستكمال الإجراءات.");
        if ($case->assigned_lawyer_id) {
            Notify::send($case->assigned_lawyer_id, 'scale', 't-blue', "أعادت الإدارة فتح القضية {$case->number}.");
        }

        Audit::log(
            action: 'إعادة فتح قضية',
            description: "أعادت الإدارة ({$actor->name}) فتح القضية {$case->number} بعد إغلاقها — السبب: {$data['reason']}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => 'مغلقة', 'سبب_الإغلاق_السابق' => $prevReason],
            afterState: ['الحالة' => 'صدر الحكم', 'سبب_إعادة_الفتح' => $data['reason']],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back()->with('flash', "أُعيد فتح القضية {$case->number} بنجاح.");
    }

    // الأرشفة النهائية — تُحفظ القضية المغلقة في الأرشيف القانوني (خطوة إدارية صريحة بعد الإغلاق)
    public function archiveCase(LegalCase $case): RedirectResponse
    {
        Workflow::run(new ArchiveCaseTransition, $case, auth()->user());
        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'أرشفة',
            'body' => '<p>تمت أرشفة ملف القضية نهائياً في سجلات الأرشيف القانوني الموثقة.</p>',
            'time_label' => $this->clock(),
        ]);
        Notify::send($case->user_id, 'check', 't-green', "أُرشفت قضيتك {$case->number} وحُفظ ملفها في الأرشيف.");
        Audit::log(
            action: 'أرشفة قضية',
            description: 'أرشفت الإدارة ('.(auth()->user()?->name ?? '—').") القضية {$case->number} نهائياً.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => 'مغلقة'],
            afterState: ['الحالة' => 'مؤرشفة'],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    /**
     * إعادة إسناد محامي القضيّة — لم يكن للمنظومة مسارٌ لها، وحذفُ حساب المحامي يُفرغ
     * `assigned_lawyer_id` فتبقى القضيّة بلا محامٍ. على نمط `DistributeController::assign`.
     */
    public function reassignLawyer(Request $request, LegalCase $case): RedirectResponse
    {
        abort_if($case->status === 'مؤرشفة', 422, 'القضية مؤرشفة — لا يُعاد إسنادها.');

        $data = $request->validate(['lawyer_id' => ['required', 'integer', new ActiveLawyer]]);
        $lawyer = User::findOrFail($data['lawyer_id']);
        $before = $case->assigned_lawyer ?: ($case->assignedLawyer?->name ?? '—');

        $case->update(['assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id]);

        // التنفيذ المفتوح يحتفظ بمحاميه — له إسنادُه المستقلّ
        $execNote = $case->execution()->exists() ? ' ويحتفظ طلب التنفيذ المفتوح بمحاميه.' : '';
        $case->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'إسناد',
            'body' => '<p>أعادت الإدارة إسناد القضية إلى '.e($lawyer->name).'.'.e($execNote).'</p>',
            'time_label' => $this->clock(),
        ]);
        Notify::send($lawyer->id, 'scale', 't-blue', "أُسندت إليك القضية {$case->number}.");
        Audit::log(
            action: 'إعادة إسناد قضية',
            description: "أعاد {$request->user()->name} إسناد القضية {$case->number} من {$before} إلى {$lawyer->name}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['المحامي' => $before],
            afterState: ['المحامي' => $lawyer->name],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back()->with('flash', "أُسندت القضية {$case->number} إلى {$lawyer->name}.");
    }

    // التحويل للتنفيذ (يطابق cfExecute)
    // كان معطوباً مزدوجاً: المسار يشير لاسم دالّة غير موجود (convertToExecution) فلا يصل هنا
    // أصلاً، والجسم ينادي createFor غير المعرَّفة — الصحيح fromCase (نفس نظيرة المحامي)
    public function execute(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless(ExecutionCreation::isEligible($case), 422, 'التحويل للتنفيذ متاح للقضايا الصادر حكمها ولم يُفتح لها تنفيذ بعد.');

        $exec = ExecutionCreation::fromCase($case, $request->user());

        // وجهة الملف المفتوح لا الصفحة السابقة — نظير مسار المحامي (lawyer.execs)، والعقد موثّق باختبار
        // `?id=` يفتح الملفّ نفسه (`execflow.resolveTarget`) — بدونه تهبط الإدارة على القائمة كلّها
        return redirect()->route('admin.execs', ['id' => $exec->number])->with('flash', "فُتح طلب التنفيذ {$exec->number} للقضية {$case->number}.");
    }

    private function clock(): string
    {
        return now()->locale('ar')->translatedFormat('h:i A');
    }
}
