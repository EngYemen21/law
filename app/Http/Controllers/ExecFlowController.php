<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Jobs\AnalyzeExecutionDocumentJob;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\Payments\GatewayCallback;
use App\Services\Payments\PaymentGateways;
use App\Support\Audit;
use App\Support\ConversationFiles;
use App\Support\ConversationHandler;
use App\Support\DocumentVerification;
use App\Support\ExecFee;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\Finance\LawyerShare;
use App\Support\LawyerName;
use App\Support\Notify;
use App\Support\PdfRenderer;
use App\Support\Permissions;
use App\Support\ReportPrint;
use App\Support\SettingsRegistry;
use App\Support\UploadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
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
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'invoices'])
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()->map(fn (Execution $e) => $e->toFlowCard(false));

        return Inertia::render('execflow', [
            'buckets' => ExecFlow::BUCKETS,
            'role' => 'client',
            'execs' => $execs,
            'initialId' => $request->query('id'),
            'initialTab' => $request->query('tab'),
        ]);
    }

    public function lawyer(Request $request): Response
    {
        // التبويب الموحّد: المسند إليه (تدفّق + قديم) أو غير المسند القابل للالتقاط (تدفّق stage≥2) — عزل المحامي محفوظ
        $uid = $request->user()->id;
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'invoices'])
            ->where(fn ($q) => $q->where('assigned_lawyer_id', $uid)
                ->orWhere(fn ($p) => $p->whereNull('assigned_lawyer_id')->whereNotNull('stage')->where('stage', '>=', 2)))
            ->latest('id')->get();
        $execs = self::staffCards($execs);

        return Inertia::render('execflow', [
            'buckets' => ExecFlow::BUCKETS,
            'role' => 'lawyer',
            'execs' => $execs,
            'initialId' => $request->query('id'),
            'initialTab' => $request->query('tab'),
        ]);
    }

    public function admin(Request $request): Response
    {
        // التبويب الموحّد: كل التنفيذات (تدفّق + قديمة تُعرَض بمرحلة مشتقّة) — الإدارة ترى الكلّ
        $models = Execution::with(['user', 'procedures', 'messages', 'documents', 'invoices', 'assignedLawyer'])
            ->latest('id')->get();
        // نصيب المحامي للإدارة وحدها — يملأ حقل النسبة في بطاقتي الاعتماد والتسعير
        $execs = self::staffCards($models)->map(fn (array $card, int $i) => $card + [
            'lawyerPct' => $models[$i]->lawyer_pct,
            'lawyerDefaultPct' => LawyerShare::defaultPctFor($models[$i]->assignedLawyer),
        ]);

        return Inertia::render('execflow', [
            'buckets' => ExecFlow::BUCKETS,
            'role' => 'admin',
            'execs' => $execs,
            'lawyers' => self::assignableLawyers(),
            'initialId' => $request->query('id'),
            'initialTab' => $request->query('tab'),
        ]);
    }

    public function employee(Request $request): Response
    {
        // التبويب الموحّد لموظف الاستقبال: كل ملفّات التنفيذ (تدفّق + قديمة) — بوّابة الاستقبال والإحالة
        $execs = Execution::with(['user', 'procedures', 'messages', 'documents', 'invoices'])
            ->latest('id')->get();
        $execs = self::staffCards($execs);

        // قائمة الإسناد **لمن يُسند وحده**: موظّفٌ بلا «إجراءات المحكمة والجلسات» لا يفتح
        // مودال الإسناد أصلاً (`canAssign=false` على كلّ بطاقة)، فقائمةٌ في حمولته زينةٌ لا تُستعمل.
        $canAssign = (bool) $request->user()?->can(Permissions::COURT_PROCEEDINGS);

        return Inertia::render('execflow', [
            'buckets' => ExecFlow::BUCKETS,
            'role' => 'employee',
            'execs' => $execs,
            'lawyers' => $canAssign ? self::assignableLawyers() : [],
            'initialId' => $request->query('id'),
            'initialTab' => $request->query('tab'),
        ]);
    }

    /**
     * بطاقات الطاقم — ومعها مسؤول المحادثة وسجلّ تولّيها، **لكلّ الملفّات باستعلامَين** لا لكلّ ملفّ
     * (`ConversationHandler::historiesFor`). وبطاقة العميل (`client()`) لا تمرّ من هنا.
     *
     * @param  Collection<int, Execution>  $execs
     * @return Collection<int, array<string, mixed>>
     */
    private static function staffCards($execs)
    {
        $histories = ConversationHandler::historiesFor($execs);

        return $execs->map(fn (Execution $e) => $e->toFlowCard(false, true) + ['conversation' => $histories[$e->id]]);
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
        // محامي أو إدارة وحدهما — إغلاق الملفّ وتوثيق الإجراء يبقيان لهما
        $staffProcActions = ['addProcedure', 'close'];
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
            abort_unless($user->can(Permissions::MANAGE_CASES_AND_FEES), 403);
            abort_if($role === Role::Lawyer && $execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
        } elseif (in_array($action, $lawyerPickup, true)) {
            abort_unless($role === Role::Lawyer, 403);
            abort_unless($user->can(Permissions::MANAGE_CASES_AND_FEES), 403);
            // عزل: لا يتصرّف محامٍ على ملفّ مسند لزميل آخر (يلتقط غير المسند فيُختَم باسمه)
            abort_if($execution->assigned_lawyer_id !== null && $execution->assigned_lawyer_id !== $user->id, 403);
            // الإسناد **بعد** نجاح الإجراء لا قبله: كان يُختم الملفّ باسم المحامي ثم يرفض حارسُ
            // المرحلة الإجراءَ نفسه، فيبقى ملفٌّ مسنَداً لمن لم يفعل شيئاً (ويُحجب عن زملائه).
            $pickup = true;
        } elseif (in_array($action, $staffProcActions, true)) {
            abort_unless($role === Role::Lawyer || $role === Role::Admin, 403);
            abort_unless($user->can(Permissions::MANAGE_CASES_AND_FEES), 403);
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
                Role::Lawyer, Role::Admin => $user->can(Permissions::MANAGE_CASES_AND_FEES),
                Role::Employee => $user->can(Permissions::COURT_PROCEEDINGS),
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
                Role::Employee => $execution->assigned_lawyer_id === null && $user->can(Permissions::COURT_PROCEEDINGS),
                default => false,
            };
            abort_unless($allowed, 403, 'لا تملك صلاحية إسناد محامٍ لملفّ التنفيذ.');
        } else {
            abort(422, 'إجراء غير معروف.');
        }

        match ($action) {
            'refer' => ExecService::refer($execution, $user),
            'accept' => ExecService::accept($execution, $user),
            'requestDocs' => ExecService::requestDocs($execution, $user),
            'reject' => ExecService::reject($execution, $user),
            'saveFee' => ExecService::saveFee(
                $execution,
                ...self::feeInput($request),
                actor: $user,
            ),
            // 0 أو الفراغ = «اعتمد الأتعاب كما هي» (الحقل اختياري في الواجهة) ⇒ null.
            // والتحقق يمنع السالب والنصّ اللذين كانا يمرّان عبر (int) على مُدخل حرّ.
            'approveFee' => ExecService::approveFee(
                $execution,
                ((int) ($request->validate(['fee' => ['nullable', 'integer', 'min:0']])['fee'] ?? 0)) ?: null,
                actor: $user,
                lawyerPct: self::lawyerPctInput($request),
            ),
            'setFee' => ExecService::setFee(
                $execution,
                ...self::feeInput($request),
                actor: $user,
                lawyerPct: self::lawyerPctInput($request),
            ),
            'acceptOffer' => ExecService::acceptOffer($execution),
            'inquire' => ExecService::inquire($execution),
            'rejectOffer' => ExecService::rejectOffer($execution),
            'addProcedure' => ExecService::addProcedure(
                $execution,
                (string) $request->validate(['title' => ['required', 'string', 'max:200']])['title'],
            ),
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
                $user,
            ),
            'registerNajiz' => ExecService::registerNajiz(
                $execution,
                trim((string) $request->validate(['court' => ['required', 'string', 'max:160']])['court']),
                trim((string) $request->validate(['circuit' => ['required', 'string', 'max:160']])['circuit']),
                (string) $request->validate(['registered_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['registered_at'],
                $user,
            ),
            'notifyDebtor' => ExecService::notifyDebtor(
                $execution,
                (string) $request->validate(['notified_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['notified_at'],
                $user,
            ),
            'applyMeasures' => ExecService::applyMeasures(
                $execution,
                (array) ($request->validate(['measures' => ['array'], 'measures.*' => ['string', 'in:'.implode(',', ExecFlow::MEASURES)]])['measures'] ?? []),
                $user,
            ),
            'addCollection' => ExecService::addCollection(
                $execution,
                (int) $request->validate(['amount' => ['required', 'integer', 'min:1']])['amount'],
                trim((string) $request->input('note', '')),
                $user,
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

    // ── سداد أتعاب التنفيذ عبر بوّابة الدفع (المرحلة 6) ──

    /** يبدأ الدفع عبر بوّابة الدفع ويعيد التوجيه لصفحة الدفع المستضافة. التأكيد عبر webhook/callback. */
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
            return Inertia::location($url); // Inertia يوجّه المتصفّح لصفحة الدفع
        }

        // **العميل يقرأ سببَ التعذّر لا صفحةَ 503 خام.** كانت الترجمة هنا يدويّةً لأنّ المُحوِّل العامّ
        // كان يعرف 403/409/422 وحدها؛ صار `App\Support\ErrorResponse` يعرف كلّ رمز: زيارةُ Inertia
        // تعود برسالةٍ على الشاشة، ويبقى 503 لمن يقرأ الرمز (axios/الاختبارات).
        abort_unless(app(PaymentGateways::class)->default()->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة — تواصل مع المكتب لإتمام السداد.');

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
    /** نسبة المحامي من أتعاب الملفّ (الإدارة وحدها) — الفراغ ⇒ المحفوظة ثمّ نسبة ملفّه (`LawyerShare`). */
    private static function lawyerPctInput(Request $request): ?int
    {
        $pct = $request->validate(['lawyerPct' => ['nullable', 'integer', 'min:0', 'max:100']])['lawyerPct'] ?? null;

        return $pct === null ? null : (int) $pct;
    }

    /**
     * مدخلات الأتعاب بأسماء معاملات `ExecService::saveFee/setFee` — تُمرَّر بالبسط المسمّى فلا يعتمد الاستدعاء
     * على ترتيب المواضع.
     *
     * @return array{fee: int, duration: string, feeMode: string, feePct: float|null}
     */
    private static function feeInput(Request $request): array
    {
        $mode = (string) $request->input('feeMode', '') === 'percent' ? 'percent' : 'fixed';

        $data = $request->validate($mode === 'percent'
            ? ['feePct' => ['required', 'numeric', 'min:0.01', 'max:'.SettingsRegistry::int('exec_max_collection_pct')], 'fee' => ['nullable', 'integer', 'min:0']]
            : ['fee' => ['required', 'integer', 'min:1'], 'feePct' => ['nullable', 'numeric']]);

        return [
            'fee' => $mode === 'percent' ? 0 : (int) $data['fee'],
            'duration' => (string) $request->input('duration', ''),
            'feeMode' => $mode,
            'feePct' => $mode === 'percent' ? (float) $data['feePct'] : null,
        ];
    }

    /** العودة من صفحة الدفع — تحقّق خادميّ صارم (يُعاد جلب الدفعة والتحقّق من انتمائها لفاتورة هذا الطلب). */
    public function payCallback(Request $request, Execution $execution): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Client && $execution->user_id === $request->user()->id, 403);

        // **أيّ فاتورةٍ لهذا الطلب، لا الأحدث.** مع خطّة تقسيطٍ من ثلاث فواتير كانت
        // `latest('id')` هي الدفعة الثالثة، فعودةُ العميل من سداد الأولى لا تطابق مرجعاً
        // فيقرأ «تعذّر تأكيد الدفع» وقد خُصم منه المبلغ.
        // يعود العميل إلى **الملفّ نفسه** لا إلى قائمة التنفيذ (`?id=` يفتحه في الصفحة)
        $back = redirect()->route('execs', ['id' => $execution->number]);

        $outcome = GatewayCallback::confirm($request, Invoice::where('exec_id', $execution->id));
        if ($outcome->settled()) {
            $execution->refresh();

            return $back->with('success', $execution->feeFullySettled()
                ? 'تم تأكيد سداد أتعاب التنفيذ وفتح الملف.'
                : "تم تأكيد سداد الدفعة {$execution->installments_paid} من {$execution->installments_total} من أتعاب التنفيذ وفُتح الملف.");
        }

        return $back->with('error', $outcome->failureMessage());
    }

    // ── محادثة ملف التنفيذ (العميل ↔ المكتب) — بلا ردّ AI، إشعار للمكتب + بثّ لحظيّ ──

    public function message(Request $request, Execution $execution): HttpResponse
    {
        $user = $request->user();
        $isClient = $user->role === Role::Client && $execution->user_id === $user->id;
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true);
        abort_unless($isClient || $isStaff, 403);
        if ($isStaff) {
            abort_unless($user->can(Permissions::MANAGE_CASES_AND_FEES), 403);
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

        $request->validate(['file' => ['required', 'file', UploadLimits::rule(UploadLimits::ATTACHMENT_KB), 'mimes:pdf,jpg,jpeg,png,doc,docx,xlsx']]);

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
        abort_unless($user->can(Permissions::MANAGE_CASES_AND_FEES), 403); // إجراء على ملفّ موكّل — يستوجب الصلاحية
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
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true) && $user->can(Permissions::MANAGE_CASES_AND_FEES);
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
        $isStaff = in_array($user->role, [Role::Lawyer, Role::Admin, Role::Employee], true) && $user->can(Permissions::MANAGE_CASES_AND_FEES);
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
                    ['title' => '٣. مقدّم الخدمة', 'cellRows' => [[['الجهة', SettingsRegistry::str('office_name')], ['المحامي المسؤول', $lawyer]]]],
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
                            ['ضريبة القيمة المضافة ('.$execution->vatRate().'٪)', number_format((int) $execution->vat).' ر.س'],
                            ['الإجمالي المستحق', number_format($total).' ر.س'],
                            ['طريقة السداد', $execution->pay_method ?: '—'],
                        ],
                    ],
                ],
            ],
            'approval' => [
                // رابط التحقّق الموقَّع — المسح يُظهر حالة الطلب الآن (`DocumentVerification`)
                'qr' => DocumentVerification::url(DocumentVerification::EXECUTION, (string) $execution->number),
                'qrCaption' => 'امسح للتحقّق من المستند',
                'rows' => [
                    ['الجهة', SettingsRegistry::str('office_name')],
                    ['حالة الطلب', $execution->status],
                    ['تاريخ الطباعة', now()->format('Y-m-d')],
                ],
            ],
            'note' => 'هذا المستند يمثّل عرض/فاتورة خدمة التنفيذ الصادرة عن المكتب، ولا يُعدّ بذاته سنداً تنفيذياً أو حكماً قضائياً.',
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

        $request->validate(['file' => ['required', 'file', UploadLimits::rule(UploadLimits::DOCUMENT_KB), 'mimes:pdf,jpg,jpeg,png,docx']], [
            'file.required' => 'يرجى اختيار ملف.',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح ('.UploadLimits::label(UploadLimits::DOCUMENT_KB).').',
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
