<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
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
}
