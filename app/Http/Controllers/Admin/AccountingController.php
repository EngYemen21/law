<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\Paginate;
use App\Support\PaymentReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    /**
     * تنزيل إثبات التحويل اليدويّ الذي رفعه العميل.
     *
     * كان `proof_path` يُكتب ولا يقرؤه شيء في المشروع كلّه — لا مسار ولا مكوّن — فحالة
     * «بانتظار مراجعة الإثبات» طريق مسدود: المراجع يرى أن إثباتاً رُفع ولا يستطيع فتحه
     * ليقرّر التحصيل. للإدارة وحدها (المجموعة محروسة بـrole:admin).
     */
    public function proof(Invoice $invoice): StreamedResponse
    {
        abort_if($invoice->proof_path === null, 404, 'لا يوجد إثبات مرفوع لهذه الفاتورة.');
        abort_unless(Storage::exists($invoice->proof_path), 404, 'ملف الإثبات غير موجود على الخادم.');

        return Storage::download(
            $invoice->proof_path,
            'اثبات-'.$invoice->number.'.'.pathinfo($invoice->proof_path, PATHINFO_EXTENSION),
        );
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! PaymentReconciler::settleManual($invoice, $request->user()->name)) {
            return back()->with('error', "الفاتورة {$invoice->number} محصّلة مسبقاً.");
        }

        return back()->with('flash', "تم تسجيل تحصيل الفاتورة {$invoice->number}.");
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

        if (Storage::exists($invoice->proof_path)) {
            Storage::delete($invoice->proof_path);
        }
        $invoice->update([
            'proof_path' => null,
            'proof_uploaded_at' => null,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
        ]);

        $reason = trim($data['reason'] ?? '');
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
