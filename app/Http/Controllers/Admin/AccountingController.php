<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Support\Paginate;
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
    public function index(Request $request): Response
    {
        // التبويب صار خادميّاً مع الترقيم: تصفية الصفحة الحالية محلياً كانت ستُظهر
        // «المدفوعة» من هذه الصفحة فقط بدل كل المدفوعات.
        $filter = (string) $request->query('filter', 'all');

        $invoices = Invoice::with('user')
            ->when($filter === 'مدفوعة', fn ($q) => $q->where('paid', true))
            ->when($filter === 'غير مدفوعة', fn ($q) => $q->where('paid', false))
            ->latest('id')->paginate(50)->withQueryString();

        // الإجماليات باستعلامات تجميعية على كل الفواتير (لا على الصفحة) — وكانت تُحسب
        // بجلب الجدول كاملاً إلى الذاكرة.
        $issued = (int) Invoice::sum('amount');
        $collected = (int) Invoice::where('paid', true)->sum('amount');

        return Inertia::render('admin/accounting', [
            'invoices' => Paginate::shape($invoices, fn (Invoice $v) => [
                'no' => $v->number,
                'client' => Ticket::maskClient($v->user?->name ?? ''),
            ] + $v->toCard()),
            'filter' => $filter,
            'totals' => [
                'issued' => $issued,
                'collected' => $collected,
                'due' => $issued - $collected,
                // كانت «متأخرة» تعدّ كل غير المدفوع (فاتورة صدرت قبل ساعة تُحسب متأخرة) — الفيصل تجاوز الاستحقاق
                'overdue' => Invoice::where('paid', false)->whereNotNull('due_at')
                    ->whereDate('due_at', '<', now()->toDateString())->count(),
                // غير المدفوعة (شاملة ما لم يحن استحقاقه) — كانت البطاقة تعرض «المتأخرة» بهذه التسمية
                'unpaid' => Invoice::where('paid', false)->count(),
            ],
        ]);
    }

    /** تحصيل يدويّ (نقد/تحويل خارج البوّابة) — يقيّد الدفتر ويمنع التحصيل المكرّر. */
    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! PaymentReconciler::settleManual($invoice, $request->user()->name)) {
            return back()->with('error', "الفاتورة {$invoice->number} محصّلة مسبقاً.");
        }

        return back()->with('flash', "تم تسجيل تحصيل الفاتورة {$invoice->number}.");
    }
}
