<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCaseDocumentJob;
use App\Jobs\GenerateCaseReplyJob;
use App\Models\CaseMessage;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\LegalAiService;
use App\Services\Payments\GatewayCallback;
use App\Services\Payments\PaymentGateways;
use App\Support\CaseFee;
use App\Support\CaseJourney;
use App\Support\CaseTicketDocuments;
use App\Support\ConversationFiles;
use App\Support\LawyerName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function __construct(private LegalAiService $ai) {}

    // قائمة قضايا العميل الحالي
    public function index(Request $request): Response
    {
        $casesRaw = LegalCase::with(['hearings', 'documents', 'assignedLawyer'])
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        $cases = $casesRaw->map(fn (LegalCase $c) => $c->toCard());

        $upcomingHearingsList = $casesRaw->flatMap(function (LegalCase $c) {
            $next = $c->nextHearingLive();
            if (! $next) {
                return [];
            }

            return [[
                'id' => $next->id,
                'caseNo' => $c->number,
                'caseType' => $c->type,
                'title' => $next->title,
                'court' => $next->court,
                'day' => $next->day,
                'startsAt' => $next->starts_at?->format('Y-m-d H:i'),
                'label' => $next->label(),
                'status' => $next->status,
                'lawyer' => LawyerName::forClient($c->assigned_lawyer_id ? $c->assignedLawyer : null, $c->assigned_lawyer, 'المستشار المختص'),
            ]];
        })->values();

        // من `CaseJourney` لا من قائمةٍ باليد: كانت تعدّ «قيد الترافع» و«محكومة» ولا يكتبهما مسار،
        // وتُسقط «قيد التحضير» و«صدر الحكم» و«مؤرشفة» من كلّ تبويب
        $tabs = CaseJourney::clientTabs();
        $counts = [
            'total' => $cases->count(),
            'active' => $cases->whereIn('status', $tabs['active'])->count(),
            'upcomingHearings' => $upcomingHearingsList->count(),
            'pendingFees' => $cases->whereIn('status', $tabs['fees'])->count(),
            'completed' => $cases->whereIn('status', $tabs['completed'])->count(),
        ];

        return Inertia::render('cases', [
            'cases' => $cases,
            'counts' => $counts,
            'tabs' => $tabs,
            'upcomingHearings' => $upcomingHearingsList,
        ]);
    }

    // متابعة قضية واحدة
    public function show(Request $request, LegalCase $case): Response
    {
        $this->authorizeCase($request, $case);
        $case->load('hearings');

        return Inertia::render('casechat', [
            'case' => [
                'no' => $case->number,
                'type' => $case->type,
                'status' => $case->status,
                'tone' => $case->tone,
                ...$case->stateFlags(),
                'update' => $case->update_text,
                'next' => $case->nextHearingLabel(),
                'invoice' => $case->invoice_text,
                'paid' => $case->paid_text,
                'fee' => $case->fee,
                'feeStatus' => $case->fee_status,
                'installmentsPaid' => $case->installments_paid,
                'installmentsTotal' => $case->installments_total,
                // بيانات الرفع والقيد في ناجز (الخطّة ب) — رقم القضيّة يصل العميل بعد القيد
                'najiz' => $case->najizCard(),
                'appeal' => $case->appealCard(),
            ],
            'channel' => 'case.'.$case->id,
            // العميل: بلا ملاحظات داخليّة وبلا مخرجٍ محجوب بانتظار اعتماد محامٍ
            'messages' => ConversationFiles::linkLegacyChips($case->messages()->visibleTo(false)->get()->map(fn (CaseMessage $m) => $m->toMessage(forClient: true))->all(), 'case', $case->documents),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $case->documents->map(fn ($d) => $d->toData(auth()->user())),
            // مرفقاته هو قبل التحويل — كانت قضيّته المحوَّلة تبدأ بلا مستند
            'ticketDocuments' => CaseTicketDocuments::for($case, auth()->user()),
        ]);
    }

    // إرفاق مستند حقيقي من العميل إلى ملف القضية (رفع ملف + رسالة + بثّ) — يُخزَّن ضمن مستندات القضية.
    public function attach(Request $request, LegalCase $case): HttpResponse
    {
        $this->authorizeCase($request, $case);
        abort_if(! $case->isActive(), 422, 'لا يمكن إرفاق مستندات على قضية مغلقة أو مؤرشفة.');

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx']]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $doc = $case->documents()->create([
            'name' => $name,
            'path' => $file->store("case-docs/{$case->id}"),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => 'client',
            'status' => 'قيد الفحص',
        ]);

        // رسالة في محادثة القضية (تُبثّ لحظياً تلقائياً عبر CaseMessage::booted)
        $case->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list">'.ConversationFiles::chip('case', $doc).'</div>',
            'time_label' => $this->clock(),
        ]);
        $case->update(['update_text' => 'أرفق العميل مستنداً: '.$name]);

        // تحليل ذكي للمستند بالخلفية (تلخيص + تصنيف ثم ملخّص في المحادثة)
        AnalyzeCaseDocumentJob::dispatch($case, $doc);

        return response()->noContent();
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق (بثّ لحظي بلا إعادة تحميل)
    public function storeMessage(Request $request, LegalCase $case): HttpResponse
    {
        $this->authorizeCase($request, $case);

        abort_if(
            ! $case->isActive(),
            422,
            'لا يمكن إرسال رسائل على قضية مغلقة أو مؤرشفة.'
        );

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $case->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => e($data['body']),
            'time_label' => $this->clock(),
        ]);

        $body = $data['body'];
        if ($request->user()->isClient()) {
            GenerateCaseReplyJob::dispatch($case, $body);
        }

        return response()->noContent();
    }

    // سداد أتعاب القضية → تفعيلها. السداد الكامل عبر بوّابة الدفع (503 إن غابت)؛ الأقساط ميزة مستقلّة.
    public function pay(Request $request, LegalCase $case): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'pending_payment', 422, 'لا أتعاب مستحقّة السداد على هذه القضية الآن — حدّث الصفحة.');

        $plan = $request->validate(['plan' => ['nullable', 'in:full,install']])['plan'] ?? 'full';

        // كلا الخطّتين تمرّان بالبوّابة. كان التقسيط «ميزة مستقلّة» تُفعّل القضية بنقرة
        // وتكتب «تم استلام الدفعة الأولى» بلا بوّابة ولا فاتورة ولا صفّ دفع.
        abort_unless(app(PaymentGateways::class)->default()->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        // فتح الخطّة يُعيد هيكلة الفاتورة إلى دفعات حقيقية — بلا تفعيل: التفعيل بالسداد
        if ($plan === 'install' && CaseFee::openInstallmentPlan($case) === null) {
            return back()->with('error', 'تعذّر فتح خطّة التقسيط — لا توجد فاتورة أتعاب مستحقّة.');
        }

        $callback = $request->getSchemeAndHttpHost().route('cases.pay.callback', $case, absolute: false);
        $url = CaseFee::initiatePayment($case->fresh(), $callback);
        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        return Inertia::location($url);
    }

    // العودة من صفحة الدفع (سداد الأتعاب) — تحقّق خادميّ صارم ثم تسوية الفاتورة وتفعيل القضية.
    public function payCallback(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);

        // اربط الدفعة بفاتورة هذه القضية تحديدًا (لا تسوية دفعة تخصّ فاتورة أخرى).
        // **والمطابقة بأيّ فاتورةٍ لهذه القضيّة لا بأحدثها**: بعد فتح خطّة التقسيط صارت
        // للقضيّة ثلاث فواتير، فيعود العميل من سداد الدفعة الأولى ومرجعُ البوّابة على
        // فاتورتها بينما `latest('id')` هي الثالثة — فلا يُطابَق، ويُقال له «تعذّر تأكيد
        // الدفع» على مالٍ خُصم فعلاً.
        if (GatewayCallback::confirm($request, Invoice::where('case_id', $case->id))) {
            return redirect()->route('cases.show', $case)->with('success', 'تم تأكيد سداد الأتعاب وتفعيل القضية.');
        }

        return redirect()->route('cases.show', $case)->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    // سداد دفعة تالية من الأقساط
    public function payInstallment(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'installments', 422, 'أتعاب هذه القضية ليست مقسّطة، أو سُدّدت أقساطها كاملة.');

        // كان هذا الزرّ يزيد العدّاد ويكتب «تم استلام الدفعة» بلا أي سداد. الآن يبدأ دفعة
        // حقيقية على فاتورة القسط المستحقّ، والتقدّم يقع في PaymentReconciler عند التسوية.
        $gateway = app(PaymentGateways::class)->default();
        abort_unless($gateway->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        $next = CaseFee::nextInstallment($case);
        if ($next === null) {
            return back()->with('error', 'لا توجد دفعة مستحقّة على هذه القضية.');
        }

        $callback = $request->getSchemeAndHttpHost().route('cases.pay.callback', $case, absolute: false);
        $url = $gateway->hostedUrlForInvoice($next, $callback);

        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        return Inertia::location($url);
    }

    private function authorizeCase(Request $request, LegalCase $case): void
    {
        abort_unless($case->user_id === $request->user()->id, 403);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
