<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
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
}
