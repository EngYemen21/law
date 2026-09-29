<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Transitions\Invoice\CancelInvoice;
use App\Domain\Journey\Transitions\Invoice\IssueInvoice;
use App\Domain\Journey\Transitions\Invoice\RejectPaymentProof;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Transitions\Invoice\WriteOffInvoice;
use App\Domain\Journey\Workflow;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Audit;
use App\Support\Finance\Expenses;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\PaymentVoucherDocument;
use App\Support\Finance\ReceiptVoucherDocument;
use App\Support\Notify;
use App\Support\Paginate;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **شاشة «المالية والمحاسبة» `/admin/finance`** — م٣ من خطّة النظام الماليّ.
 *
 * خلفت شاشة الفواتير القديمة `/admin/accounting`، وحُذف مسارها (قرار المالك 2026-09-28): تبويب
 * «الفواتير» هنا مكانها الوحيد.
 *
 * ── **التبويب خادميٌّ عبر `?tab=`** ──────────────────────────────────────────────────
 *
 * كما هي `?filter=` في الشاشة القديمة، وللسبب نفسه: **الترقيم**. تبويبٌ يُرشَّح في المتصفّح
 * يرشّح **الصفحة الحاليّة وحدها** — فيُظهر «المدفوعة» من الخمسين المعروضة ويُخفي الباقي،
 * ويعرض عدّاداً كاذباً. والخادم يرشّح الجدول كلَّه ويرقّم النتيجة.
 *
 * **ولا يُحسب إلّا التبويب المطلوب**: حمولةُ كلّ تبويبٍ استعلاماتُها هي، فلا تُنفَّذ استعلامات
 * الأعمار والضريبة على من فتح الفواتير.
 *
 * ── **الصلاحيّة: حراسةُ الدور وحدها، عن قصد** ───────────────────────────────────────
 *
 * لم تُستحدث صلاحيّة «المالية والمحاسبة» — انظر تعليل المسار في `routes/web.php` والقسم ٢٤
 * في `HANDOVER.md`. مختصرُه: كلّ مسارات `/admin/*` داخل `role:admin`، والأدمن يتجاوز كلّ
 * صلاحيّة بـ`Gate::before`، وغيرُ الأدمن لا يبلغ المجموعة أصلاً — فالصلاحيّة تظهر في شاشة
 * الصلاحيّات ولا تفتح باباً. وهي سابقةٌ وقعت مع «أرشيف الاستشارات» ويحرسها
 * `PermissionReachabilityTest`.
 *
 * ── **والحالات تُكتب بالانتقالات لا مباشرةً** ────────────────────────────────────────
 *
 * كلُّ إجراءٍ هنا ينادي انتقالاً في `Domain/Journey/Transitions/Invoice`، فيُسجَّل صفٌّ في
 * `journey_transitions` ويُرفَض ما لا يجوز بحارسه هو. و`StateWriteGuard` يردّ أيّ كتابةٍ
 * مباشرة على `status`/`paid` منذ م٢.
 */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = FinanceBoard::tab($request->query('tab'));
        $period = FinanceBoard::period(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
        );

        $status = (string) $request->query('status', 'all');
        $kind = (string) $request->query('kind', 'all');
        $kind = isset(FinanceBoard::KINDS[$kind]) ? $kind : 'all';

        return Inertia::render('admin/finance', [
            'tab' => $tab,
            'tabs' => self::asList(FinanceBoard::TABS),
            'periods' => self::asList(FinanceBoard::PERIODS),
            'period' => [
                'key' => $period['key'],
                'label' => $period['label'],
                'from' => $period['fromDate'],
                'to' => $period['toDate'],
            ],
            'status' => $status,
            'kind' => $kind,
            'statuses' => FinanceBoard::statusFilters(),
            'kinds' => self::asList(FinanceBoard::KINDS),
            'buckets' => FinanceBoard::AGING_BUCKETS,
        ] + $this->payloadFor($tab, $request, $period, $status, $kind));
    }

    /**
     * حمولة التبويب المطلوب وحده — البقيّة `null` فلا تُنفَّذ استعلاماتُها.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     */
    private function payloadFor(string $tab, Request $request, array $period, string $status, string $kind): array
    {
        $payload = match ($tab) {
            'invoices' => ['invoices' => $this->invoicesPayload($request, $period, $status, $kind)],
            'receipts' => ['receipts' => [
                'rows' => Paginate::shape(
                    FinanceBoard::receipts($period),
                    fn (Payment $p) => FinanceBoard::receiptRow($p),
                ),
                'total' => FinanceBoard::receiptsTotal($period),
            ]],
            'expenses' => ['expenses' => $this->expensesPayload($request, $period)],
            'aging' => ['aging' => ['rows' => FinanceBoard::debtorsByClient()]],
            'vat' => ['vat' => FinanceBoard::vat($period) + [
                'invoices' => Paginate::shape(
                    FinanceBoard::vatInvoices($period),
                    fn (Invoice $v) => FinanceBoard::invoiceRow($v),
                ),
            ]],
            // «التقارير» روابطُ نقلٍ إلى الشاشتين القائمتين — لا تكرارَ لحسابٍ يعيش هناك
            'reports' => [],
            default => ['dashboard' => FinanceBoard::dashboard($period)],
        };

        // المفاتيح الباقية `null` صراحةً: الواجهة تقرؤها كلَّها، وغيابُ مفتاحٍ يعني `undefined`
        // لا «لا حمولة». و`+` يُبقي ما في الطرف الأيسر، فالمحسوب أوّلاً والفراغ بعده.
        return $payload + ['dashboard' => null, 'invoices' => null, 'receipts' => null, 'expenses' => null, 'aging' => null, 'vat' => null];
    }

    /**
     * تبويب المصروفات: الجدول بمرشّحيه (`estatus` · `category` — لا `status` الذي يخصّ الفواتير)،
     * وأرقام الرأس، وخيارات نموذج التسجيل من مصادرها (التصنيف · الحالة · جهة الدفع).
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return array<string, mixed>
     */
    private function expensesPayload(Request $request, array $period): array
    {
        $status = (string) $request->query('estatus', 'all');
        $category = (string) $request->query('category', 'all');

        return [
            'rows' => Paginate::shape(
                FinanceBoard::expenses($period, $status, $category),
                fn (Expense $e) => FinanceBoard::expenseRow($e, forAdmin: true),
            ),
            'summary' => FinanceBoard::expensesSummary($period),
            'status' => $status,
            'category' => $category,
            'statuses' => ExpenseStatus::options(),
            'categories' => ExpenseCategory::options(),
            'paidFrom' => Expense::paidFromOptions(),
        ];
    }

    /**
     * جدول الفواتير + الأزرار المتاحة لكلّ صفّ.
     *
     * **الأزرار من `Workflow::allowed` لا من شروطٍ في الواجهة**: الحارس الذي يقبل الإجراء هو
     * نفسه الذي يقرّر ظهور زرّه، فلا يَعِد زرٌّ بما يردّه الخادم. والانتقالات تُنشأ مرّةً لا
     * لكلّ صفّ.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     */
    private function invoicesPayload(Request $request, array $period, string $status, string $kind): array
    {
        $transitions = [new IssueInvoice, new SettleInvoice, new CancelInvoice, new WriteOffInvoice];
        $actor = $request->user();

        return Paginate::shape(
            FinanceBoard::invoices($period, $status, $kind),
            fn (Invoice $v) => FinanceBoard::invoiceRow($v, Workflow::allowed($v, $actor, $transitions)),
        );
    }

    /** @param  array<string,string>  $map */
    private static function asList(array $map): array
    {
        $out = [];
        foreach ($map as $k => $label) {
            $out[] = ['k' => $k, 'label' => $label];
        }

        return $out;
    }

    // ─────────────────────────────── إجراءات تبويب الفواتير ───────────────────────────────

    /** تحصيل يدويّ (نقد/تحويل خارج البوّابة) — يقيّد الدفتر ويمنع التحصيل المكرّر. */
    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        // طريقة القبض تُطبع على سند القبض — نقداً أو تحويلاً (البوّابة لا تُحصَّل من هنا)
        $data = $request->validate(['method' => ['nullable', Rule::in(Payment::MANUAL_METHODS)]]);

        if (! PaymentReconciler::settleManual($invoice, $request->user()->name, $request->user(), $data['method'] ?? null)) {
            // **رفضٌ لا نجاحٌ بلونٍ آخر**: `back()->with('error')` تحويلٌ ناجح، فكانت الواجهة تُطلق
            // «تم تسجيل التحصيل» ورسالة الخادم «محصّلة مسبقاً» معاً. خطأ تحقّقٍ يبلغ `onError` وحده.
            // والرفض يقول سببه: `settleManual` يرفض المدفوعة **والملغاة** — «محصّلة مسبقاً» لملغاةٍ كان كذباً
            throw ValidationException::withMessages([
                'invoice' => $invoice->paid
                    ? "الفاتورة {$invoice->number} محصّلة مسبقاً."
                    : "الفاتورة {$invoice->number} ملغاة — لا تُحصَّل.",
            ]);
        }

        return back()->with('flash', "تم تسجيل تحصيل الفاتورة {$invoice->number}.");
    }

    /**
     * **إصدار المسوّدة** ⇐ «مستحقة» — اللحظة التي تصير فيها الفاتورة مطالبةً يراها العميل.
     *
     * حالة «مسوّدة» أُضيفت في م٢ بلا شاشةٍ تُخرجها منها، فكانت طريقاً مسدوداً. وهذا بابُها.
     */
    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        Workflow::run(new IssueInvoice, $invoice, $request->user());

        Audit::log(
            action: 'إصدار فاتورة',
            description: "أصدر {$request->user()->name} الفاتورة {$invoice->number} للعميل.",
            category: 'مالية وفواتير',
            auditable: $invoice,
            auditableRef: $invoice->number,
        );

        Notify::send($invoice->user_id, 'card', 't-blue', "صدرت لك الفاتورة {$invoice->number} بمبلغ ".number_format((int) $invoice->amount).' ر.س.');

        return back()->with('flash', "أُصدرت الفاتورة {$invoice->number} وأُشعر العميل.");
    }

    /** إلغاء الفاتورة — والسبب اختياريّ (قد يكون إعادة تسعيرٍ لا خطأً). */
    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $reason = trim($data['reason'] ?? '');

        Workflow::run(new CancelInvoice, $invoice, $request->user(), ['reason' => $reason]);

        Audit::log(
            action: 'إلغاء فاتورة',
            description: "ألغى {$request->user()->name} الفاتورة {$invoice->number}".($reason !== '' ? " — السبب: {$reason}" : '').'.',
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $invoice,
            auditableRef: $invoice->number,
        );

        Notify::send($invoice->user_id, 'card', 't-grey', "أُلغيت الفاتورة {$invoice->number}".($reason !== '' ? " — السبب: {$reason}" : '').'.');

        return back()->with('flash', "أُلغيت الفاتورة {$invoice->number}.");
    }

    /**
     * **شطب الدين** ⇐ «دين معدوم» — **والسبب إلزاميّ**.
     *
     * إسقاطُ مالٍ للمكتب حقٌّ فيه، وقيدٌ بلا تعليل لا يُدافَع عنه عند مراجعة. والانتقال يرفضه
     * كذلك (‏`WriteOffInvoice::guard`) — والتحقّق هنا يعطي رسالة حقلٍ بدل صفحة ٤٢٢.
     *
     * **ولا يُشعَر العميل**: الشطب قرارٌ محاسبيٌّ داخليّ بإسقاط المطالبة، وإخبارُ المدين بأنّ
     * دينه أُسقط قرارُ مكتبٍ لا أثرٌ تقنيّ — ولم يُطلب.
     */
    public function writeOff(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ], [], ['reason' => 'سبب شطب الدين']);

        Workflow::run(new WriteOffInvoice, $invoice, $request->user(), ['reason' => trim($data['reason'])]);

        Audit::log(
            action: 'شطب دين معدوم',
            description: "شطب {$request->user()->name} الفاتورة {$invoice->number} ديناً معدوماً — السبب: ".trim($data['reason']).'.',
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $invoice,
            auditableRef: $invoice->number,
        );

        return back()->with('flash', "شُطبت الفاتورة {$invoice->number} ديناً معدوماً.");
    }

    /**
     * تنزيل إثبات التحويل اليدويّ الذي رفعه العميل.
     *
     * كان `proof_path` يُكتب ولا يقرؤه شيء في المشروع كلّه — لا مسار ولا مكوّن — فحالة
     * «بانتظار مراجعة الإثبات» طريق مسدود: المراجع يرى أن إثباتاً رُفع ولا يستطيع فتحه
     * ليقرّر التحصيل. للإدارة وحدها (المجموعة محروسة بـrole:admin).
     */
    // ── المصروفات (المرحلة ب) — كلّ قاعدةٍ في `Finance\Expenses`، والمتحكّم يمرّر ──

    public function storeExpense(Request $request): RedirectResponse
    {
        [$rules, $messages] = Expenses::rules();
        $data = $request->validate($rules, $messages);
        $expense = Expenses::record($request->user(), $data, $request->file('document'));

        return back()->with('flash', "سُجّل المصروف واعتُمد — سند الصرف {$expense->voucher_no}.");
    }

    public function approveExpense(Request $request, Expense $expense): RedirectResponse
    {
        Expenses::approve($expense, $request->user());

        return back()->with('flash', "اعتُمد المصروف — سند الصرف {$expense->voucher_no}.");
    }

    public function rejectExpense(Request $request, Expense $expense): RedirectResponse
    {
        Expenses::reject($expense, $request->user(), self::reason($request));

        return back()->with('flash', 'رُفض المصروف وأُبلغ من سجّله.');
    }

    public function voidExpense(Request $request, Expense $expense): RedirectResponse
    {
        Expenses::void($expense, $request->user(), self::reason($request));

        return back()->with('flash', "أُلغي المصروف {$expense->voucher_no} — يبقى سنده في الدفتر بحالته.");
    }

    public function expenseDocument(Expense $expense): StreamedResponse
    {
        abort_if($expense->document_path === null || ! Storage::exists($expense->document_path), 404);

        return Storage::download($expense->document_path);
    }

    /** سند الصرف PDF — للمصروف الذي اعتُمد مرّةً فحمل رقماً (والملغى يُطبع بحالته). */
    public function expenseVoucher(Expense $expense): \Symfony\Component\HttpFoundation\Response
    {
        abort_if($expense->voucher_no === null, 404);

        return PdfRenderer::render(PaymentVoucherDocument::forExpense($expense), $expense->voucher_no.'.pdf');
    }

    private static function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'اكتب السبب.', 'reason.min' => 'اكتب السبب بوضوح (5 أحرف على الأقل).'],
        )['reason'];
    }

    /** سند القبض PDF — للدفعة الناجحة التي مُنحت رقماً (`ReceiptVoucher`) وحدها. */
    public function receipt(Payment $payment): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($payment->isReceived() && $payment->receipt_no !== null, 404);

        return PdfRenderer::render(ReceiptVoucherDocument::html($payment->load('invoice.user', 'actor')), $payment->receipt_no.'.pdf');
    }

    public function proof(Invoice $invoice): StreamedResponse
    {
        abort_if($invoice->proof_path === null, 404, 'لا يوجد إثبات مرفوع لهذه الفاتورة.');
        abort_unless(Storage::exists($invoice->proof_path), 404, 'ملف الإثبات غير موجود على الخادم.');

        return Storage::download(
            $invoice->proof_path,
            'اثبات-'.$invoice->number.'.'.pathinfo($invoice->proof_path, PATHINFO_EXTENSION),
        );
    }

    /**
     * رفض إثبات التحويل — عميلٌ رفع ملفاً خاطئاً كان يفقد زرّ الدفع نهائياً:
     * لا مسار يعيد proof_path إلى الفراغ فتبقى «بانتظار مراجعة الإثبات» للأبد.
     */
    public function rejectProof(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->proof_path === null, 404, 'لا يوجد إثبات مرفوع لهذه الفاتورة.');
        abort_if($invoice->paid, 422, 'الفاتورة محصَّلة — لا معنى لرفض إثباتها.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $reason = trim($data['reason'] ?? '');

        // الملفّ يُحذف بعد نجاح الانتقال لا قبله: رفضٌ تعثّر (تحصيلٌ سبقه) لا يُضيّع إثباتاً قائماً
        $proofPath = $invoice->proof_path;
        Workflow::run(new RejectPaymentProof, $invoice, $request->user(), ['reason' => $reason]);

        if (Storage::exists($proofPath)) {
            Storage::delete($proofPath);
        }
        Notify::send(
            $invoice->user_id,
            'card',
            't-amber',
            "لم يُقبل إثبات التحويل للفاتورة {$invoice->number}".($reason !== '' ? " — السبب: {$reason}" : '').'. يمكنك الدفع عبر ميسّر أو رفع إثبات صحيح.'
        );

        Audit::log(
            action: 'رفض إثبات تحويل',
            description: "رفض {$request->user()->name} إثبات التحويل للفاتورة {$invoice->number}".($reason !== '' ? " — السبب: {$reason}" : '').'.',
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $invoice,
            auditableRef: $invoice->number,
        );

        return back()->with('flash', 'رُفض الإثبات وأُعيدت الفاتورة للاستحقاق وأُشعر العميل.');
    }
}
