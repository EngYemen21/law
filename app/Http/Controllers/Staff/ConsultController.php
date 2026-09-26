<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Journey\Enums\RescheduleReason;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\AnalyzeConsult;
use App\Domain\Journey\Transitions\Consult\ApproveConsultAnalysis;
use App\Domain\Journey\Transitions\Consult\CancelRequest;
use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Transitions\Consult\MarkNoShow;
use App\Domain\Journey\Transitions\Consult\ReferConsult;
use App\Domain\Journey\Transitions\Consult\RequestConsultDocs;
use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Domain\Journey\Transitions\Consult\StartSession;
use App\Domain\Journey\Workflow;
use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractConsultDecisionsJob;
use App\Jobs\FinalizeConsultJob;
use App\Models\Consult;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\Ai\AiReviewOutcome;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Services\ZoomService;
use App\Support\ConsultAppointments;
use App\Support\ConsultBooking;
use App\Support\ConsultSessionOutcome;
use App\Support\ConsultSummary;
use App\Support\DecisionTasks;
use App\Support\LawyerSpecialties;
use App\Support\Live;
use App\Support\Notify;
use App\Support\RoomDetails;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * استقبال الاستشارات للموظف/المحامي/الإدارة (يطابق consultRecvView + crStart/crEnd)
 * — متحكم واحد مشترك؛ يُصيَّر لصفحة الدور الحالي.
 */
class ConsultController extends Controller
{
    use ScopedToLawyer;

    public function __construct(private LegalAiService $ai) {}

