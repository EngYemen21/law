<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Jobs\AnalyzeExecutionDocumentJob;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\Setting;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\MoyasarService;
use App\Support\Audit;
use App\Support\ConversationFiles;
use App\Support\ExecFee;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\LawyerName;
use App\Support\Notify;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
use App\Support\SettingsRegistry;
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
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences', 'invoices'])
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false));

        return Inertia::render('execflow', ['role' => 'client', 'execs' => $execs]);
    }

    public function lawyer(Request $request): Response
    {
        // التبويب الموحّد: المسند إليه (تدفّق + قديم) أو غير المسند القابل للالتقاط (تدفّق stage≥2) — عزل المحامي محفوظ
        $uid = $request->user()->id;
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences', 'invoices'])
            ->where(fn ($q) => $q->where('assigned_lawyer_id', $uid)
                ->orWhere(fn ($p) => $p->whereNull('assigned_lawyer_id')->whereNotNull('stage')->where('stage', '>=', 2)))
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false, true));

        return Inertia::render('execflow', ['role' => 'lawyer', 'execs' => $execs]);
    }

    public function admin(): Response
    {
        // التبويب الموحّد: كل التنفيذات (تدفّق + قديمة تُعرَض بمرحلة مشتقّة) — الإدارة ترى الكلّ
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences', 'invoices'])
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false, true));

        return Inertia::render('execflow', ['role' => 'admin', 'execs' => $execs, 'lawyers' => self::assignableLawyers()]);
    }

    public function employee(Request $request): Response
    {
        // التبويب الموحّد لموظف الاستقبال: كل ملفّات التنفيذ (تدفّق + قديمة) — بوّابة الاستقبال والإحالة
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'correspondences', 'invoices'])
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false, true));

        // قائمة الإسناد **لمن يُسند وحده**: موظّفٌ بلا «إجراءات المحكمة والجلسات» لا يفتح
        // مودال الإسناد أصلاً (`canAssign=false` على كلّ بطاقة)، فقائمةٌ في حمولته زينةٌ لا تُستعمل.
        $canAssign = (bool) $request->user()?->can('إجراءات المحكمة والجلسات');

        return Inertia::render('execflow', [
            'role' => 'employee',
            'execs' => $execs,
            'lawyers' => $canAssign ? self::assignableLawyers() : [],
        ]);
    }

    /**
     * المحامون الذين يقبلهم الإسناد — النشطون وحدهم، وهي **قاعدة `ActiveLawyer` نفسها**
     * التي يقيس بها `act` المُدخل. قائمةٌ أوسع من الحارس تعرض خياراً يردّه الخادم.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private static function assignableLawyers(): array
    {
        return User::where('role', Role::Lawyer)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->isActive())
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()->all();
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
        $execution = ExecService::submit($request->user(), $data, is_array($files) ? $files : [$files]);

        Audit::log(
            action: 'فتح طلب تنفيذ',
            description: "فتح العميل {$request->user()->name} طلب التنفيذ {$execution->number} — {$data['subject']}.",
            category: 'قضايا وتنفيذ',
            auditable: $execution,
            auditableRef: $execution->number,
        );

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
        $intakeActions = ['refer', 'requestDocs'];            // الاستقبال — الموظف أو المكتب
        $lawyerPickup = ['accept', 'reject', 'saveFee'];     // المحامي (التقاط/عزل)
        // محامي أو إدارة وحدهما — إغلاق الملفّ وتوثيق الإجراء وطلب المخاطبة تبقى لهما
        $staffProcActions = ['addProcedure', 'requestCorr', 'close'];
        // خطوات ناجز (الرفع/القيد/الإبلاغ/الإجراءات/التحصيل) — يسجّلها الموظّف أيضاً بصلاحيّتها
        $najizActions = ['fileNajiz', 'registerNajiz', 'notifyDebtor', 'applyMeasures', 'addCollection'];
        // الإسناد — الإدارة وحدها تُعيد، والموظّف المخوَّل يُسند غير المسنَد (تفصيله في حارسه أدناه)
        $assignActions = ['assignLawyer'];
        $pickup = false; // التقاطُ محامٍ لملفٍّ غير مسنَد — يُختم بعد نجاح الإجراء

        if (in_array($action, $clientActions, true)) {
            abort_unless($role === Role::Client && $execution->user_id === $user->id, 403);
        } elseif (in_array($action, $adminOnly, true)) {
            abort_unless($role === Role::Admin, 403);
        } elseif (in_array($action, $intakeActions, true)) {
            // الموظف (بوّابة الاستقبال) أو المكتب (محامٍ/إدارة). refer للموظف/الإدارة فقط
            $allowed = $action === 'refer' ? [Role::Employee, Role::Admin] : [Role::Employee, Role::Lawyer, Role::Admin];
            abort_unless(in_array($role, $allowed, true), 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } elseif (in_array($action, $lawyerPickup, true)) {
            abort_unless($role === Role::Lawyer, 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            // عزل: لا يتصرّف محامٍ على ملفّ مسند لزميل آخر (يلتقط غير المسند فيُختَم باسمه)
            abort_if($execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
            // الإسناد **بعد** نجاح الإجراء لا قبله: كان يُختم الملفّ باسم المحامي ثم يرفض حارسُ
            // المرحلة الإجراءَ نفسه، فيبقى ملفٌّ مسنَداً لمن لم يفعل شيئاً (ويُحجب عن زملائه).
            $pickup = true;
        } elseif (in_array($action, $staffProcActions, true)) {
            abort_unless($role === Role::Lawyer || $role === Role::Admin, 403);
            abort_unless($user->can('إدارة القضايا والأتعاب'), 403);
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } elseif (in_array($action, $najizActions, true)) {
            /*
             * **خطوات المحكمة يسجّلها الموظّف كما في القضايا** (قرار المالك 2026-09-12).
             *
             * كان حارس الدور يسبق حارس الصلاحيّة (`role===Lawyer||Admin` ثمّ `can`)، فمنحُ
             * الموظّف أيّ صلاحيّةٍ لا يفتح له شيئاً — صلاحيّةٌ تُعرض في شاشة الصلاحيّات ولا
             * تحرس باباً. والتنفيذ يطابق القضايا الآن: «إجراءات المحكمة والجلسات» للموظّف
             * (نظير مجموعة `employee.cases.najiz.*`)، و«إدارة القضايا والأتعاب» للمكتب.
             *
             * والفحص بالصلاحيّة لا بالدور وحده، والرفض 403 صريحٌ لأن المسار بلا وسيط
             * `permission:` — الحارس هنا (نظير `EnsurePermission`).
             */
            $allowed = match ($role) {
                Role::Lawyer, Role::Admin => $user->can('إدارة القضايا والأتعاب'),
                Role::Employee => $user->can('إجراءات المحكمة والجلسات'),
                default => false,
            };
            abort_unless($allowed, 403, 'لا تملك صلاحية تسجيل إجراءات ناجز على ملفّ التنفيذ.');
            // عزل المحامي بالإسناد كما هو — الموظّف بوّابةُ المكتب فلا يُعزل بإسناد زميل
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } elseif (in_array($action, $assignActions, true)) {
            /*
             * **الإسناد قرارُ توجيهٍ لا خطوةُ ملفّ** (قرار المالك):
             *
             * - الإدارة تُسند وتُعيد الإسناد بلا قيد — التوزيع عملها.
             * - الموظّف يُسند بصلاحيّة «إجراءات المحكمة والجلسات» (الصلاحيّة نفسها التي
             *   مُنحت لخطوات ناجز)، لكن **غير المسنَد وحده**: إعادةُ الإسناد تنزع ملفّاً من
             *   محامٍ يعمل عليه وتكسر عزله عنه (`assigned_lawyer_id` هو ما يحجب الملفّ عن
             *   زملائه)، وذاك قرارُ إدارةٍ لا استقبال.
             * - **ولا محامٍ يُسند** — ولا لنفسه: الالتقاط بابُه «قبول» وحده (يُختم بعد نجاح
             *   الإجراء)، وفتحُ الإسناد للمحامي يعني أن يأخذ ملفّ زميله أو يحجز ما لم يعمل عليه.
             */
            $allowed = match ($role) {
                Role::Admin => true,
                Role::Employee => $execution->assigned_lawyer_id === null && $user->can('إجراءات المحكمة والجلسات'),
                default => false,
            };
            abort_unless($allowed, 403, 'لا تملك صلاحية إسناد محامٍ لملفّ التنفيذ.');
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
                ...self::feeInput($request),
            ),
            // 0 أو الفراغ = «اعتمد الأتعاب كما هي» (الحقل اختياري في الواجهة) ⇒ null.
            // والتحقق يمنع السالب والنصّ اللذين كانا يمرّان عبر (int) على مُدخل حرّ.
            'approveFee' => ExecService::approveFee(
                $execution,
                ((int) ($request->validate(['fee' => ['nullable', 'integer', 'min:0']])['fee'] ?? 0)) ?: null,
            ),
            'setFee' => ExecService::setFee(
                $execution,
                ...self::feeInput($request),
            ),
            'acceptOffer' => ExecService::acceptOffer($execution),
            'inquire' => ExecService::inquire($execution),
            'rejectOffer' => ExecService::rejectOffer($execution),
            'addProcedure' => ExecService::addProcedure(
                $execution,
                (string) $request->validate(['title' => ['required', 'string', 'max:200']])['title'],
            ),
            'requestCorr' => ExecService::requestCorr($execution),
            // المحامي يُقاس بقاعدة `ActiveLawyer` نفسها المستعملة في التحويل والاستشارات —
            // فلا يمرّ عميلٌ ولا موظّفٌ ولا محامٍ موقوف كـ`lawyer_id`
            'assignLawyer' => ExecService::assignLawyer(
                $execution,
                User::findOrFail((int) $request->validate(['lawyer_id' => ['required', 'integer', new ActiveLawyer]])['lawyer_id']),
                $user,
            ),
            // خطوات ناجز — التحقّق هنا، والحرّاس والرسائل والإشعارات في ExecService كبقيّة الإجراءات
            'fileNajiz' => ExecService::fileNajiz(
                $execution,
                trim((string) $request->validate(['request_no' => ['required', 'string', 'max:60']])['request_no']),
                (string) $request->validate(['filed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['filed_at'],
            ),
            'registerNajiz' => ExecService::registerNajiz(
                $execution,
                trim((string) $request->validate(['court' => ['required', 'string', 'max:160']])['court']),
                trim((string) $request->validate(['circuit' => ['required', 'string', 'max:160']])['circuit']),
                (string) $request->validate(['registered_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['registered_at'],
            ),
            'notifyDebtor' => ExecService::notifyDebtor(
                $execution,
                (string) $request->validate(['notified_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['notified_at'],
            ),
            'applyMeasures' => ExecService::applyMeasures(
                $execution,
                (array) ($request->validate(['measures' => ['array'], 'measures.*' => ['string', 'in:'.implode(',', ExecFlow::MEASURES)]])['measures'] ?? []),
            ),
            'addCollection' => ExecService::addCollection(
                $execution,
                (int) $request->validate(['amount' => ['required', 'integer', 'min:1']])['amount'],
                trim((string) $request->input('note', '')),
            ),
            // سبب الإنهاء يُسجَّل مع الإغلاق (ExecFlow::CLOSE_REASONS) — «أخرى» إن لم يُحدَّد.
            // والفاعل يُمرَّر صراحةً: إنهاء **الملفّ المرفوض** (2‑3) للإدارة وحدها، وحارسُه في
            // `ExecService::close` لأنّه يقرأ حالة الصفّ مع الدور معاً لا الدور وحده.
            'close' => ExecService::close(
                $execution,
                (string) ($request->validate(['reason' => ['nullable', 'string', 'in:'.implode(',', ExecFlow::CLOSE_REASONS)]])['reason'] ?? 'أخرى'),
                $user,
            ),
        };

        // نجح الإجراء ⇒ يُختم الملفّ باسم ملتقِطه (والحرّاس أعلاه ترمي قبل بلوغ هذا السطر)
        if ($pickup) {
            $this->assignLawyerIfNeeded($execution, $user->name, $user->id);
        }

        // موزّع مركزي: قيد واحد يلتقط كل انتقالات دورة التنفيذ (إحالة/قبول/أتعاب/عرض/إجراء/إغلاق…)
        $fresh = $execution->fresh();
        Audit::log(
            action: 'إجراء على ملف تنفيذ: '.$action,
            description: "نفّذ {$user->name} إجراء «{$action}» على ملف التنفيذ {$execution->number} — حالته الآن: {$fresh->status}.",
            category: 'قضايا وتنفيذ',
            severity: in_array($action, ['approveFee', 'setFee', 'close', 'reject', 'rejectOffer'], true) ? 'warning' : 'info',
            auditable: $execution,
            auditableRef: $execution->number,
            afterState: ['الحالة' => $fresh->status, 'المرحلة' => $fresh->effectiveStage()] + self::actionAudit($action, $fresh),
        );

        return back();
    }

    /**
     * تفاصيل الإجراء في القيد المركزيّ — كان يسجّل الحالة والمرحلة فقط، فلا يُعرف من السجلّ
     * رقمُ الطلب ولا المحكمة ولا المبلغ المحصَّل، وهي بيانات المرجع الخارجيّ والمال. القيم من
     * الصفّ **بعد** التحديث لا من الطلب، فيُوثَّق ما استقرّ فعلاً. ولا قيد ثانٍ: تُدمج في القائم.
     * (كانت `najizAudit` مقصورةً على خطوات ناجز، ثمّ لزم الإسنادُ التوثيقَ نفسه — فاسمها عمَّ.)
     *
     * @return array<string, string|int>
     */
    private static function actionAudit(string $action, Execution $fresh): array
    {
        return match ($action) {
            // من صار مسؤولاً عن الملفّ — أهمّ ما يُسأل عنه لاحقاً في سجلّ التدقيق
            'assignLawyer' => ['المحامي المسنَد' => (string) $fresh->assigned_lawyer],
            'fileNajiz' => [
                'رقم الطلب في ناجز' => (string) $fresh->najiz_request_no,
                'تاريخ الرفع' => $fresh->najiz_filed_at?->toDateString() ?? '',
            ],
            'registerNajiz' => [
                'محكمة التنفيذ' => (string) $fresh->court,
                'الدائرة' => (string) $fresh->circuit,
                'تاريخ القيد' => $fresh->registered_at?->toDateString() ?? '',
            ],
            'notifyDebtor' => [
                'تاريخ الإبلاغ' => $fresh->notified_at?->toDateString() ?? '',
                'نهاية مهلة الوفاء' => $fresh->pay_due_at?->toDateString() ?? '',
            ],
            'applyMeasures' => [
                'إجراءات عدم الوفاء' => implode(' · ', $fresh->measures ?? []) ?: 'رُفعت الإجراءات',
            ],
            'addCollection' => [
                'إجمالي المحصَّل' => (int) $fresh->collected,
                'المتبقّي' => max(0, (int) $fresh->amount - (int) $fresh->collected),
            ],
            default => [],
        };
    }

    // ── سداد أتعاب التنفيذ عبر بوّابة ميسّر (المرحلة 6) ──

    /** يبدأ الدفع عبر ميسّر ويعيد التوجيه لصفحة الدفع المستضافة. التأكيد عبر webhook/callback. */
    public function pay(Request $request, Execution $execution): HttpFoundationResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Client && $execution->user_id === $user->id, 403);

        // **خطّة السداد قرار العميل** (قرار المالك 2026-09-12): كاملاً أو ثلاث دفعات. وفتحُ
        // الخطّة يُعيد هيكلة الفاتورة إلى دفعاتٍ حقيقيّة تمرّ كلُّها بالبوّابة — ولا يفتح
        // الملفَّ: يُفتح بأوّل دفعةٍ **مسوّاة فعلاً** لا باختيار الخطّة.
        $plan = $request->validate(['plan' => ['nullable', 'in:full,install']])['plan'] ?? 'full';
        if ($plan === 'install'
            && $execution->feeMode() === 'fixed'
            && $execution->payPlan() !== 'install'
            && ExecFee::openInstallmentPlan($execution) === null) {
            return back()->with('error', 'تعذّر فتح خطّة التقسيط — لا توجد فاتورة أتعاب مستحقّة.');
        }

        // رابط العودة من أصل الطلب نفسه (لا APP_URL) — فتبقى الجلسة صالحة
        $callback = $request->getSchemeAndHttpHost().route('exec-flow.pay.callback', $execution, absolute: false);
        $url = ExecService::initiatePayment($execution->fresh(), $callback); // يحرس المرحلة [6]، وnull إن تعذّر

        if ($url !== null) {
            return Inertia::location($url); // Inertia يوجّه المتصفّح لصفحة ميسّر
        }

        // **العميل يقرأ سببَ التعذّر لا صفحةَ 503 خام.** محوّل الاستثناءات (bootstrap/app.php)
        // يترجم 403/409/422 وحدها إلى أخطاء Inertia، و5xx مستثناة عمداً (وضع الصيانة منها) —
        // فلا يُوسَّع الاستثناء العامّ. الترجمة هنا: زيارةُ Inertia تُردّ برسالةٍ على الشاشة،
        // ويبقى 503 لمن يقرأ الرمز (axios/الاختبارات) كعقد `EnsurePermission` نفسه.
        if (! app(MoyasarService::class)->isConfigured()) {
            $message = 'بوّابة الدفع غير مهيّأة — تواصل مع المكتب لإتمام السداد.';
            abort_unless($request->inertia(), 503, $message);

            return back()->withErrors(['message' => $message]);
        }

        return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
    }

    /**
     * **مُدخل التسعير — والتحقّق يتبع النموذج.** في النموذج الثابت الرقم إلزاميّ كما كان
     * (`min:1`: عرضٌ بصفرٍ لا يُرسل)، وفي النسبيّ لا مبلغ أصلاً بل نسبةٌ من كلّ محصَّل —
     * فإلزامُ المبلغ هناك يمنع النموذج كلّه، وإسقاطُ التحقّق في الحالتين يفتح بابَ العرض
     * بصفر الذي أُغلق سابقاً.
     *
     * السقف 50%: ما فوقها خطأُ إدخالٍ (خانتان بلا فاصلة) لا سياسةُ تسعير — والإدارة تضبطه
     * من الإعدادات، و`ExecFee::MAX_PCT` افتراضه المُعلَن.
     *
     * @return array{0:int,1:string,2:string,3:?float} [الأتعاب، المدّة، النموذج، النسبة]
     */
    private static function feeInput(Request $request): array
    {
        $mode = (string) $request->input('feeMode', '') === 'percent' ? 'percent' : 'fixed';

        $data = $request->validate($mode === 'percent'
            ? ['feePct' => ['required', 'numeric', 'min:0.01', 'max:'.SettingsRegistry::int('exec_max_collection_pct')], 'fee' => ['nullable', 'integer', 'min:0']]
            : ['fee' => ['required', 'integer', 'min:1'], 'feePct' => ['nullable', 'numeric']]);

        return [
            $mode === 'percent' ? 0 : (int) $data['fee'],
            (string) $request->input('duration', ''),
            $mode,
            $mode === 'percent' ? (float) $data['feePct'] : null,
        ];
    }

    /** العودة من صفحة ميسّر — تحقّق خادميّ صارم (يُعاد جلب الدفعة والتحقّق من انتمائها لفاتورة هذا الطلب). */
    public function payCallback(Request $request, Execution $execution): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Client && $execution->user_id === $request->user()->id, 403);

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        // **أيّ فاتورةٍ لهذا الطلب، لا الأحدث.** مع خطّة تقسيطٍ من ثلاث فواتير كانت
        // `latest('id')` هي الدفعة الثالثة، فعودةُ العميل من سداد الأولى لا تطابق مرجعاً
        // فيقرأ «تعذّر تأكيد الدفع» وقد خُصم منه المبلغ.
        $ref = (string) ($payment['invoice_id'] ?? '');
        $belongs = $payment !== null && $ref !== ''
            && $execution->invoices()->where('gateway_ref', $ref)->exists();

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
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403); // عزل المحامي بالإسناد
        abort_if($execution->isClosed(), 422, 'لا يمكن إرسال رسائل على ملفّ تنفيذ مغلق.');

        $data = $request->validate(['body' => ['required', 'string']]);

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
        abort_if($execution->isClosed(), 422, 'لا يمكن إرفاق مستندات على ملفّ تنفيذ مغلق.');

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
            'body' => '<p>تم إرفاق مستند:</p><div class="doc-list">'.ConversationFiles::chip('exec', $doc).'</div>',
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
        abort_unless($user->can('إدارة القضايا والأتعاب'), 403); // إجراء على ملفّ موكّل — يستوجب الصلاحية
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403); // عزل المحامي بالإسناد
        abort_unless($document->execution_id === $execution->id, 404);
        abort_unless($document->status === 'مرفوع', 422, 'لا يمكن مراجعة مستند لم يُرفَع بعد.');

        $decision = (string) $request->validate(['decision' => ['required', 'in:accept,reject']])['decision'];
        $document->update(['status' => $decision === 'accept' ? 'مقبول' : 'مرفوض']);

        $msg = $decision === 'accept' ? "اعتُمد مستند «{$document->label}»." : "أُعيد مستند «{$document->label}» لإعادة الرفع.";
        Notify::send($execution->user_id, 'file', $decision === 'accept' ? 't-green' : 't-amber', "$msg (ملفّ التنفيذ {$execution->number})");

        Audit::log(
            action: $decision === 'accept' ? 'اعتماد مستند تنفيذ' : 'رفض مستند تنفيذ',
            description: "{$user->name}: {$msg} (ملف التنفيذ {$execution->number}).",
            category: 'قضايا وتنفيذ',
            severity: $decision === 'accept' ? 'info' : 'warning',
            auditable: $execution,
            auditableRef: $execution->number,
            afterState: ['المستند' => $document->label, 'القرار' => $decision === 'accept' ? 'مقبول' : 'مرفوض'],
        );

        return back()->with('success', $msg);
    }

    // ── تنزيل مستند التنفيذ المرفوع (صاحب الملف أو المكتب المصرَّح له) ──

    public function downloadDocument(Request $request, Execution $execution, ExecutionDocument $document): StreamedResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        // الطاقم يحتاج صلاحية الملفّات صراحةً — كان أي موظف بلا صلاحية يُنزّل مستندات أي موكّل
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true) && $user->can('إدارة القضايا والأتعاب');
        abort_unless($isClient || $isStaff, 403);
        // والموظّف يلزمه «تنزيل مرفقات الملفات» فوقها — القاعدة نفسها في `ConversationFiles`
        abort_if($user->role === Role::Employee && ! ConversationFiles::employeeMayDownload($user), 403, 'لا تملك صلاحيّة تنزيل مرفقات الملفات — تمنحها الإدارة من تبويب الموظّفين.');
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        abort_unless($document->execution_id === $execution->id, 404);
        abort_if($document->path === null, 404, 'الملف غير موجود على الخادم.');
        abort_unless(Storage::exists($document->path), 404, 'الملف غير موجود على الخادم.');

        return Storage::download($document->path, $document->label);
    }

    // ── طباعة عرض/فاتورة خدمة التنفيذ (PDF حقيقي عبر Browsershot — نظير InvoiceController::pdf) ──

    public function offerPdf(Request $request, Execution $execution): HttpFoundationResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true) && $user->can('إدارة القضايا والأتعاب');
        abort_unless($isClient || $isStaff, 403);
        abort_if($user->role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        // **النموذج النسبيّ عرضٌ بلا مبلغ.** الحارس على `fee > 0` وحده كان سيمنع طباعة كلّ
        // عرضٍ نسبيّ — وفيه ما يُطبع: النسبة والمدّة والنموذج. والطلب غير المسعَّر يبقى مردوداً.
        $percent = $execution->feeMode() === 'percent';
        abort_unless((int) $execution->fee > 0 || ($percent && (float) $execution->collection_fee_pct >= 0.01), 422, 'لا يوجد عرض/فاتورة على هذا الطلب بعد.');

        $total = (int) $execution->fee + (int) $execution->vat;
        $lawyer = $isClient ? LawyerName::forClient($execution->assigned_lawyer_id ? $execution->assignedLawyer : null, $execution->assigned_lawyer, '—') : ($execution->assigned_lawyer ?: '—');

        $html = ReportPrint::html([
            // **العنوان يتبع النموذج لا `paid`.** `executions.paid` صار يعني «انفتح الملفّ»
            // لا «وصل المال»، والملفّ النسبيّ يُفتح بالقبول بلا ريال — فكانت تُطبع ورقةٌ
            // عنوانها «فاتورة — مدفوعة» بينما قسمها الماليّ يقول «لا مبلغ مقدَّم».
            'title' => $percent ? 'عرض خدمة التنفيذ' : ($execution->paid ? 'فاتورة خدمة التنفيذ' : 'عرض خدمة التنفيذ'),
            'subtitle' => $percent
                ? 'نسبة من المحصّل — لا مبلغ مقدَّم'
                : ($execution->paid ? 'مدفوعة' : 'بانتظار السداد'),
            'ref' => $execution->number,
            'blocks' => [
                [
                    'title' => '١. بيانات طلب التنفيذ',
                    'cellRows' => [
                        [['رقم الطلب', $execution->number], ['نوع السند', $execution->sanad ?: '—'], ['الموضوع', $execution->subject], ['المنفَّذ ضده', $execution->defendant ?: '—']],
                        [['قيمة المطالبة', number_format((int) $execution->amount).' ر.س'], ['الرقم المرجعيّ الداخليّ لملفّ التنفيذ', $execution->exec_no ?: '—'], ['رقم الفاتورة', $execution->invoice_no ?: '—']],
                    ],
                ],
                [
                    ['title' => '٢. بيانات العميل', 'cellRows' => [[['اسم العميل', $execution->user?->name ?: '—']]]],
                    ['title' => '٣. مقدّم الخدمة', 'cellRows' => [[['الجهة', 'المكتب القانوني'], ['المحامي المسؤول', $lawyer]]]],
                ],
                [
                    'title' => '٤. التفاصيل المالية',
                    // نسبة الضريبة من الإعدادات لا «١٥٪» منقوشة — الفاتورة تُحسب بـ`Setting::vatOn`،
                    // فكان المطبوع يخالفها متى غُيّرت النسبة.
                    'cellRows' => [$percent
                        ? [
                            ['نموذج الأتعاب', 'نسبة من المحصّل'],
                            ['نسبة الأتعاب', ExecFee::pctLabel((float) $execution->collection_fee_pct).'٪ من كل مبلغ يُحصَّل'],
                            ['ضريبة القيمة المضافة', 'تُضاف على كل فاتورة أتعاب بنسبة '.Setting::vatRate().'٪'],
                            ['المستحق مقدَّماً', 'لا مبلغ مقدَّم'],
                        ]
                        : [
                            ['أتعاب التنفيذ', number_format((int) $execution->fee).' ر.س'],
                            ['ضريبة القيمة المضافة ('.Setting::vatRate().'٪)', number_format((int) $execution->vat).' ر.س'],
                            ['الإجمالي المستحق', number_format($total).' ر.س'],
                            ['طريقة السداد', $execution->pay_method ?: '—'],
                        ],
                    ],
                ],
            ],
            'approval' => [
                'qrSeed' => $execution->number,
                'rows' => [
                    ['الجهة', 'النظام الإداري لمكاتب المحاماة'],
                    ['حالة الطلب', $execution->status],
                    ['تاريخ الطباعة', now()->format('Y-m-d')],
                ],
            ],
            'note' => 'هذا المستند يمثّل عرض/فاتورة خدمة التنفيذ الصادرة عن المكتب، ولا يُعدّ بذاته سنداً تنفيذياً أو حكماً قضائياً.',
            'footer' => 'النظام الإداري لمكاتب المحاماة — صادر إلكترونياً',
        ]);

        return PdfRenderer::render($html, $execution->number.'.pdf');
    }

    // ── رفع مستند مطلوب من العميل ──

    public function uploadDocument(Request $request, Execution $execution, ExecutionDocument $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Client && $execution->user_id === $user->id, 403);
        abort_unless($document->execution_id === $execution->id, 404);
        abort_if($execution->isClosed(), 422, 'لا يمكن رفع مستندات على ملفّ تنفيذ مغلق.');
        // الشاشة لا تعرض الرفع إلا للمطلوب والمعاد، والخادم كان يقبله على أيّ مستند: فطلبٌ
        // مباشر يستبدل مستنداً **اعتمده المكتب** بآخر، ويبقى وسمه «مقبول».
        abort_unless(in_array($document->status, ['مطلوب', 'مرفوض'], true), 422, 'هذا المستند لا يقبل الرفع في حالته الحالية.');

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

        // كإرفاق المحادثة: يُحلَّل المستند ويظهر في السجلّ — كان الرفع المطلوب يمرّ صامتاً بلا أثر
        AnalyzeExecutionDocumentJob::dispatch($execution, $document);
        $execution->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>تم رفع المستند المطلوب:</p><div class="doc-list">'.ConversationFiles::chip('exec', $document).'</div>',
            'time_label' => $this->clock(),
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
