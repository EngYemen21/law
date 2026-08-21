<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\MoyasarService;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;

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

        $request->validate(['file' => ['required', 'file', 'max:2048']], [ // حتى 2MB (يطابق upload_max_filesize)
            'file.required' => 'يرجى اختيار ملف.',
            'file.file' => 'الملف غير صالح.',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت).',
        ]);

        $path = $request->file('file')->store("invoice-proofs/{$request->user()->id}");

        $invoice->update([
            'proof_path' => $path,
            'proof_uploaded_at' => now(),
            'status' => 'بانتظار مراجعة الإثبات',
            'tone' => 'b-blue',
        ]);

        return back()->with('success', 'تم استلام إثبات التحويل وسيُراجَع.');
    }

    // دفع فاتورة حقيقي عبر بوّابة ميسّر → يعيد التوجيه لصفحة الدفع المستضافة.
    // التأكيد عبر webhook/callback (مصدر الحقيقة عبر PaymentReconciler) — لا دفع بلا بوّابة مهيّأة.
    public function checkout(Request $request, Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);
        abort_if($invoice->paid, 422, 'الفاتورة مدفوعة بالفعل.');
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

    // فاتورة PDF حقيقية — بنفس تصميم بطاقة .cf المستخدَم لتقرير الاستشارة، مُصيَّرة فعلياً عبر Browsershot.
    public function pdf(Request $request, Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();
        abort_unless(
            $invoice->user_id === $user->id ||
            $user->isAdmin() ||
            $user->isEmployee(),
            403
        );

        $html = ReportPrint::html([
            'title' => 'فاتورة',
            'subtitle' => $invoice->paid ? 'مدفوعة' : 'مستحقة',
            'ref' => $invoice->number,
            'blocks' => [
                [
                    'title' => '١. بيانات الفاتورة',
                    'cellRows' => [[
                        ['رقم الفاتورة', $invoice->number],
                        ['الوصف', $invoice->description],
                        ['تاريخ الاستحقاق', $invoice->due_label ?: '—'],
                        ['حالة السداد', $invoice->paid ? 'مدفوعة' : $invoice->status],
                    ]],
                ],
                ['title' => '٢. المبلغ الإجمالي', 'cellRows' => [[['الإجمالي', number_format($invoice->amount).' ر.س']]]],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — شكراً لتعاملكم معنا',
        ]);

        return PdfRenderer::render($html, $invoice->number.'.pdf');
    }
}