    // إدارة الاستشارات (يطابق emConsultsView/adConsultsView) — قائمة الرحلة والمؤشرات
    // (تُستثنى طلبات ما قبل الجلسة: تسعير/سداد/اختيار موعد — مكانها شاشة «طلبات الاستشارات»)
    public function index(Request $request): Response
    {
        // ترتيب بموعد الجلسة (الأقرب أولاً، بلا موعد آخراً) — كان بالمعرّف فتختلط الفائتة بالقادمة
        $consults = $this->scopeForRole($request, Consult::with(['user', 'appointment', 'invoice', 'ticket:id,number', 'ticket.legalCase:id,ticket_id,number']))
            ->whereNotIn('status', Consult::PRE_SESSION_STATUSES)
            ->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->latest('id')->get()
            ->map(fn (Consult $c) => $c->toCard());

        $lawyers = User::where('role', Role::Lawyer)->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'department'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: '—'])
            ->values()
            ->all();

        if (empty($lawyers)) {
            $lawyers = User::where('role', Role::Lawyer)
                ->orderBy('name')->get(['id', 'name', 'department'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: '—'])
                ->values()
                ->all();
        }

        $props = [
            'consults' => $consults,
            'lawyers' => $lawyers,
        ];

        if ($this->prefix($request) === 'admin' || $this->prefix($request) === 'employee' || $request->user()?->isAdmin() || $request->user()?->isEmployee()) {
            // `ticket.legalCase` مثل القائمة الأولى: `toCard()` يقرأ `caseNo` منها،
            // وبلا تحميلٍ مسبق يُصبح استعلامين لكلّ صفّ.
            $props['preSessionRequests'] = Consult::with(['user', 'invoice', 'appointment', 'ticket:id,number', 'ticket.legalCase:id,ticket_id,number'])
                ->whereIn('status', Consult::PRE_SESSION_STATUSES)
                ->latest('id')->get()
                ->map(fn (Consult $c) => $c->toCard());
            // سعر التسعير المقترح من الإعدادات بحسب القناة — كانت الشاشة تفترض «600» لكلّ قناة
            $props['suggestedPrices'] = self::suggestedPrices();
        }

        return Inertia::render($this->prefix($request).'/consults', $props);
    }

    // طلبات الاستشارات وتسعيرها (الإدارة العليا فقط) — دورة الحجز قبل الجلسة + إجراء التسعير
    public function requests(Request $request): Response
    {
        $consults = Consult::with(['user', 'invoice', 'appointment'])
            ->whereIn('status', Consult::PRE_SESSION_STATUSES)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toCard());

        /*
         * **الأسعار والضريبة من الإعدادات لا من الشاشة.**
         *
         * كانت الشاشة تحسب الضريبة بـ`0.15` مصلَّبة وتعرض «باقات معياريّة» من ستّة
         * أرقامٍ مكتوبةٍ بيدٍ لا يطابق واحدٌ منها سعراً معتمداً. والنسبة والأسعار
         * إعداداتٌ إداريّة حيّة لها شاشةُ ضبطٍ في اللوحة نفسها — فتُمرَّر.
         */
        $prices = Setting::consultPrices();

        return Inertia::render('admin/consult-requests', [
            'consults' => $consults,
            // `consultPrices()` يحمل الضريبة دائماً من `Setting::vatRate()` — فلا افتراضَ «15» ثانٍ هنا
            'vatRate' => (int) $prices['vat'],
            // محامو المكتب النشطون — لتعديل المحامي عند اعتماد موعدٍ اقترحه موظّف
            'lawyers' => User::where('role', Role::Lawyer)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'suggestedPrices' => self::suggestedPrices(),
        ]);
    }

    /**
     * السعر المقترح لكلّ قناة من الإعدادات (`Setting::consultPrices`) — مصدرٌ واحد لشاشتَي التسعير
     * («طلبات الاستشارات» و«الاستشارات») بدل رقمٍ مكتوبٍ في كلٍّ منهما.
     *
     * @return array<string, int>
     */
    private static function suggestedPrices(): array
    {
        $prices = Setting::consultPrices();

        return [
            'مرئية' => (int) ($prices['video'] ?? 0),
            'حضورية' => (int) ($prices['office'] ?? 0),
            'هاتفية' => (int) ($prices['phone'] ?? 0),
        ];
    }

    // رحلة الاستشارة (يطابق consultView) — التفاصيل والإجراءات وسجل التدقيق
    public function show(Request $request): Response
    {
        $consult = Consult::with(['user', 'appointment', 'ticket:id,number', 'ticket.legalCase:id,ticket_id,number'])
            ->where('ref', (string) $request->query('ref'))
            ->firstOrFail();
        $this->guardConsult($request, $consult);

        return Inertia::render($this->prefix($request).'/consult', [
            'consult' => $consult->toCard(),
            'lawyers' => $this->lawyerOptions($consult),
        ]);
    }

    /** محامون نشطون حقيقيون للإحالة/التعيين، يتصدّرهم متخصّصو قسم الاستشارة. */
    private function lawyerOptions(Consult $consult): array
    {
        $specialty = $consult->specialty ?: $consult->type;

        return User::where('role', Role::Lawyer)->where('status', 'active')
            ->with('specialties')
            ->orderBy('name')->get(['id', 'name', 'department', 'covers_all_departments'])
            ->sortByDesc(fn (User $u) => LawyerSpecialties::covers($u, $consult->legal_department_id, $specialty) ? 1 : 0)
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: '—'])
            ->all();
    }

    // تسعير طلب استشارة «بانتظار التسعير» (مطابق للتصميم) — يُصدر الفاتورة ويُشعر العميل للسداد
    public function setPrice(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        // `min:1` لا `min:0` — الصفر كان يُنشئ فاتورةً ميتة ويعلّق الطلب (انظر `ConsultBooking::setPrice`)
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:1', 'max:100000'],
            // تصحيح القناة عند التسعير — من كتالوج القنوات الواحد لا نصّاً مكرّراً
            'channel' => ['nullable', 'string', Rule::in(Consult::CHANNELS)],
        ]);

        ConsultBooking::setPrice($consult, (int) $data['price'], $request->user(), $data['channel'] ?? null);

        return back()->with('flash', 'تم تحديد سعر الاستشارة وإصدار الفاتورة.');
    }

    /**
     * **تصحيح تسعيرٍ خاطئ — قبل السداد.**
     *
     * لم يكن في المشروع مسارٌ لإعادة التسعير إطلاقاً: `setPrice` يشترط «بانتظار
     * التسعير»، وأوّلُ تسعيرٍ ينقل الحالة إلى «بانتظار السداد» فتُقفل. وشاشة الطلبات
     * تعرض زرّ «تعديل السعر» مصيرُه ٤٢٢ دائماً. فالخطأ في رقمٍ يُرسَل إلى عميلٍ في
     * فاتورة **لا مخرج منه إلّا إلغاء الطلب كلّه**.
     *
     * والتصحيح **قبل السداد وحده**: بعده يصير استرداداً ماليّاً لا تسعيراً، وذاك فعلٌ
     * محاسبيّ خارج هذا المسار. والفاتورة القديمة تُلغى ولا تُحذف — أثرُها يبقى.
     */
    public function reprice(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        abort_unless(
            $consult->status === 'بانتظار السداد',
            422,
            'التصحيح متاحٌ للطلبات المسعَّرة التي لم تُسدَّد بعد.'
        );
        abort_if(
            $consult->paid_at !== null,
            422,
            'سُدِّدت هذه الفاتورة — تصحيحُها بعد السداد استردادٌ ماليّ لا إعادة تسعير.'
        );

        ConsultBooking::reprice($consult, $request->user());

        return back()->with('flash', 'أُلغيت الفاتورة وعاد الطلب إلى التسعير.');
    }

    // طلب استكمال مستندات (يطابق cRequestDocs) — يُشعر العميل
    public function requestDocs(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        // **طلبُ المستند لا يُخرج الطلب من طابور التسعير.** كانت الدالّة بلا حارس
        // حالةٍ إطلاقاً: طلبُ مستندٍ على استشارةٍ «بانتظار السداد» يكتب «بانتظار
        // استكمال البيانات» — وهي خارج `PRE_SESSION_STATUSES` — فتسقط من طابور
        // `requests()` ولا تُسعَّر أبداً. وهو **عين العطل** الذي وُضع له حارسٌ في
        // `refer` (حادثة CN-2026-4504) ولم يوضع هنا، والشاشة تفتح البابين معاً.
        abort_if(
            in_array($consult->status, Consult::PRE_SESSION_STATUSES, true),
            422,
            'الطلب ما زال في دورة الحجز — أكمل التسعير والسداد قبل طلب المستندات.'
        );
        abort_if(
            in_array($consult->status, Consult::CLOSED_STATUSES, true),
            422,
            'الاستشارة انتهت أو أُلغيت — لا تُطلب لها مستندات.'
        );

        // **النصّ مطلوب.** كان `nullable` بافتراضيّ «مستند إضافي مطلوب»، فيصل العميلَ
        // طلبٌ **لا يقول ما المطلوب** باسم المكتب — ويعلق ملفّه بانتظار شيءٍ مجهول.
        // وصفحةُ رحلة الاستشارة كانت ترسل حمولةً فارغة أصلاً، فهذا حالُها الغالب.
        $data = $request->validate([
            'docs' => ['required', 'string', 'min:3', 'max:300'],
        ], [], ['docs' => 'المستند المطلوب']);

        Workflow::run(new RequestConsultDocs, $consult, $request->user(), ['docs' => $data['docs']]);
        Live::push(new ConsultStatusBroadcast($consult));

        Notify::send($consult->user_id, 'upload', 't-amber', "نحتاج استكمال مستندات لاستشارتك ({$consult->ref}): ".implode('، ', $consult->missing).'.');

        return back();
    }

    // بدء معالجة الفريق القانوني (يطابق cRunAI/cRerun) — تحليل ذكي
    public function analyze(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // **الحارس قبل نداء النموذج** — لا يُنفَق نداءٌ على ملفٍّ سيُرفض انتقاله (ع١٩).
        $transition = new AnalyzeConsult;
        $why = $transition->guard($consult, []);
        abort_if($why !== null, 422, (string) $why);

        $ai = $this->ai->analyzeConsult($consult);

        $source = AiSource::tryFrom((string) ($ai['source'] ?? AiSource::AiSuccess->value)) ?? AiSource::AiSuccess;
        $isRealAnalysis = $source->isRealAnalysis();

        Workflow::run($transition, $consult, $request->user(), [
            'class' => $ai['class'],
            'summary' => $ai['summary'],
            'lawyer' => $ai['lawyer'],
            // كان `true` بلا شرط: نصّ الاحتياطيّ يقول «تعذّر إعداد التحليل» والحالة تقول «اكتمل»
            'done' => $isRealAnalysis,
            'source' => $source->value,
            'missing' => $ai['missing'] ?? [],
        ]);

        $meta = is_array($ai['meta'] ?? null) ? $ai['meta'] : [];
        // رأيٌ قانونيّ: عالي الحساسيّة ⇒ «يتطلّب مراجعة» دائماً ولو نجح التحليل.
        // واسم القيد `consult` وتعليمته `consult.analyze`.
        AiRunLogger::log('consult', $source, $meta, $consult, (string) $consult->ref, policyTask: 'consult.analyze');

        // تنبيه المكتب (الموظفون + الإدارة العليا) بعطل التحليل — العميل لا يُطلَع عليه
        if (! $isRealAnalysis) {
            foreach (User::whereIn('role', [Role::Employee, Role::Admin])->pluck('id') as $staffId) {
                Notify::send($staffId, 'info', 't-amber', "الاستشارة {$consult->ref}: تعذّر التحليل الذكيّ — يلزم إعداد الرأي القانوني يدوياً قبل الاعتماد.");
            }
        }

        Live::push(new ConsultStatusBroadcast($consult));

        return back();
    }

    // حفظ تعديلات التحليل (يطابق cSaveAI) — تُوثّق الفروقات في سجل التدقيق
    public function saveAnalysis(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        $data = $request->validate([
            'aiClass' => ['required', 'string', 'max:120'],
            'aiSummary' => ['required', 'string', 'max:6000'],
            'aiLawyer' => ['required', 'string', 'max:80'],
        ]);

        $user = $request->user()->name;
        if ($data['aiClass'] !== $consult->ai_class) {
            $consult->logAudit($user, 'التصنيف', (string) $consult->ai_class, $data['aiClass']);
        }
        if ($data['aiSummary'] !== $consult->ai_summary) {
            $consult->logAudit($user, 'الملخص', '(نص سابق)', '(نص محدّث)');
        }
        if ($data['aiLawyer'] !== $consult->ai_lawyer) {
            $consult->logAudit($user, 'المحامي المقترح', (string) $consult->ai_lawyer, $data['aiLawyer']);
        }
        $consult->update(['ai_class' => $data['aiClass'], 'ai_summary' => $data['aiSummary'], 'ai_lawyer' => $data['aiLawyer']]);

        return back();
    }

    /**
     * تحرير **ملخّص الجلسة** قبل اعتماده — نظير `MeetingController::saveSummary`.
     *
     * غيرُ `saveAnalysis` أعلاه: تلك تحرّر تحليل **ما قبل** الجلسة (`ai_summary`)
     * الذي لا يراه العميل؛ وهذه تحرّر النصّ الذي سيصل العميل في تقريره الرسميّ.
     *
     * ولم يكن له محرِّر: «تعديل واعتماد» خيارٌ في صندوق المراجعة بلا حقلٍ يستقبله،
     * فيُسجَّل «عدّل» والمنشور نصُّ النموذج حرفياً. ومخرج النموذج يُحفظ في
     * `summary_ai_original` عند أوّل تحرير، فيبقى الفرق مقيساً لا مُدَّعى.
     *
     * **والتحرير بعد الاعتماد مرفوض (422):** العميل قرأ النصّ وأُشعر باعتماده،
     * فتبديله صامتاً سحبٌ لا حفظ. سحبُه فعلٌ آخر يُشعِر صاحبه.
     */
    public function saveSummary(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->summaryApproved(), 422, 'اعتُمد هذا الملخّص ووصل العميل — تعديله بعد الاعتماد يقتضي سحبه أوّلاً.');
        // اعتمده المستشار ورُفع للإدارة — يُقفل عليه، والإدارة تعدّله إن لزم
        abort_if(
            ! $request->user()->isAdmin() && $consult->summary_lawyer_approved_at !== null,
            422,
            'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ — لا يُعدَّل من جهتك.'
        );

        $data = $request->validate(['summary' => ['required', 'string', 'max:8000']]);
        $summary = trim($data['summary']);

        if ($summary === (string) $consult->summary) {
            return back();
        }

        $consult->update([
            // أوّل تحرير يُجمّد مخرج النموذج؛ وما بعده تحريرٌ على تحرير فلا يدهسه
            'summary_ai_original' => $consult->summary_ai_original ?? $consult->summary,
            'summary' => $summary,
            'summary_edited_at' => now(),
            'summary_edited_by' => $request->user()->id,
            // قرارات النصّ القديم لا تبقى تحت نصٍّ جديد — تُستخرج من المحرَّر (ع٢٣)
            'decisions' => [],
        ]);

        $consult->logAudit($request->user()->name, 'ملخص الجلسة', '(نص النموذج)', '(نص محرَّر)');
        $consult->save();

        ExtractConsultDecisionsJob::dispatch($consult->fresh(), $summary);

        return back()->with('flash', 'حُفظ الملخّص المحرَّر — يصل العميل بعد اعتماده.');
    }

    /**
     * **اعتماد ملخّص الجلسة من شاشة الملفّ — بابٌ ثانٍ بكاتبٍ واحد.**
     *
     * كان الاعتماد لا يقع إلّا من صندوق المراجعة، والصندوق لا يعرض إلّا ما له قيدٌ
     * في `ai_runs`، والقيد لا يُنشأ إلّا بنداءٍ ناجح للنموذج. فملخّصٌ كتبه المحامي
     * بيده — وهو ما يقع كلّما انتهت جلسةٌ بلا تدوين — كان **محجوباً عن العميل
     * للأبد**: لا في الصندوق فلا يُعتمد، ولا يصل فلا يُقرأ.
     *
     * ولا يُزوَّر له قيد: «قيدٌ بلا نداء» يُفسد إحصاء الكلفة والتغطية (انظر
     * `FinalizeConsultJob` و`ConsultNoMaterialTest`). فالاعتماد فعلٌ على الملفّ،
     * و`recordFileApproval` تسجّله في القيد **إن وُجد** وتصمت إن لم يوجد.
     */
    public function approveSummary(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->summaryApproved(), 422, 'اعتُمد هذا الملخّص ووصل العميل.');
        abort_if(blank($consult->summary), 422, 'لا ملخّص ليُعتمد — دوّن تدوين الجلسة أو اكتب التقرير أوّلاً.');
        // **لا «ملخّص جلسة» لجلسةٍ لم تنعقد** — كان يُعتمد لاستشارةٍ «جديدة» ويصل العميل (ع٢٢)
        abort_unless($consult->session === 'منتهية', 422, 'لم تنعقد هذه الجلسة — لا يُعتمد لها ملخّص جلسة.');

        $user = $request->user();
        $edited = $consult->summary_edited_at !== null;

        // ── المرحلة الأولى: المستشار يعتمد ويرفع للإدارة — لا يصل الموكّلَ شيء ──
        if (! $user->isAdmin()) {
            abort_if($consult->summary_lawyer_approved_at !== null, 422, 'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ.');

            AiReviewOutcome::lawyerApproveConsultSummary($consult, $user);
            AiReviewOutcome::recordFileApproval('consult.summary', $consult->ref, $user, edited: $edited);

            return back()->with('flash', 'اعتُمد الملخّص ورُفع للإدارة لاعتماده النهائيّ قبل إرساله للموكّل.');
        }

        // ── المرحلة الثانية: الإدارة تعتمد فيُنشر للموكّل وتكتمل تذكرته ──
        // **لا تُنادَ `AiReviewOutcome::apply()` هنا**: مسار الصندوق يُشعِر العميل، فمناداته تُشعره مرّتين.
        AiReviewOutcome::approveConsultSummary($consult, $user);
        AiReviewOutcome::recordFileApproval('consult.summary', $consult->ref, $user, edited: $edited);

        return back()->with('flash', 'اعتُمد الملخّص وأُرسل إلى الموكّل، ونُشرت نتيجة التذكرة.');
    }

    // اعتماد التحليل (يطابق cApproveAI) → جاهزة للمحامي
    public function approveAnalysis(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        /*
         * **الرفض يُقال لا يُبتلع.** كان ما ليس «بانتظار اعتماد الموظف» يُتجاوز بصمتٍ ويعود `back()`،
         * فتعرض الشاشة «✅ اعتُمد التحليل» على طلبٍ لم يُنفَّذ (نقرةٌ ثانية، أو تحليلٌ أُعيد للتشغيل،
         * أو استشارةٌ أُحيلت من شاشةٍ أخرى). الآن 422 بالسبب، ورفضُ المحرّك (`TransitionDenied`)
         * يصل الواجهة كما هو.
         */
        $transition = new ApproveConsultAnalysis;
        abort_unless(
            $transition->accepts((string) $consult->status),
            422,
            "لا تحليل بانتظار الاعتماد — الاستشارة في حالة «{$consult->status}»."
        );

        Workflow::run($transition, $consult, $request->user());
        Live::push(new ConsultStatusBroadcast($consult));

        // ويُسجَّل قرار المراجعة كما يُسجَّل من الصندوق — وإلّا بقي القيد معلَّقاً
        // أبداً لمخرجٍ اعتمده إنسان. و«حُرّر» يُشتقّ من سجلّ التدقيق: `saveAnalysis`
        // تكتب فيه قيد «الملخص» عند كل تحرير، فهو أثرُ فعلٍ لا إعلانُ نيّة.
        $edited = collect((array) ($consult->audit ?? []))
            ->contains(fn ($entry) => ($entry['field'] ?? '') === 'الملخص');

        // اسم القيد `consult` لا `consult.analyze` — انظر `AiRunLogger::STORED_TASK_TYPE`
        AiReviewOutcome::recordFileApproval('consult', $consult->ref, $request->user(), $edited);

        return back();
    }

    // الإحالة/تعيين المحامي (يطابق cRefer + adAssign) — يُشعر العميل
    public function refer(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // الحرّاس في `ReferConsult` (الجلسة الجارية · النهايات · دورة الحجز · التحليل غير المعتمد)،
        // وتُفحص قبل التصديق: لا يُسأل عن محامٍ لملفٍّ لا يُحال.
        $transition = new ReferConsult;
        $why = $transition->guard($consult, []);
        abort_if($why !== null, 422, (string) $why);

        $data = $request->validate([
            // مشترك بين الموظف/المحامي/الإدارة (كل بدوره عبر route مستقل) — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
            'lawyer' => ['nullable', 'string', 'max:80'],
        ]);

        // **المحامي بالمعرّف، أو بالاسم مطابقةً تامّة** — كان يسقط إلى `LIKE` غير مهرَّب على اقتراح الذكاء (ع٩).
        $name = trim($data['lawyer'] ?? '') ?: (string) ($consult->ai_lawyer ?: $consult->lawyer);
        $lawyerUser = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : ($name !== '' ? User::where('role', Role::Lawyer)->where('name', $name)->first() : null);

        Workflow::run($transition, $consult, $request->user(), [
            'lawyer_id' => $lawyerUser?->id,
            'lawyer' => $lawyerUser?->name ?: $name,
        ]);

        return back();
    }

    // تحديث الأولوية (الإدارة العليا — يطابق adConsult priority)
    public function priority(Request $request, Consult $consult): RedirectResponse
    {
        // الدالّة المُعدِّلة الوحيدة التي كانت بلا حارس — مسارها إداريّ اليوم، لكنّ
        // استثناءً بلا علّة يصير ثغرةً يوم يُفتح المسار لدورٍ آخر.
        $this->guardConsult($request, $consult);

        // **قاموسُ الاستشارات لا التذاكر.** كان يقبل `'عادية'` — وهي أولويّةُ فرز
        // **التذكرة** يكتبها الذكاء — ويرفض `'منخفضة'` التي تحملها استشاراتٌ قائمة
        // في القاعدة فعلاً: أي أن صفّاً لا يستطيع أحدٌ إعادة ضبطه على قيمته نفسها.
        $data = $request->validate(['priority' => ['required', 'string', Rule::in(Consult::PRIORITIES)]]);

        if ($data['priority'] !== $consult->priority) {
            $consult->logAudit($request->user()->name, 'الأولوية', $consult->priority, $data['priority']);
            $consult->priority = $data['priority'];
            $consult->save();
        }

        return back();
    }

    // استقبال الاستشارات — الجلسات حسب القناة حتى كتابة الملخص
    // (تُستثنى طلبات ما قبل الجلسة: تسعير/سداد/اختيار موعد — مكانها قائمة إدارة الاستشارات)
    public function recv(Request $request): Response
    {
        // ترتيب بموعد الجلسة (الأقرب أولاً، بلا موعد آخراً) — كان بالمعرّف فتختلط الفائتة بالقادمة
        $consults = $this->scopeForRole($request, Consult::with(['user', 'appointment', 'ticket:id,number', 'ticket.legalCase:id,ticket_id,number']))
            ->whereNotIn('status', Consult::PRE_SESSION_STATUSES)
            ->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->latest('id')->get()
            ->map(fn (Consult $c) => $c->toCard());

        return Inertia::render($this->prefix($request).'/consultrecv', [
            'consults' => $consults,
        ]);
    }

    // بدء الجلسة (يطابق crStart) — مرئية/هاتفية/حضورية
    public function start(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // بدأها ويبهوك Zoom أو زميلٌ قبل ثوانٍ — تكرارُ الفعل ليس خطأً.
        if ($consult->session === 'جلسة جارية') {
            return back();
        }

        // الحارس في الانتقال: نافذة الموعد ودورة الحجز والنهايات — مصدرٌ واحد مع البطاقة (`isStartable`).
        Workflow::run(new StartSession, $consult, $request->user());

        return back();
    }

    // إنهاء الجلسة وتوليد الملخص (يطابق vrEnd/crEnd + cRunAIFromSession)
    public function end(Request $request, Consult $consult): RedirectResponse
    {
        // الحارس قبل التصديق: غيرُ المسنَد يستحقّ ٤٠٣ لا رسائلَ تحقّقٍ تصف ملفّاً لا يراه.
        $this->guardConsult($request, $consult);

        /*
         * **لا تُختَم جلسةٌ لم تنعقد.**
         *
         * كانت الدالّة بلا حارس حالةٍ إطلاقاً — وحدها بين أخواتها. فمن يفتح
         * `/{role}/videoroom?ref=…` مباشرةً ويضغط «إنهاء» يكتب «منتهية» ويُشعر
         * الموكّل **«انتهت جلسة استشارتك»** ويُنهي اجتماع Zoom — لجلسةٍ لم تبدأ قطّ.
         *
         * وهذا حرفيّاً التزوير الذي كُتب `noShow()` لمنعه: تعليقُه يصف «بدء+إنهاء
         * فوري» بأنه «يزوّر السجل جلسةً منعقدة». وسُدَّ نصفُه بحارس `isStartable`
         * على `start`، وبقي البابُ الأوسع: لا حاجة إلى `start` أصلاً.
         *
         * و«منتهية» تبقى مقبولة: التدوين المتأخّر مسارٌ مقصود — الويبهوك يختم الجلسة
         * قبل أن يضغط المحامي، فيُحفظ تدوينه بعدها ويُستدعى التوليد ثانيةً.
         */
        //
        // **والحارس في الانتقال لا هنا** (`EndSession::guard` ⇐ `Consult::isLive`) — القاعدة نفسها
        // التي تُفعّل زرّ الإنهاء في عقد الغرفة؛ يُفحص هنا قبل التصديق وحده برسالة الانتقال.
        $alreadyEnded = $consult->session === SessionState::Ended->value;
        if (! $alreadyEnded && ($why = (new EndSession)->guard($consult, [])) !== null) {
            abort(422, $why);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'duration' => ['nullable', 'string', 'max:20'],
        ]);

        $notes = trim($data['notes'] ?? '');

        // **ختمُ الجلسة وحفظُ الملاحظات فعلان منفصلان.** كانا ملفوفين بشرطٍ واحد
        // (`session !== 'منتهية'`)، وويبهوك Zoom يختم الجلسة قبل أن يضغط المحامي
        // «إنهاء» — فتُصادَق ملاحظاته في الطلب ثمّ تُلقى صامتةً لأن الجلسة مختومة.
        $notesPayload = $notes !== '' ? ['session_notes' => $notes] : [];

        if (! $alreadyEnded) {
            // **وختمُ الجلسة يحسم موعدها** («تم الحضور») في الانتقال نفسه — انظر `EndSession`.
            // كان الإنهاء لا يحسمه، فيبقى صفّ الموعد `when_kind='up'` و`status='مؤكد'` يقرؤه كلّ
            // تقريرٍ أو ترشيح يعتمد العمود الخام قبل أن يحسمه `appointments:auto-lapse`.
            Workflow::run(new EndSession, $consult, $request->user(), $notesPayload + [
                'duration_label' => $data['duration'] ?? $consult->duration_label,
            ]);
            Live::push(new ConsultStatusBroadcast($consult));
        } elseif ($notesPayload !== []) {
            // تدوينٌ متأخّر لجلسةٍ مختومة — ليس انتقالاً، عمودٌ خارج الحالة
            $consult->update($notesPayload);
            Live::push(new ConsultStatusBroadcast($consult));
        }

        if (! $alreadyEnded) {
            // انعقدت الجلسة وانتهت ⇒ التذكرة «بانتظار ملخّص الجلسة» بلا زرٍّ للموظّف
            ConsultSessionOutcome::sessionEnded($consult);
        }

        // **وتُغلق غرفة Zoom فعلاً** — حدثُ `EndSession` بعد التزامه (`EndZoomMeetingJob` في
        // الطابور)، فيقع عند الختم وحده لا عند حفظ تدوينٍ لجلسةٍ مختومة، ولا يقف عليه الزرّ.

        // التوليد عند الختم، **أو** عند وصول ملاحظاتٍ لجلسةٍ خُتمت بلا ملخّص — وهي
        // حال «انتهت بلا تدوين»: الوظيفة لا تُنادي النموذج بلا مادّة، فتُنبّه المحامي،
        // ثمّ يدوّن فتُستدعى ثانيةً وتولّد. وبلا هذا الفرع يبقى تدوينُه بلا أثر.
        if (! $alreadyEnded || ($notes !== '' && blank($consult->summary))) {
            FinalizeConsultJob::dispatch($consult->fresh(), $notes);
        }

        return RoomDetails::afterEnd($consult, $request->user(), match (true) {
            ! $alreadyEnded => 'انتهت الجلسة وأُغلقت غرفتها — يُعدّ ملخّصها الآن.',
            $notes !== '' => 'حُفظ تدوينك للجلسة.',
            default => 'الجلسة منتهية بالفعل.',
        });
    }

    // تذكير عميلٍ دفع ولم يختر موعده — الطلب كان يعلق للأبد بلا أي إجراء إداري
    public function remindSchedule(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_unless($consult->status === 'بانتظار تحديد الموعد', 422, 'التذكير متاح للاستشارات المدفوعة التي لم يُحجز موعدها بعد.');

        // **الحجز بيد الطاقم لا العميل** (قرار المالك 2026-09-14) — التذكير لمن يحجز.
        $staff = User::whereIn('role', [Role::Employee, Role::Admin])->get()
            ->filter(fn (User $u) => $u->isAdmin() || $u->can('جدولة المواعيد'));
        foreach ($staff as $member) {
            Notify::send($member->id, 'cal', 't-amber', "تذكير: الاستشارة ({$consult->ref}) مدفوعة ولم يُحدَّد موعدها بعد — احجزه من شاشة المواعيد.");
        }

        $consult->logAudit($request->user()->name, 'تذكير', '—', 'تذكير الطاقم بحجز الموعد');
        $consult->save();

        return back()->with('flash', 'أُرسل التذكير لفريق المواعيد.');
    }

    /**
     * **اعتماد موعدٍ اقترحه موظّف — كما هو أو بعد تعديله** (قرار المالك 2026-09-14).
     *
     * الإدارة لا ترفض الاقتراح: تعدّل الوقت أو المحامي أو نوع الجلسة إن لزم، ثمّ يُنشر للعميل
     * ويُشعَر الموظّف بما تغيّر.
     */
    public function approveAppointment(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->status === 'بانتظار اعتماد الموعد', 422, 'لا موعد مقترح بانتظار الاعتماد لهذه الاستشارة.');

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['nullable', 'string', 'date_format:H:i'],
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
            'type' => ['nullable', 'string', 'in:office,video,phone'],
        ]);

        ConsultAppointments::publish($consult, $request->user(), array_filter($data, fn ($v) => $v !== null && $v !== ''));

        return back()->with('flash', "اعتُمد موعد الاستشارة {$consult->ref} وأُرسل للعميل.");
    }

    // إلغاء طلب معلّق قبل الجلسة — يُحيي حالة «ملغاة» التي لم يكن لها كاتب في النظام
    public function cancelRequest(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // رسالة عربيّة صريحة: لغة التطبيق الافتراضيّة إنجليزيّة ولا ملفّ ترجمة للتحقّق
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'reason.max' => 'سبب الإلغاء أطول من 500 حرف — اختصره ثم أعد المحاولة.',
            'reason.string' => 'سبب الإلغاء غير صالح.',
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));

        // الإلغاء يُلغي فواتيره غير المدفوعة ويُرجع التذكرة (ع١، ع١٠) — انظر `CancelRequest`.
        Workflow::run(
            new CancelRequest,
            $consult,
            $request->user(),
            array_filter(['reason' => $reason !== '' ? $reason : null])
        );

        return back()->with('flash', 'أُلغي الطلب وأُشعر العميل.');
    }

    // وسم «لم يحضر» لاستشارة فات موعدها بلا جلسة — كانت تعلق «بانتظار الجلسة» للأبد بلا أي إجراء،
    // والحيلة الوحيدة (بدء+إنهاء فوري) كانت تزوّر السجل جلسةً منعقدة
    public function noShow(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->session !== 'بانتظار الجلسة', 422, 'الجلسة بدأت أو انتهت — لا يصحّ وسمها «لم يحضر».');

        Workflow::run(new MarkNoShow, $consult, $request->user());

        return back();
    }

    // إعادة جدولة استشارة لم تنعقد: تعود لمرحلة اختيار الموعد، ويُلغى موعدها القديم واجتماع Zoom المرتبط
    public function reschedule(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // السبب إلزاميّ من قائمةٍ مغلقة (قرار المالك 2026-09-25) — والحرّاس كلّها في `RescheduleConsult`
        $data = $request->validate(RescheduleReason::rules('consult'));
        $reason = RescheduleReason::from($data['reason']);

        Workflow::run(new RescheduleConsult, $consult, $request->user(), [
            'reason_code' => $reason->value,
            'note' => $data['note'] ?? null,
            'reason' => $reason->describe($data['note'] ?? null),
        ]);

        return back();
    }

    /**
     * **رفضُ طلب العميل تغيير موعده — بسببٍ يصله.** (قرار المالك 2026-09-25)
     *
     * الطلب حالةٌ معلّقة لها صاحب: يُقضى بإعادة الجدولة (`RescheduleConsult` يمحوه)، أو يُرفض
     * هنا. والرفض بلا سبب يترك العميل لا يعرف أيحضر أم لا — فالسبب إلزاميّ ويصله نصّاً.
     */
    public function dismissRescheduleRequest(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->reschedule_requested_at === null, 422, 'لا طلبَ معلّقاً لتغيير موعد هذه الاستشارة.');

        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        $consult->forceFill(['reschedule_requested_at' => null, 'reschedule_request_note' => null]);
        $consult->logAudit($request->user()->name, 'رفض طلب تغيير الموعد', 'طلب العميل موعداً آخر', $reason);
        $consult->save();

        Notify::send($consult->user_id, 'cal', 't-amber', "تعذّر تغيير موعد استشارتك ({$consult->ref}) — {$reason}. يبقى موعدك كما هو: {$consult->whenLabel()}.");

        return back()->with('flash', 'رُفض الطلب وأُبلغ العميل بسببه — موعده باقٍ كما هو.');
    }

    // تحويل قرارات الاستشارة إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->tasks_created, 409, 'أُنشئت مهامّ هذه الاستشارة من قبل.');

        $count = DecisionTasks::create($consult, $this->ai, $request->user());
        abort_if($count === 0, 422, 'لم تُنشأ مهامّ: لا قرارات في ملخّص الاستشارة، أو لا محامي تُسند إليه.');

        return back()->with('flash', 'تم تحويل '.$count.' قرار إلى مهام');
    }

    /**
     * غرفة الجلسة المرئية للطاقم — `?ref=CN-…`.
     *
     * كانت بلا حارسٍ إطلاقاً: مرجعٌ مجهول يفتح غرفةً فارغة (لا 404)، واستشارةٌ منتهية أو فائتة أو
     * حضوريّة تُفتح غرفتها كأنّها قائمة. الآن `firstOrFail` والقاعدة الواحدة للغرف الأربع
     * (`RoomDetails::entryBlocker`) بسببها الحقيقيّ — نظيرُ غرفة الاجتماع حرفاً. («kind=req» لطلبات
     * الاجتماعات القديمة لم يعد لها منادٍ: غرفة الاجتماع `/{role}/meetingroom`.)
     */
    public function room(Request $request): Response|RedirectResponse
    {
        $consult = Consult::with(['user', 'assignedLawyer'])->where('ref', (string) $request->query('ref'))->firstOrFail();
        $this->guardConsult($request, $consult);
        if (($why = RoomDetails::entryBlocker($consult, $request->user())) !== null) {
            return RoomDetails::refuse($request, $consult, $why, 422);
        }

        return Inertia::render($this->prefix($request).'/videoroom', [
            // عقد الغرفة (`RoomDetails::for`) — والخاصيّات القديمة باقية حتى تنتقل الواجهة إليه
            'room' => RoomDetails::for($consult, $request->user()),
            'consult' => $consult->toCard(),
            'selfName' => $request->user()->name,
            'selfAv' => $request->user()->avatar_initials ?? '',
        ]);
    }

    private function prefix(Request $request): string
    {
        return match ($request->user()->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }

    /**
     * عزل قائمة الاستشارات بحسب الدور:
     * - المحامي: استشاراته المسندة فقط (assigned_lawyer_id) — يسدّ رؤية استشارات غيره.
     * - الموظف: كل استشارات المكتب (مجمّع الاستقبال المشترك قبل الإسناد وبعده).
     * - الإدارة: الكل.
     *
     * @param  Builder<Consult>  $query
     * @return Builder<Consult>
     */
    private function scopeForRole(Request $request, $query)
    {
        $user = $request->user();
        if ($user->role === Role::Lawyer) {
            $query->where('assigned_lawyer_id', $user->id);
        }

        return $query;
    }

    /**
     * حارس الوصول المباشر لسجل استشارة (يسدّ IDOR):
     * - المحامي: يُمنع (403) إن لم تكن الاستشارة مُسندة إليه (guardAssigned، الإدارة مستثناة).
     * - الموظف/الإدارة: الوصول مفتوح لكل سجلات المكتب.
     */
    private function guardConsult(Request $request, Consult $consult): void
    {
        if ($request->user()->role === Role::Lawyer) {
            $this->guardAssigned($consult);
        }
    }

    /**
     * استعلام يدوي من Zoom API: يسحب كل بيانات الجلسة (الحضور، المدة، التسجيل، النصّ،
     * الملخص) ويحدّث الاستشارة فوراً — لحالات تأخّر الويبهوك/السحب الدوري أو تعثّرهما.
     */
    public function zoomSync(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if(empty($consult->meet_id), 422, 'لا جلسة Zoom مرتبطة بهذه الاستشارة.');
        // الاعتماد نهائيّ: المزامنة تكتب القرارات، و`toClientCard` يُرسلها للعميل
        // بعد الاعتماد — فمزامنةٌ لاحقة تُبلغه ما لم تعتمده الإدارة.
        abort_if($consult->summary_approved_at !== null, 422, 'اعتُمد ملخّص هذه الاستشارة ووصل العميل — لا تُحدَّث بياناتها من Zoom بعد الاعتماد.');

        $pulled = ConsultSummary::pull($consult, app(ZoomService::class));

        return back()->with(
            $pulled ? 'flash' : 'error',
            $pulled
                ? 'تم تحديث بيانات الجلسة من Zoom.'
                : 'لا بيانات جديدة لدى Zoom بعد — الملخص يُعدّ عادةً خلال دقائق من انتهاء جلسة فعلية.'
        );
    }
}
