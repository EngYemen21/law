<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCaseDocumentJob;
use App\Jobs\GenerateCaseReplyJob;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\LegalAiService;
use App\Services\MoyasarService;
use App\Support\CaseFee;
use App\Support\PaymentReconciler;
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
        $cases = LegalCase::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (LegalCase $c) => $c->toCard());

        return Inertia::render('cases', [
            'cases' => $cases,
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
                'update' => $case->update_text,
                'next' => $case->next_hearing,
                'invoice' => $case->invoice_text,
                'paid' => $case->paid_text,
                'fee' => $case->fee,
                'feeStatus' => $case->fee_status,
                'installmentsPaid' => $case->installments_paid,
                'installmentsTotal' => $case->installments_total,
            ],
            'channel' => 'case.'.$case->id,
            'messages' => $case->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $case->documents->map->toData(),
        ]);
    }

    // إرفاق مستند حقيقي من العميل إلى ملف القضية (رفع ملف + رسالة + بثّ) — يُخزَّن ضمن مستندات القضية.
    public function attach(Request $request, LegalCase $case): HttpResponse
    {
        $this->authorizeCase($request, $case);
        abort_if(in_array($case->status, ['مغلقة', 'مؤرشفة'], true), 422, 'لا يمكن إرفاق مستندات على قضية مغلقة أو مؤرشفة.');

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
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
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
            in_array($case->status, ['مغلقة', 'مؤرشفة'], true),
            422,
            'لا يمكن إرسال رسائل على قضية مغلقة أو مؤرشفة.'
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $case->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => e($data['body']),
            'time_label' => $this->clock(),
        ]);

        $body = $data['body'];
        GenerateCaseReplyJob::dispatch($case, $body);

        return response()->noContent();
    }

    // سداد أتعاب القضية → تفعيلها. السداد الكامل عبر بوّابة ميسّر (503 إن غابت)؛ الأقساط ميزة مستقلّة.
    public function pay(Request $request, LegalCase $case): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'pending_payment', 422);

        $plan = $request->validate(['plan' => ['nullable', 'in:full,install']])['plan'] ?? 'full';

        // الأقساط: ميزة مستقلّة (لا تمرّ ببوّابة الدفع) — الدفعة الأولى تُفعّل القضية
        if ($plan === 'install') {
            $case->update([
                'pay_plan' => 'install',
                'installments_total' => 3,
                'installments_paid' => 1,
                'fee_status' => 'installments',
                'paid_text' => 'دفعة 1 من 3 مدفوعة',
            ]);
            $case->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
                'body' => '<p>تم استلام الدفعة الأولى (1 من 3) من أتعاب القضية، وتفعيلها. تُسدَّد بقية الدفعات لاحقاً.</p>',
                'time_label' => $this->clock(),
            ]);
            CaseFee::activate($case);

            return back();
        }

        // السداد الكامل عبر بوّابة ميسّر → إعادة توجيه لصفحة الدفع (التأكيد عبر webhook/callback)
        abort_unless(app(MoyasarService::class)->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');
        $callback = $request->getSchemeAndHttpHost().route('cases.pay.callback', $case, absolute: false);
        $url = CaseFee::initiatePayment($case, $callback);
        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        return Inertia::location($url);
    }

    // العودة من صفحة ميسّر (سداد الأتعاب) — تحقّق خادميّ صارم ثم تسوية الفاتورة وتفعيل القضية.
    public function payCallback(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        // اربط الدفعة بفاتورة هذه القضية تحديدًا (لا تسوية دفعة تخصّ فاتورة أخرى)
        $ref = Invoice::where('case_id', $case->id)->latest('id')->value('gateway_ref');
        $belongs = $payment !== null && $ref !== null && (string) ($payment['invoice_id'] ?? '') === (string) $ref;

        if ($belongs && PaymentReconciler::settle($payment, 'callback')) {
            return redirect()->route('cases.show', $case)->with('success', 'تم تأكيد سداد الأتعاب وتفعيل القضية.');
        }

        return redirect()->route('cases.show', $case)->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    // سداد دفعة تالية من الأقساط
    public function payInstallment(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'installments', 422);

        $paid = $case->installments_paid + 1;
        $done = $paid >= $case->installments_total;
        $case->update([
            'installments_paid' => $paid,
            'fee_status' => $done ? 'paid' : 'installments',
            'paid_text' => $done ? 'تم سداد كامل الأتعاب' : "دفعة {$paid} من {$case->installments_total} مدفوعة",
        ]);
        if ($done) {
            CaseFee::markInvoicePaid($case);
        }
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => $done
                ? '<p>تم سداد الدفعة الأخيرة واكتمال أتعاب القضية.</p>'
                : "<p>تم استلام الدفعة {$paid} من {$case->installments_total}.</p>",
            'time_label' => $this->clock(),
        ]);

        return back();
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
