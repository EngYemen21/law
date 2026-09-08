<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\FinalizeConsultJob;
use App\Models\Consult;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\Ai\AiReviewOutcome;
use App\Services\Ai\AiRunLogger;
use App\Services\GoogleCalendarService;
use App\Services\LegalAiService;
use App\Services\ZoomService;
use App\Support\Audit;
use App\Support\Booking\BookingMoved;
use App\Support\ConsultBooking;
use App\Support\ConsultSummary;
use App\Support\DecisionTasks;
use App\Support\Live;
use App\Support\Notify;
use App\Support\Specialties;
use App\Support\TicketJourney;
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
            'vatRate' => (int) ($prices['vat'] ?? 15),
            'suggestedPrices' => [
                'مرئية' => (int) ($prices['video'] ?? 0),
                'حضورية' => (int) ($prices['office'] ?? 0),
                'هاتفية' => (int) ($prices['phone'] ?? 0),
            ],
        ]);
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
            ->orderBy('name')->get(['id', 'name', 'department'])
            ->sortByDesc(fn ($u) => Specialties::matches($u->department, $specialty) ? 1 : 0)
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: '—'])
            ->all();
    }

    // تسعير طلب استشارة «بانتظار التسعير» (مطابق للتصميم) — يُصدر الفاتورة ويُشعر العميل للسداد
    public function setPrice(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        // `min:1` لا `min:0` — الصفر كان يُنشئ فاتورةً ميتة ويعلّق الطلب (انظر `ConsultBooking::setPrice`)
        $data = $request->validate(['price' => ['required', 'integer', 'min:1', 'max:100000']]);

        ConsultBooking::setPrice($consult, (int) $data['price'], $request->user());

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

    // استلام الاستشارة (يطابق cTake)
    public function take(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        if ($consult->status === 'جديدة') {
            $consult->employee = $request->user()->name;
            $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'قيد مراجعة الموظف');
            $consult->status = 'قيد مراجعة الموظف';
            $consult->save();
            Live::push(new ConsultStatusBroadcast($consult));
        }

        return back();
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

        $missing = $consult->missing ?? [];
        $missing[] = trim($data['docs']);
        $consult->missing = array_values(array_unique($missing));
        $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'بانتظار استكمال البيانات');
        $consult->status = 'بانتظار استكمال البيانات';
        $consult->save();
        Live::push(new ConsultStatusBroadcast($consult));

        Notify::send($consult->user_id, 'upload', 't-amber', "نحتاج استكمال مستندات لاستشارتك ({$consult->ref}): ".implode('، ', $consult->missing).'.');

        return back();
    }

    // بدء معالجة الفريق القانوني (يطابق cRunAI/cRerun) — تحليل ذكي
    public function analyze(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // **لا يُعاد تحليل ملفٍّ انتهى.** زرّ «إعادة التحليل» في الشاشة بلا شرط حالة،
        // فاستشارةٌ منتهية أو ملغاة تعود بضغطة إلى «بانتظار اعتماد الموظف» — انتقالٌ
        // للخلف يُفقدها وسمها ويُخرجها من صفّ المحامي.
        abort_if(
            in_array($consult->status, Consult::CLOSED_STATUSES, true),
            422,
            'الاستشارة انتهت أو أُلغيت — لا يُعاد تحليلها.'
        );
        $before = $consult->status;
        $ai = $this->ai->analyzeConsult($consult);

        $source = AiSource::tryFrom((string) ($ai['source'] ?? AiSource::AiSuccess->value)) ?? AiSource::AiSuccess;
        $isRealAnalysis = $source->isRealAnalysis();

        $consult->ai_class = $ai['class'];
        $consult->ai_summary = $ai['summary'];
        $consult->ai_lawyer = $ai['lawyer'];
        // كان `true` بلا شرط: نصّ الاحتياطيّ يقول «تعذّر إعداد التحليل» والحالة تقول «اكتمل»
        $consult->ai_done = $isRealAnalysis;
        $consult->ai_source = $source->value;
        // **دمجٌ لا استبدال.** كان يدهس النواقص التي كتبها الموظّف يدوياً في
        // `requestDocs` وأُشعر بها العميل — فيُطالَب بمستندٍ لم يعد في ملفّ المكتب.
        $consult->missing = array_values(array_unique(
            array_merge($consult->missing ?? [], $ai['missing'] ?? [])
        ));
        $consult->logAudit($request->user()->name, 'الحالة', $before, 'قيد معالجة الفريق القانوني');
        $consult->logAudit('النظام', 'تحليل الفريق القانوني', '—', $isRealAnalysis ? 'اكتمل' : 'تعذّر — يلزم إعداد يدويّ');
        $consult->logAudit('النظام', 'الحالة', 'قيد معالجة الفريق القانوني', 'بانتظار اعتماد الموظف');
        $consult->status = 'بانتظار اعتماد الموظف';
        $consult->save();

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
        ]);

        $consult->logAudit($request->user()->name, 'ملخص الجلسة', '(نص النموذج)', '(نص محرَّر)');
        $consult->save();

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

        // **لا تُنادَ `AiReviewOutcome::apply()` هنا**: مسار الصندوق يُشعِر العميل،
        // فمناداته من هنا تُشعره مرّتين بالاعتماد الواحد.
        AiReviewOutcome::approveConsultSummary($consult, $request->user());
        AiReviewOutcome::recordFileApproval(
            'consult.summary',
            $consult->ref,
            $request->user(),
            edited: $consult->summary_edited_at !== null,
        );

        return back()->with('flash', 'اعتُمد الملخّص وأُرسل إلى الموكّل.');
    }

    // اعتماد التحليل (يطابق cApproveAI) → جاهزة للمحامي
    public function approveAnalysis(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        if ($consult->status === 'بانتظار اعتماد الموظف') {
            $consult->logAudit($request->user()->name, 'اعتماد التحليل', $consult->status, 'جاهزة للمحامي');
            $consult->status = 'جاهزة للمحامي';
            $consult->save();
            Live::push(new ConsultStatusBroadcast($consult));

            // ويُسجَّل قرار المراجعة كما يُسجَّل من الصندوق — وإلّا بقي القيد معلَّقاً
            // أبداً لمخرجٍ اعتمده إنسان. و«حُرّر» يُشتقّ من سجلّ التدقيق: `saveAnalysis`
            // تكتب فيه قيد «الملخص» عند كل تحرير، فهو أثرُ فعلٍ لا إعلانُ نيّة.
            $edited = collect((array) ($consult->audit ?? []))
                ->contains(fn ($entry) => ($entry['field'] ?? '') === 'الملخص');

            // اسم القيد `consult` لا `consult.analyze` — انظر `AiRunLogger::STORED_TASK_TYPE`
            AiReviewOutcome::recordFileApproval('consult', $consult->ref, $request->user(), $edited);
        }

        return back();
    }

    // الإحالة/تعيين المحامي (يطابق cRefer + adAssign) — يُشعر العميل
    public function refer(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);

        // **لا تُحال استشارةٌ ما زالت في دورة الحجز.**
        //
        // للاستشارة دورتان: دورةُ حجزٍ (تسعير ← سداد ← اختيار موعد) ودورةُ تحليلٍ
        // تنتهي بـ«جاهزة للمحامي» ثم الإحالة. وهذا الإجراء من الثانية، وكان بلا
        // شرطٍ واحد — فإحالةٌ على طلبٍ في الأولى تنقل حالته إلى «محالة للمحامي»،
        // وهي **خارج** `PRE_SESSION_STATUSES` التي يُبنى منها طابور التسعير لدى
        // الإدارة. فيسقط الطلب من الطابور: لا يُسعَّر، ولا تصل الفاتورة، ولا يدفع
        // العميل، ولا يختار موعداً، ولا تُعقد جلسة — وهو ينتظر بلا أن يعلم.
        //
        // وقع فعلاً في `CN-2026-4504` (٢٠٢٦-٠٩-٠٣): أُحيلت وهي «بانتظار التسعير»،
        // فظهرت في شاشة استقبال الجلسات بموعدٍ فارغ وزرِّ «بدء الجلسة» مُفعَّلاً.
        /*
         * **ولا تُحال جلسةٌ منعقدة.** كان الحارس يمنع النهايات ودورة الحجز ولا يمنع
         * `'قيد الاستشارة'` — فالضغط على «إعادة إسناد المستشار» أثناء جلسةٍ جارية
         * يُرجع الحالة إلى «محالة للمحامي» ويبثّها، ويصل صاحبَ الجلسة إشعارٌ يقول
         * «أُحيلت استشارتك… وسنوافيك بموعد الجلسة» — وهو فيها الآن.
         */
        abort_if(
            $consult->session === 'جلسة جارية',
            422,
            'الجلسة منعقدة الآن — أنهِها قبل تغيير المستشار.'
        );

        abort_if(
            in_array($consult->status, Consult::CLOSED_STATUSES, true),
            422,
            'الاستشارة انتهت أو أُلغيت — لا تُحال إلى محامٍ.'
        );

        abort_if(
            in_array($consult->status, Consult::PRE_SESSION_STATUSES, true),
            422,
            "الاستشارة ({$consult->ref}) ما زالت في دورة الحجز — حالتها «{$consult->status}». "
            .'إحالتها الآن تُخرجها من طابور التسعير فلا تُسعَّر ولا تصل الفاتورة العميلَ. '
            .'أكمل التسعير والسداد واختيار الموعد أوّلاً.'
        );

        $data = $request->validate([
            // مشترك بين الموظف/المحامي/الإدارة (كل بدوره عبر route مستقل) — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
            'lawyer' => ['nullable', 'string', 'max:80'],
        ]);

        // حلّ المحامي المختص إلى مستخدم حقيقي: بالمعرّف إن مُرّر، وإلا بمطابقة الاسم المقترح
        $name = trim($data['lawyer'] ?? '') ?: ($consult->ai_lawyer ?: $consult->lawyer);
        $lawyerUser = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : User::where('role', Role::Lawyer)->where('name', $name)->first();
        if (! $lawyerUser && $name !== '') {
            $lawyerUser = User::where('role', Role::Lawyer)->where('name', 'like', '%'.$name.'%')->first();
        }
        $lawyer = $lawyerUser?->name ?: $name;

        $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'محالة للمحامي');
        if ($lawyer !== $consult->lawyer) {
            $consult->logAudit($request->user()->name, 'المحامي', $consult->lawyer, $lawyer);
        }
        $consult->lawyer = $lawyer;
        // ربط المحامي بالمعرّف (مصدر عزل رؤية المحامي والبثّ)
        if ($lawyerUser) {
            $consult->assigned_lawyer_id = $lawyerUser->id;
        }
        $consult->status = 'محالة للمحامي';
        $consult->save();
        Live::push(new ConsultStatusBroadcast($consult));

        // مزامنة إسناد المحامي إلى التذكرة المرتبطة إن وجدت
        if ($consult->ticket_id && $lawyerUser) {
            Ticket::where('id', $consult->ticket_id)->update(['assigned_lawyer_id' => $lawyerUser->id]);
        }

        // النصّ يتبع وجود الموعد: «ستُعقد الجلسة في موعدها» طُمأنينةٌ إلى موعدٍ قد لا
        // يكون حُجز أصلاً — وصلت العميل مرّتين في `CN-2026-4504` ولا موعد لها.
        Notify::send($consult->user_id, 'scale', 't-green', $consult->starts_at !== null
            ? "أُحيلت استشارتك ({$consult->ref}) إلى المستشار المختص وستُعقد الجلسة في موعدها المحدَّد."
            : "أُحيلت استشارتك ({$consult->ref}) إلى المستشار المختص، وسنوافيك بموعد الجلسة.");

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

        // **ما تُعلنه البطاقة يفرضه الخادم.** كان `startable` (نافذة ١٥د) يُرسَل
        // إلى الواجهة ولا يُفرض هنا، فصار توصيةً تتجاهلها شاشةٌ لا تقرؤه: تُبدأ
        // استشارةٌ موعدها بعد ثلاثة أسابيع، أو فائتةٌ منذ شهر، فتُشعَر صاحبتُها
        // بأن جلستها «بدأت» ولا أحد هناك.
        abort_unless(
            $consult->isStartable(),
            422,
            $consult->isMissed()
                ? 'فات موعد هذه الجلسة — سجّل «لم يحضر» أو أعد جدولتها.'
                : 'الجلسة تُبدأ قبل موعدها بربع ساعة فأقرب.'
        );

        if ($consult->session === 'بانتظار الجلسة') {
            $consult->update(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);
            Live::push(new ConsultStatusBroadcast($consult));

            $verb = match ($consult->channel) {
                'مرئية' => 'بدأت جلسة استشارتك المرئية — يمكنك الانضمام الآن من صفحة «استشاراتي»',
                'هاتفية' => 'بدأت مكالمة استشارتك الهاتفية',
                default => 'بدأت جلسة استشارتك الحضورية',
            };
            Notify::send($consult->user_id, $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office'), 't-blue', "{$verb} ({$consult->ref}).");
        }

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
        abort_unless(
            in_array($consult->session, ['جلسة جارية', 'منتهية'], true),
            422,
            'الجلسة لم تبدأ — لا تُختَم إلّا جلسةٌ انعقدت. سجّل «لم يحضر» إن فات موعدها.'
        );

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'duration' => ['nullable', 'string', 'max:20'],
        ]);

        $notes = trim($data['notes'] ?? '');
        $alreadyEnded = $consult->session === 'منتهية';

        // **ختمُ الجلسة وحفظُ الملاحظات فعلان منفصلان.** كانا ملفوفين بشرطٍ واحد
        // (`session !== 'منتهية'`)، وويبهوك Zoom يختم الجلسة قبل أن يضغط المحامي
        // «إنهاء» — فتُصادَق ملاحظاته في الطلب ثمّ تُلقى صامتةً لأن الجلسة مختومة.
        $payload = $notes !== '' ? ['session_notes' => $notes] : [];

        if (! $alreadyEnded) {
            $payload += [
                'session' => 'منتهية',
                'status' => 'منتهية',
                'duration_label' => $data['duration'] ?? $consult->duration_label,
            ];
        }

        if ($payload !== []) {
            $consult->update($payload);
            Live::push(new ConsultStatusBroadcast($consult));
        }

        // **وتُغلق غرفة Zoom فعلاً.** كان «إنهاء الجلسة» يختم السجلّ وحده ولا يُنهي
        // الاجتماع — يبقى حيّاً حتى يخرج آخر مشارك، فيستطيع الموكّل (أو من بلغه
        // الرابط) البقاء فيه أو العودة إليه بعد أن أُعلنت الجلسة منتهية. ونظيرُه
        // في الاجتماعات يُنهيه منذ البداية (`Staff\MeetingController`).
        // الإنهاء **عند الختم وحده** لا عند حفظ تدوينٍ لجلسةٍ مختومة، وفشلُه
        // مُبتلَعٌ داخل `endMeeting` فلا يُسقط ختم الجلسة على المكتب.
        if (! $alreadyEnded && filled($consult->meet_id)) {
            app(ZoomService::class)->endMeeting((string) $consult->meet_id);
        }

        // التوليد عند الختم، **أو** عند وصول ملاحظاتٍ لجلسةٍ خُتمت بلا ملخّص — وهي
        // حال «انتهت بلا تدوين»: الوظيفة لا تُنادي النموذج بلا مادّة، فتُنبّه المحامي،
        // ثمّ يدوّن فتُستدعى ثانيةً وتولّد. وبلا هذا الفرع يبقى تدوينُه بلا أثر.
        if (! $alreadyEnded || ($notes !== '' && blank($consult->summary))) {
            FinalizeConsultJob::dispatch($consult->fresh(), $notes);
        }

        return back();
    }

    // تذكير عميلٍ دفع ولم يختر موعده — الطلب كان يعلق للأبد بلا أي إجراء إداري
    public function remindSchedule(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_unless($consult->status === 'بانتظار تحديد الموعد', 422, 'التذكير متاح للطلبات المدفوعة بانتظار اختيار الموعد فقط.');

        Notify::send($consult->user_id, 'cal', 't-amber', "تذكير: استشارتك ({$consult->ref}) مدفوعة وبانتظار اختيارك موعد الجلسة من «استشاراتي».");
        $consult->logAudit($request->user()->name, 'تذكير', '—', 'تذكير باختيار الموعد');
        $consult->save();

        return back()->with('flash', 'أُرسل التذكير للعميل.');
    }

    // إلغاء طلب معلّق قبل الجلسة — يُحيي حالة «ملغاة» التي لم يكن لها كاتب في النظام
    public function cancelRequest(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_unless(in_array($consult->status, Consult::PRE_SESSION_STATUSES, true), 422, 'الإلغاء متاح لطلبات ما قبل الجلسة فقط.');

        $before = $consult->status;
        $consult->status = 'ملغاة';
        $consult->logAudit($request->user()->name, 'الحالة', $before, 'ملغاة');
        $consult->save();

        GoogleCalendarService::deleteConsultEvent($consult);

        Audit::log(
            action: 'إلغاء طلب استشارة',
            description: "ألغى {$request->user()->name} طلب الاستشارة {$consult->ref} (كانت «{$before}»).",
            category: 'استشارات',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الحالة' => $before],
            afterState: ['الحالة' => 'ملغاة'],
        );

        Live::push(new ConsultStatusBroadcast($consult));
        Notify::send($consult->user_id, 'info', 't-grey', "أُلغي طلب استشارتك ({$consult->ref}). إن كنت قد سددت فسيتواصل معك المكتب بشأن الاسترداد.");

        return back()->with('flash', 'أُلغي الطلب وأُشعر العميل.');
    }

    // وسم «لم يحضر» لاستشارة فات موعدها بلا جلسة — كانت تعلق «بانتظار الجلسة» للأبد بلا أي إجراء،
    // والحيلة الوحيدة (بدء+إنهاء فوري) كانت تزوّر السجل جلسةً منعقدة
    public function noShow(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->session !== 'بانتظار الجلسة', 422, 'الجلسة بدأت أو انتهت — لا يصحّ وسمها «لم يحضر».');
        abort_unless($consult->isMissed(), 422, 'لم يحن موعد الاستشارة بعد.');

        $consult->session = 'لم تُعقد';
        $consult->status = 'لم يحضر';
        $consult->logAudit($request->user()->name, 'الجلسة', 'بانتظار الجلسة', 'لم يحضر');
        $consult->save();

        Live::push(new ConsultStatusBroadcast($consult));
        Notify::send($consult->user_id, 'clock', 't-red', "لم تُعقد جلسة استشارتك ({$consult->ref}) في موعدها. يمكنك التواصل مع المكتب لإعادة الجدولة.");

        return back();
    }

    // إعادة جدولة استشارة لم تنعقد: تعود لمرحلة اختيار الموعد، ويُلغى موعدها القديم واجتماع Zoom المرتبط
    public function reschedule(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if(
            in_array($consult->status, Consult::CLOSED_STATUSES, true),
            422,
            'الاستشارة انتهت أو أُلغيت — أنشئ طلباً جديداً بدل إعادة جدولتها.'
        );

        abort_if($consult->session === 'منتهية', 422, 'الجلسة انتهت — لا يمكن إعادة جدولتها.');
        abort_if(in_array($consult->status, Consult::PRE_SESSION_STATUSES, true), 422, 'الاستشارة لم تُجدول بعد أصلاً.');

        // إلغاء الموعد القديم (يظهر «ملغي» في تبويب المواعيد لا «لم يحضر»)
        $consult->appointment?->update(['status' => 'ملغي', 'tone' => 'b-grey', 'when_kind' => 'past']);

        GoogleCalendarService::deleteConsultEvent($consult);

        $old = $consult->when_label ?: '—';
        $consult->status = 'بانتظار تحديد الموعد';
        $consult->session = 'بانتظار الجلسة';
        $consult->starts_at = null;
        $consult->when_label = 'بانتظار اختيار موعد جديد';
        // **يُحذف اجتماع Zoom قبل تصفير مرجعه.** كانت الأعمدة تُصفَّر بلا حذف،
        // فيبقى اجتماعٌ يتيم على الحساب وتسجيله السحابيّ مُفعَّل، وأيّ ويبهوك متأخّر
        // عنه يصير غير قابلٍ للتوجيه (التوجيه بـ`meet_id`). والنظير في الاجتماعات
        // (`MeetingController::cancel`) يحذف — فافترق المساران بلا سبب.
        BookingMoved::cancelled($consult);

        $consult->meet_id = null;
        $consult->meet_link = null;
        $consult->host_link = null;
        $consult->meet_password = null;
        $consult->link_released_at = null;
        // تصفير أختام التذكير للموعد الجديد — بدونها لا يصل المؤجَّلة تذكير أبداً
        // (نظير ما تفعله إعادة جدولة جلسات المحاكم في Lawyer\CaseController).
        $consult->reminder_24h_sent_at = null;
        $consult->reminder_30m_sent_at = null;
        $consult->logAudit($request->user()->name, 'إعادة الجدولة', $old, 'بانتظار اختيار موعد جديد');
        $consult->save();

        /*
         * **والتذكرة ترتدّ مع استشارتها.**
         *
         * `ConsultBooking::schedule` يدفع التذكرة إلى «موعد مؤكد»، وإعادةُ الجدولة تُلغي
         * الموعد وتُصفّر `starts_at` — **ولا تمسّ التذكرة**. فتبقى تقول «موعد مؤكد»
         * لملفٍّ لا موعد له، وتصير مؤهَّلةً لـ`advance` الذي يعقد جلسةً لم تُحدَّد بعد.
         *
         * قِيس على `SB-2026-8077`: أُعيدت جدولة `CN-2026-7173` الساعة 01:57 وبقيت
         * التذكرة «موعد مؤكد».
         */
        if ($consult->ticket && $consult->ticket->status === 'موعد مؤكد') {
            $consult->ticket->update([
                'status' => 'بانتظار حجز الاستشارة',
                'tone' => TicketJourney::toneFor('بانتظار حجز الاستشارة'),
                'last_message' => 'أُعيدت جدولة الجلسة — بانتظار اختيار موعد جديد',
                'date_label' => 'الآن',
            ]);
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }

        Audit::log(
            action: 'إعادة جدولة استشارة',
            description: "أعاد {$request->user()->name} الاستشارة {$consult->ref} لاختيار موعد جديد (كان موعدها: {$old}).",
            category: 'استشارات',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الموعد' => $old],
            afterState: ['الحالة' => 'بانتظار تحديد الموعد'],
        );

        Live::push(new ConsultStatusBroadcast($consult));
        Notify::send($consult->user_id, 'cal', 't-amber', "أُعيدت استشارتك ({$consult->ref}) لاختيار موعد جديد — اختر الموعد المناسب من «استشاراتي».");

        return back();
    }

    // تحويل قرارات الاستشارة إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->tasks_created, 409);

        $count = DecisionTasks::create($consult, $this->ai, $request->user());
        abort_if($count === 0, 422);

        return back()->with('flash', 'تم تحويل '.$count.' قرار إلى مهام');
    }

    // غرفة الجلسة المرئية (يطابق openVideoRoom) — ?ref=CN-… للاستشارة، وتبقى kind=req لطلبات الاجتماعات
    public function room(Request $request): Response
    {
        $card = null;
        if ($ref = $request->query('ref')) {
            $consult = Consult::with('user')->where('ref', $ref)->first();
            if ($consult) {
                $this->guardConsult($request, $consult);
                $card = $consult->toCard();
            }
        }

        return Inertia::render($this->prefix($request).'/videoroom', [
            'consult' => $card,
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
