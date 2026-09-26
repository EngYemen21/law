<?php

namespace App\Http\Controllers;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transitions\Invoice\SubmitPaymentProof;
use App\Domain\Journey\Workflow;
use App\Models\Invoice;
use App\Services\MoyasarService;
use App\Support\Finance\TaxInvoiceDocument;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    // قائمة فواتير العميل الحالي (تحسب الواجهة الإحصائيات والتقسيم)
    public function index(Request $request): Response
    {
        $invoices = Invoice::where('user_id', $request->user()->id)
            ->latest('id')->get()
            ->map(fn (Invoice $v) => $v->toCard());

        return Inertia::render('invoices', [
            'invoices' => $invoices,
        ]);
    }

    // رفع إثبات تحويل يدويّ لفاتورة مستحقّة (يخزّن الملف ويحوّل الحالة للمراجعة)
    public function uploadProof(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);
        // قبل تخزين الملفّ لا بعده: الرفض بعد التخزين يترك على القرص إثباتاً بلا فاتورة
        abort_if($invoice->paid, 422, SubmitPaymentProof::PAID);
        abort_if($invoice->isCancelled(), 422, SubmitPaymentProof::CANCELLED);

        // قائمة السماح نفسها المعتمدة في بقيّة الرفوعات — كان يقبل أي امتداد
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,doc,docx']], [ // حتى 2MB (يطابق upload_max_filesize)
            'file.required' => 'يرجى اختيار ملف.',
            'file.file' => 'الملف غير صالح.',
            'file.mimes' => 'صيغة الملف غير مسموحة (المسموح: PDF أو صورة أو مستند Word).',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت).',
        ]);

        $path = $request->file('file')->store("invoice-proofs/{$request->user()->id}");

        Workflow::run(new SubmitPaymentProof, $invoice, $request->user(), ['proof_path' => $path]);

        return back()->with('success', 'تم استلام إثبات التحويل وسيُراجَع.');
    }

    // دفع فاتورة حقيقي عبر بوّابة ميسّر → يعيد التوجيه لصفحة الدفع المستضافة.
    // التأكيد عبر webhook/callback (مصدر الحقيقة عبر PaymentReconciler) — لا دفع بلا بوّابة مهيّأة.
    public function checkout(Request $request, Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);
        abort_if($invoice->paid, 422, 'الفاتورة مدفوعة بالفعل.');
        // الملغاة لا تُسدَّد — كان سدادُها يُحيي الاستشارة التي أُلغيت معها (ع١).
        // **والكتالوج مصدرُ الحكم لا نصٌّ منقوش**: `isPayable` يردّ المسوّدةَ (لم تُرسَل بعد)
        // والمعدومةَ (أُسقطت مطالبتُها) كما يردّ الملغاة — والمقارنة النصّيّة كانت تمرّرهما.
        $status = InvoiceStatus::tryFrom((string) $invoice->status);
        abort_if($status === null || ! $status->isPayable(), 422, $invoice->isCancelled()
            ? 'أُلغيت هذه الفاتورة ولا تُسدَّد.'
            : 'هذه الفاتورة لا تقبل السداد في حالتها الحاليّة.');
        abort_unless(app(MoyasarService::class)->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        $callback = $request->getSchemeAndHttpHost().route('invoices.checkout.callback', $invoice, absolute: false);
        $url = app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callback);
        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        return Inertia::location($url);
    }

    // العودة من صفحة ميسّر — تحقّق خادميّ صارم (لا يُوثَق بمعطيات الـURL): يُعاد جلب الدفعة والتحقّق منها.
    public function checkoutCallback(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);

        // إن نشأت الفاتورة من استشارة مرتبطة بتذكرة، يعود العميل لدردشة التذكرة (لاختيار الموعد)؛ وإلا لصفحة الفواتير.
        $ticket = $invoice->consult?->ticket;
        $back = fn (): RedirectResponse => $ticket
            ? redirect()->route('tickets.show', $ticket)
            : redirect()->route('invoices');

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        $belongs = $payment !== null && (
            ($invoice->gateway_ref !== null && (string) ($payment['invoice_id'] ?? '') === (string) $invoice->gateway_ref) ||
            ($invoice->gateway_ref === null && (string) ($payment['metadata']['invoice_number'] ?? '') === (string) $invoice->number)
        );

        if ($belongs && PaymentReconciler::settle($payment, 'callback')) {
            return $back()->with('success', 'تم تأكيد الدفع.');
        }

        if ($invoice->fresh()->paid) {
            return $back()->with('success', 'تم تأكيد الدفع.');
        }

        return $back()->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    /**
     * **الفاتورة الضريبيّة** — مستندٌ مقروء، لا سطرَ «إجمالي» واحداً.
     *
     * كان المطبوع سطراً واحداً بالإجماليّ الشامل: لا أساسَ قبل الضريبة، ولا ضريبةً بنسبتها،
     * ولا رقماً ضريبيّاً للمكتب — فما يصدره النظام لم يكن فاتورةً ضريبيّة (ب٢).
     *
     * ورمز الهيئة (TLV) يبنيه المستند نفسه (`Finance\ZatcaQr`) متى ضُبط الرقم الضريبيّ — لا هنا.
     */
    public function pdf(Request $request, Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();
        abort_unless(
            $invoice->user_id === $user->id ||
            $user->isAdmin() ||
            $user->isEmployee(),
            403
        );

        return PdfRenderer::render(TaxInvoiceDocument::html($invoice), $invoice->number.'.pdf');
    }
}
