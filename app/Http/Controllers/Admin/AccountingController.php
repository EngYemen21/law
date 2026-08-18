<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Support\PaymentReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * محاسبة الإدارة — فواتير حقيقية من موديل Invoice (بدل مصفوفة INVOICES الوهمية).
 */
class AccountingController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::with('user')->latest('id')->get();

        $issued = (int) $invoices->sum('amount');
        $collected = (int) $invoices->where('paid', true)->sum('amount');

        return Inertia::render('admin/accounting', [
            'invoices' => $invoices->map(fn (Invoice $v) => [
                'no' => $v->number,
                'client' => Ticket::maskClient($v->user?->name ?? ''),
            ] + $v->toCard()),
            'totals' => [
                'issued' => $issued,
                'collected' => $collected,
                'due' => $issued - $collected,
                // كانت «متأخرة» تعدّ كل غير المدفوع (فاتورة صدرت قبل ساعة تُحسب متأخرة) — الفيصل تجاوز الاستحقاق
                'overdue' => $invoices->filter(fn (Invoice $v) => $v->isOverdue())->count(),
            ],
        ]);
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        PaymentReconciler::settleDomain($invoice, $request->user()->name);

        return back()->with('flash', "تم تسجيل تحصيل الفاتورة {$invoice->number}.");
    }
}
