<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Jobs\AnalyzeExecutionDocumentJob;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Services\MoyasarService;
use App\Support\ExecService;
use App\Support\Mask;
use App\Support\Notify;
use App\Support\PaymentReconciler;
use App\Support\ReportPrint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تدفّق طلب التنفيذ التجاريّ (10 مراحل) — يخدم لوحات الأدوار الأربع (عميل/محامي/إدارة/موظف).
 * العرض حسب الدور، والتقديم للعميل، وموزّع إجراءات واحد يحرس الدور/الملكيّة لكلّ انتقال.
 */
class ExecFlowController extends Controller
{
    // ── العرض حسب اللوحة ──

    public function client(Request $request): Response
    {
        // التبويب الموحّد: كل تنفيذات العميل — التدفّق (stage≠null) والقديمة (stage=null، تُعرَض بمرحلة مشتقّة)
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences'])
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false));

        return Inertia::render('execflow', ['role' => 'client', 'execs' => $execs]);
    }

    public function lawyer(Request $request): Response
    {
        // التبويب الموحّد: المسند إليه (تدفّق + قديم) أو غير المسند القابل للالتقاط (تدفّق stage≥2) — عزل المحامي محفوظ
        $uid = $request->user()->id;
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences'])
            ->where(fn ($q) => $q->where('assigned_lawyer_id', $uid)
                ->orWhere(fn ($p) => $p->whereNull('assigned_lawyer_id')->whereNotNull('stage')->where('stage', '>=', 2)))
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(true));

        return Inertia::render('execflow', ['role' => 'lawyer', 'execs' => $execs]);
    }

    public function admin(): Response
    {
        // التبويب الموحّد: كل التنفيذات (تدفّق + قديمة تُعرَض بمرحلة مشتقّة) — الإدارة ترى الكلّ
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences'])
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(true));

        return Inertia::render('execflow', ['role' => 'admin', 'execs' => $execs]);
    }

    public function employee(Request $request): Response
    {
        // التبويب الموحّد لموظف الاستقبال: كل تنفيذات فرعه (تدفّق + قديمة) — بوّابة الاستقبال والإحالة
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences'])
            ->where('branch', $request->user()->branch)
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(true));

        return Inertia::render('execflow', ['role' => 'employee', 'execs' => $execs]);
    }

    // ── تقديم طلب جديد (العميل) ──

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Client, 403);

        $data = $request->validate([
            'sanad' => ['required', 'string', 'max:60'],
            'subject' => ['required', 'string', 'max:160'],
            'defendant' => ['nullable', 'string', 'max:160'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ]);

        $files = $request->file('files', []);
        ExecService::submit($request->user(), $data, is_array($files) ? $files : [$files]);

        return back();
    }

    // ── موزّع الإجراءات (يحرس الدور/الملكيّة لكلّ انتقال) ──

    public function act(Request $request, Execution $execution): RedirectResponse
    {
        $user = $request->user();
        $role = $user->role;
        $action = (string) $request->input('action');

        // خرائط الصلاحية لكلّ إجراء
        $clientActions = ['acceptOffer', 'inquire', 'rejectOffer'];
        $adminOnly = ['approveFee', 'setFee'];               // قرار ماليّ — الإدارة وحدها
        $intakeActions = ['refer', 'requestDocs'];            // الاستقبال — الموظف (بفرعه) أو المكتب
        $lawyerPickup = ['accept', 'reject', 'saveFee'];     // المحامي (التقاط/عزل)
        $staffProcActions = ['addProcedure', 'requestCorr', 'close']; // محامي أو إدارة

        if (in_array($action, $clientActions, true)) {
            abort_unless($role === Role::Client && $execution->user_id === $user->id, 403);
        } elseif (in_array($action, $adminOnly, true)) {
            abort_unless($role === Role::Admin, 403);
        } elseif (in_array($action, $intakeActions, true)) {
            // الموظف (بوّابة الاستقبال، محصور بفرعه) أو المكتب (محامٍ/إدارة). refer للموظف/الإدارة فقط
            $allowed = $action === 'refer' ? [Role::Employee, Role::Admin] : [Role::Employee, Role::Lawyer, Role::Admin];
            abort_unless(in_array($role, $allowed, true), 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            abort_if($role === Role::Employee && $execution->branch !== $user->branch, 403);
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } elseif (in_array($action, $lawyerPickup, true)) {
            abort_unless($role === Role::Lawyer, 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            // عزل: لا يتصرّف محامٍ على ملفّ مسند لزميل آخر (يلتقط غير المسند فيُختَم باسمه)
            abort_if($execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
            $this->assignLawyerIfNeeded($execution, $user->name, $user->id);
        } elseif (in_array($action, $staffProcActions, true)) {
            abort_unless($role === Role::Lawyer || $role === Role::Admin, 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } else {
            abort(422, 'إجراء غير معروف.');
        }

        match ($action) {
            'refer' => ExecService::refer($execution),
            'accept' => ExecService::accept($execution),
            'requestDocs' => ExecService::requestDocs($execution),
            'reject' => ExecService::reject($execution),
            'saveFee' => ExecService::saveFee(
                $execution,
                (int) $request->validate(['fee' => ['required', 'integer', 'min:1']])['fee'],
                (string) $request->input('duration', ''),
                (string) $request->input('payMethod', ''),
            ),
            'approveFee' => ExecService::approveFee($execution, (int) $request->input('fee', 0) ?: null),
            'setFee' => ExecService::setFee(
                $execution,
                (int) $request->validate(['fee' => ['required', 'integer', 'min:1']])['fee'],
                (string) $request->input('duration', ''),
                (string) $request->input('payMethod', ''),
            ),
            'acceptOffer' => ExecService::acceptOffer($execution),
            'inquire' => ExecService::inquire($execution),
            'rejectOffer' => ExecService::rejectOffer($execution),
            'addProcedure' => ExecService::addProcedure(
                $execution,
                (string) $request->validate(['title' => ['required', 'string', 'max:200']])['title'],
            ),
            'requestCorr' => ExecService::requestCorr($execution),
            'close' => ExecService::close($execution),
        };

        return back();
    }

    // ── سداد أتعاب التنفيذ عبر بوّابة ميسّر (المرحلة 6) ──

    /** يبدأ الدفع عبر ميسّر ويعيد التوجيه لصفحة الدفع المستضافة. التأكيد عبر webhook/callback. */
    public function pay(Request $request, Execution $execution): HttpFoundationResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Client && $execution->user_id === $user->id, 403);

        // رابط العودة من أصل الطلب نفسه (لا APP_URL) — فتبقى الجلسة صالحة
        $callback = $request->getSchemeAndHttpHost().route('exec-flow.pay.callback', $execution, absolute: false);
        $url = ExecService::initiatePayment($execution, $callback); // يحرس المرحلة [6]، وnull إن تعذّر

        if ($url !== null) {
            return Inertia::location($url); // Inertia يوجّه المتصفّح لصفحة ميسّر
        }

        abort_unless(app(MoyasarService::class)->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
    }

    /** العودة من صفحة ميسّر — تحقّق خادميّ صارم (يُعاد جلب الدفعة والتحقّق من انتمائها لفاتورة هذا الطلب). */
    public function payCallback(Request $request, Execution $execution): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Client && $execution->user_id === $request->user()->id, 403);

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        $ref = $execution->invoices()->latest('id')->value('gateway_ref');
        $belongs = $payment !== null && $ref !== null && (string) ($payment['invoice_id'] ?? '') === (string) $ref;

        if ($belongs && PaymentReconciler::settle($payment, 'callback')) {
            return redirect()->route('execs')->with('success', 'تم تأكيد سداد أتعاب التنفيذ وفتح الملف.');
        }

        return redirect()->route('execs')->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    // ── محادثة ملف التنفيذ (العميل ↔ المكتب) — بلا ردّ AI، إشعار للمكتب + بثّ لحظيّ ──

    public function message(Request $request, Execution $execution): HttpResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true);
        abort_unless($isClient || $isStaff, 403);
        if ($isStaff) {
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
        }
        abort_if($user->role === Role::Employee && $execution->branch !== $user->branch, 403); // عزل الموظف بفرعه
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403); // عزل المحامي بالإسناد
        abort_if(in_array($execution->status, ['مكتمل', 'مغلق'], true) || (int) $execution->stage === 9, 422, 'لا يمكن إرسال رسائل على ملفّ تنفيذ مغلق.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        // هوية المرسِل حسب دوره (العميل ↔ المكتب) — البثّ اللحظيّ تلقائيّ في ExecutionMessage::booted
        [$who, $name, $senderRole] = match (true) {
            $isClient => ['client', 'أنت', 'العميل'],
            $user->role === Role::Admin => ['admin', 'الإدارة العليا', 'المكتب'],
            $user->role === Role::Lawyer => ['lawyer', $user->name, 'قسم التنفيذ'],
            default => ['staff', $user->name, 'خدمة العملاء'], // الموظف
        };

        $execution->messages()->create([
            'who' => $who, 'name' => $name, 'role' => $senderRole,
            'body' => e($data['body']), 'time_label' => $this->clock(),
        ]);

        // إشعار الطرف الآخر: العميل يُشعِر المكتب، والمكتب يُشعِر العميل
        if ($isClient) {
            if ($execution->assigned_lawyer_id !== null) {
                Notify::send($execution->assigned_lawyer_id, 'exec', 't-blue', "رسالة جديدة من العميل على ملفّ التنفيذ {$execution->number}.");
            }
        } else {
            Notify::send($execution->user_id, 'exec', 't-blue', "رسالة جديدة من المكتب على ملفّ تنفيذك {$execution->number}.");
        }

        return response()->noContent();
    }

    // ── إرفاق مستند حرّ من العميل داخل محادثة التنفيذ (رفع ملف + رسالة + بثّ) ──

    public function attach(Request $request, Execution $execution): HttpResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Client && $execution->user_id === $user->id, 403);
        abort_if(in_array($execution->status, ['مكتمل', 'مغلق'], true) || (int) $execution->stage === 9, 422, 'لا يمكن إرفاق مستندات على ملفّ تنفيذ مغلق.');

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xlsx']]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $doc = $execution->documents()->create([
            'label' => $name,
            'status' => 'مرفوع',
            'path' => $file->store("exec-docs/{$execution->id}"),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_at' => now(),
        ]);

        // تحليل ذكي للمستند بالخلفية (تصنيف + تلخيص بسياق طلب التنفيذ) ثم ملخّص في المحادثة
        AnalyzeExecutionDocumentJob::dispatch($execution, $doc);

        // رسالة في محادثة التنفيذ (تُبثّ لحظياً تلقائياً عبر ExecutionMessage::booted)
        $execution->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);

        if ($execution->assigned_lawyer_id !== null) {
            Notify::send($execution->assigned_lawyer_id, 'upload', 't-blue', "أرفق العميل مستنداً «{$name}» على ملفّ التنفيذ {$execution->number}.");
        }

        return response()->noContent();
    }

    // ── اعتماد/إعادة مستند رفعه العميل (المكتب: محامٍ أو إدارة) ──

    public function reviewDocument(Request $request, Execution $execution, ExecutionDocument $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true), 403);
        abort_if($user->role === Role::Employee && $execution->branch !== $user->branch, 403); // عزل الموظف بفرعه
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403); // عزل المحامي بالإسناد
        abort_unless($document->execution_id === $execution->id, 404);
        abort_unless($document->status === 'مرفوع', 422, 'لا يمكن مراجعة مستند لم يُرفَع بعد.');

        $decision = (string) $request->validate(['decision' => ['required', 'in:accept,reject']])['decision'];
        $document->update(['status' => $decision === 'accept' ? 'مقبول' : 'مرفوض']);

        $msg = $decision === 'accept' ? "اعتُمد مستند «{$document->label}»." : "أُعيد مستند «{$document->label}» لإعادة الرفع.";
        Notify::send($execution->user_id, 'file', $decision === 'accept' ? 't-green' : 't-amber', "$msg (ملفّ التنفيذ {$execution->number})");

        return back()->with('success', $msg);
    }

    // ── تنزيل مستند التنفيذ المرفوع (صاحب الملف أو المكتب المصرَّح له) ──

    public function downloadDocument(Request $request, Execution $execution, ExecutionDocument $document): StreamedResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true);
        abort_unless($isClient || $isStaff, 403);
        abort_if($user->role === Role::Employee && $execution->branch !== $user->branch, 403);
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        abort_unless($document->execution_id === $execution->id, 404);
        abort_if($document->path === null, 404, 'الملف غير موجود على الخادم.');

        return Storage::download($document->path, $document->label);
    }

    // ── طباعة عرض/فاتورة خدمة التنفيذ (PDF حقيقي عبر Browsershot — نظير InvoiceController::pdf) ──

    public function offerPdf(Request $request, Execution $execution): HttpFoundationResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true);
        abort_unless($isClient || $isStaff, 403);
        abort_if($user->role === Role::Employee && $execution->branch !== $user->branch, 403);
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        abort_unless((int) $execution->fee > 0, 422, 'لا يوجد عرض/فاتورة على هذا الطلب بعد.');

        $total = (int) $execution->fee + (int) $execution->vat;
        $lawyer = $isClient ? Mask::lawyer($execution->assigned_lawyer) : ($execution->assigned_lawyer ?: '—');

        $html = ReportPrint::html([
            'title' => $execution->paid ? 'فاتورة خدمة التنفيذ' : 'عرض خدمة التنفيذ',
            'subtitle' => $execution->paid ? 'مدفوعة' : 'بانتظار السداد',
            'ref' => $execution->number,
            'blocks' => [
                [
                    'title' => '١. بيانات طلب التنفيذ',
                    'cellRows' => [
                        [['رقم الطلب', $execution->number], ['نوع السند', $execution->sanad ?: '—'], ['الموضوع', $execution->subject], ['المنفَّذ ضده', $execution->defendant ?: '—']],
                        [['قيمة المطالبة', number_format((int) $execution->amount).' ر.س'], ['رقم ملف التنفيذ', $execution->exec_no ?: '—'], ['رقم الفاتورة', $execution->invoice_no ?: '—']],
                    ],
                ],
                [
                    ['title' => '٢. بيانات العميل', 'cellRows' => [[['اسم العميل', $execution->user?->name ?: '—']]]],
                    ['title' => '٣. مقدّم الخدمة', 'cellRows' => [[['الجهة', 'المكتب القانوني'], ['المحامي المسؤول', $lawyer]]]],
                ],
                [
                    'title' => '٤. التفاصيل المالية',
                    'cellRows' => [[
                        ['أتعاب التنفيذ', number_format((int) $execution->fee).' ر.س'],
                        ['ضريبة القيمة المضافة (١٥٪)', number_format((int) $execution->vat).' ر.س'],
                        ['الإجمالي المستحق', number_format($total).' ر.س'],
                        ['طريقة السداد', $execution->pay_method ?: '—'],
                    ]],
                ],
            ],
            'approval' => [
                'qrSeed' => $execution->number,
                'rows' => [
                    ['الجهة', 'سلاسل بابل لتقنية المعلومات'],
                    ['حالة الطلب', $execution->status],
                    ['تاريخ الطباعة', now()->format('Y-m-d')],
                ],
            ],
            'note' => 'هذا المستند يمثّل عرض/فاتورة خدمة التنفيذ الصادرة عن المكتب، ولا يُعدّ بذاته سنداً تنفيذياً أو حكماً قضائياً.',
            'footer' => 'سلاسل بابل لتقنية المعلومات — صادر إلكترونياً',
        ]);

        $pdf = Browsershot::html($html)
            ->setCustomTempPath(storage_path('app/browsershot-tmp'))
            ->setNodeModulePath(base_path('node_modules'))
            ->noSandbox()
            ->format('A4')
            ->showBackground()
            ->margins(12, 12, 12, 12)
            ->pdf();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$execution->number.'.pdf"',
        ]);
    }

    // ── رفع مستند مطلوب من العميل ──

    public function uploadDocument(Request $request, Execution $execution, ExecutionDocument $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Client && $execution->user_id === $user->id, 403);
        abort_unless($document->execution_id === $execution->id, 404);

        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,docx']], [
            'file.required' => 'يرجى اختيار ملف.',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت).',
            'file.mimes' => 'الصيغة غير مدعومة (المسموح: PDF, JPG, PNG, DOCX).',
        ]);

        $file = $request->file('file');
        $path = $file->store("exec-docs/{$execution->id}");

        $document->update([
            'status' => 'مرفوع',
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_at' => now(),
        ]);

        if ($execution->assigned_lawyer_id !== null) {
            Notify::send($execution->assigned_lawyer_id, 'upload', 't-blue', "رفع العميل مستند «{$document->label}» على ملفّ التنفيذ {$execution->number}.");
        }

        return back()->with('success', 'تم رفع المستند.');
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }

    private function assignLawyerIfNeeded(Execution $execution, string $name, int $id): void
    {
        if ($execution->assigned_lawyer_id === null) {
            $execution->update(['assigned_lawyer' => $name, 'assigned_lawyer_id' => $id]);
        }
    }
}
