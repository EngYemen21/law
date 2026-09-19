<?php

namespace App\Services\Ai;

use App\Domain\Journey\Transitions\Consult\ApproveConsultSummary;
use App\Domain\Journey\Workflow;
use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\CaseStatusBroadcast;
use App\Events\ConsultStatusBroadcast;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Audit;
use App\Support\CasePleading;
use App\Support\ConsultSessionOutcome;
use App\Support\LegalCatalogue;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * أثر قرار المراجعة في الملفّ — لا في `ai_runs` وحده.
 *
 * `AiReviewController::decide` كان يسجّل القرار ويقف: يقبل المراجعُ مخرجاً فلا يتغيّر
 * شيء في الملفّ. وهذا مقبولٌ ما دام المخرج معروضاً أصلاً، لكنه يصير عطلاً حين
 * يُحجب المخرج عن العميل بانتظار اعتماد — فيبقى محجوباً وإن اعتُمد.
 *
 * ولذلك تُفصل الآثار هنا لا تُحشر في المتحكّم: كل مهمّة يُراد لقبولها أثرٌ في ملفّها
 * تُضاف بفرعٍ واحد، ويبقى المتحكّم رقيقاً.
 */
class AiReviewOutcome
{
    /**
     * القرار كما وقع فعلاً، وحجم التحرير مقيساً.
     *
     * **العلّة:** «تعديل واعتماد» كان إعلانَ نيّةٍ لا فعلاً — يختاره المراجع من قائمة
     * ويُسجَّل في `review_action`، والمنشور نصُّ النموذج حرفياً. فكان `humanEditRate`
     * استفتاءً: يقيس ما قاله المراجع عن نفسه لا ما فعله بالنصّ.
     *
     * فيُرفَع `Accept` إلى `Edit` حين يختلف المنشور عن مخرج النموذج — لأن التحرير
     * وقع سواء أعلنه صاحبه أم لا. **ولا يُخفَض العكس:** من أعلن أنه عدّل قد يكون
     * عدّل خارج هذا الحقل، ونفيُ فعلٍ أعلنه إنسانٌ أثقل من إثبات فعلٍ قِيس. والقياس
     * يُحفظ في الحالين في `review_edit_distance`، فيُعاد الحساب متى شئنا.
     *
     * @return array{0:AiReviewAction, 1:?int} القرار الفعليّ وحجم التحرير (`null` = لم يُقَس)
     */
    public static function effectiveAction(AiRun $run, AiReviewAction $action): array
    {
        if ($action !== AiReviewAction::Accept && $action !== AiReviewAction::Edit) {
            return [$action, null];
        }

        $consult = $run->task_type === 'consult.summary' ? self::consultOf($run) : null;

        // لا مخرج نصّيّ محفوظ ⇒ **لا قياس** — و`null` تعني «لم يُقَس» لا «لم يُغيَّر شيء»
        if ($consult === null || blank($consult->summary)) {
            return [$action, null];
        }

        $distance = self::editSize((string) ($consult->summary_ai_original ?? $consult->summary), (string) $consult->summary);

        return [$distance > 0 && $action === AiReviewAction::Accept ? AiReviewAction::Edit : $action, $distance];
    }

    /**
     * حجم التحرير: عدد الحروف خارج البادئة واللاحقة المشتركتين.
     *
     * ليست مسافة ليفنشتاين — تلك في PHP بايتيّة ومحدودة بـ255 حرفاً، فلا تصلح لنصّ
     * عربيّ من آلاف الحروف. وهذه **حدٌّ أدنى** مضبوط: تساوي المسافة تماماً حين يكون
     * التحرير متّصلاً، وتقلّ عنها حين يتفرّق. وما يُبنى عليها قرارٌ ثنائيّ — «حُرّر
     * أم لا» — وهو صحيحٌ بأيّ من الحالتين.
     */
    private static function editSize(string $before, string $after): int
    {
        if ($before === $after) {
            return 0;
        }

        $a = preg_split('//u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $after, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $la = count($a);
        $lb = count($b);

        $prefix = 0;
        while ($prefix < $la && $prefix < $lb && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < $la - $prefix && $suffix < $lb - $prefix
            && $a[$la - 1 - $suffix] === $b[$lb - 1 - $suffix]) {
            $suffix++;
        }

        return max($la, $lb) - $prefix - $suffix;
    }

    /** الاستشارة التي يخصّها القيد — بالعلاقة إن حُمّلت، وإلّا بالمرجع. */
    private static function consultOf(AiRun $run): ?Consult
    {
        return $run->entity instanceof Consult
            ? $run->entity
            : Consult::where('ref', $run->entity_ref)->first();
    }

    /**
     * تسجيل قرارٍ بشريّ وقع في **شاشة الملفّ** لا في صندوق المراجعة.
     *
     * `apply()` أعلاه تسير في اتّجاهٍ واحد: قرارُ الصندوق يُحدث أثراً في الملفّ.
     * والاتّجاه المعاكس كان مفقوداً: محامٍ يعتمد ملخّص التذكرة من شاشتها فيصل
     * العميلَ ويُشعَر به — و`ai_runs.review_action` يبقى `NULL` والحالة
     * `needs_review` **إلى الأبد**.
     *
     * وأثره ثلاثيّ: الصندوق يعرض عنصراً منتهياً بوصفه معلَّقاً، و`humanEditRate`
     * لا يعدّه، وتقرير الحوكمة يقول «لم يُراجَع» لمخرجٍ راجعه إنسانٌ وأطلقه.
     * قِيس على قاعدة التطوير: **ملخّصان معتمدان، وكلاهما بلا قرارٍ مسجَّل — ٢ من ٢**
     * (منها `SB-2026-3715` التي اعتُمدت ١:١٣ وبقي قيدها معلَّقاً).
     *
     * ولا تُنادى `apply()` من هنا: الأثر في الملفّ **قد وقع بالفعل** — وهو ما دعا
     * إلى التسجيل أصلاً. وتكرارُه يُشعر العميل مرّتين.
     *
     * @param  string  $taskType  الاسم **كما يُخزَّن** في `ai_runs` — انظر `AiRunLogger::STORED_TASK_TYPE`
     * @param  bool  $edited  حرّر المراجعُ النصّ قبل اعتماده؟ فيُسجَّل «تعديل» لا «قبول»
     */
    public static function recordFileApproval(
        string $taskType,
        ?string $entityRef,
        User $reviewer,
        bool $edited = false,
    ): ?AiRun {
        if (blank($entityRef)) {
            return null;
        }

        $run = AiRun::where('task_type', $taskType)
            ->where('entity_ref', $entityRef)
            ->whereNull('review_action')
            ->reorder('id', 'desc')
            ->first();

        if ($run === null) {
            return null;
        }

        $run->update([
            'review_action' => $edited ? AiReviewAction::Edit->value : AiReviewAction::Accept->value,
            'review_note' => 'اعتماد من شاشة الملفّ',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'status' => AiRun::STATUS_COMPLETED,
        ]);

        return $run->fresh();
    }

    /** يُطبّق أثر القرار إن كان له أثر. يُنادى **بعد** تسجيل القرار. */
    public static function apply(AiRun $run, AiReviewAction $action, User $reviewer): void
    {
        // القبول والتعديل كلاهما اعتمادٌ بشريّ: الأوّل «صحيح كما هو» والثاني
        // «صحيح بعد تحريري». والرفض وإعادة التشغيل والتصعيد لا تُطلق مخرجاً.
        if ($action !== AiReviewAction::Accept && $action !== AiReviewAction::Edit) {
            return;
        }

        // **المفاتيح `task_type` كما يُكتب فعلاً** لا معرّف التعليمة: `execution`
        // اسمٌ قصير وتعليمته `execution.analyze` تُمرَّر في `policyTask`. والمطابقة
        // على المعرّف تفشل صامتةً — `match` يقع على `default` بلا خطأ.
        match ($run->task_type) {
            'consult.summary' => self::releaseConsultSummary($run, $reviewer),
            'execution' => self::releaseExecutionAnalysis($run, $reviewer),
            // بقيّة المهامّ العالية بلا أثرٍ هنا **عمداً**: مخرجها معروضٌ للمكتب
            // وحده أصلاً (`ticket.summary` · `meeting.*`)، أو
            // يعود للمتصفّح بلا تخزين (`najiz.statement` · `assistant.draft`).
            // و`case.pleading` يُطلَق في `releaseCasePleading` — انظر أدناه.
            // `case.classify` كان هنا «معروضاً للمكتب وحده» — وهو يعيد كتابة رسالةٍ يراها العميل.
            // صار مقترحاً محفوظاً يُطبَّق عند القبول.
            'case.classify' => self::releaseCaseClassification($run, $reviewer),
            'case.pleading' => self::releaseCasePleading($run, $reviewer),
            default => null,
        };
    }

    /**
     * إطلاق ملخّص الاستشارة إلى العميل بعد اعتماد محامٍ.
     *
     * الإشعار **هنا** لا عند التوليد: كان يُرسل فور كتابة النموذج للملخّص («ملخص
     * الاستشارة متاح الآن») بينما لم يمرّ به إنسان. فصار الإشعار يتبع الاعتماد لا
     * الإنتاج، ويصل العميل مرّةً واحدة — `summary_approved_at` يحرس التكرار.
     */
    private static function releaseConsultSummary(AiRun $run, User $reviewer): void
    {
        $consult = self::consultOf($run);

        if ($consult !== null) {
            // قبول المحامي في الصندوق = المرحلة الأولى؛ قبول الإدارة = الاعتماد النهائيّ والنشر
            $reviewer->isAdmin()
                ? self::approveConsultSummary($consult, $reviewer)
                : self::lawyerApproveConsultSummary($consult, $reviewer);
        }
    }

    /**
     * **الكاتب الوحيد لـ`summary_approved_at` — أيّاً كان مصدر النصّ.**
     *
     * كانت حالةُ الاعتماد تعيش على `AiRun` بينما الوثيقة تعيش على `Consult`: البوّابة
     * لا تُفتح إلّا بقيدٍ من نوع `consult.summary`، وذلك القيد لا يُنشأ إلّا بنداءٍ
     * ناجح للنموذج. وللنصّ **ثلاثة** كتّاب: `FinalizeConsultJob` (يُنشئ قيداً)،
     * و`saveSummary` (بيد المحامي)، و`ConsultSummary::pull` (من Zoom) — والأخيران بلا
     * قيد. فجلسةٌ تنتهي بلا تدوين ⇒ لا ملخّص ولا قيد ⇒ يكتب المحامي التقرير بيده
     * ⇒ **محجوبٌ عن العميل للأبد**، بينما تَعِده الواجهة بأنه «جاهز للاعتماد».
     *
     * فصار الاعتماد فعلاً على **الملفّ**، ويسجّله القيد إن وُجد لا العكس. تناديها
     * بوّابة الصندوق وشاشة الملفّ معاً — فلا مسارَ اعتمادٍ ثانٍ خارج الحوكمة.
     *
     * @return bool هل وقع الاعتماد الآن؟ (`false` إن كان معتمَداً أصلاً أو بلا نصّ)
     */
    public static function approveConsultSummary(Consult $consult, User $reviewer): bool
    {
        /*
         * **كلّه أو لا شيء.** اعتماد الملخّص ونشر نتيجة التذكرة ونقل حالتها في معاملةٍ واحدة:
         * كان الاعتماد يُحفظ ويُشعَر العميل، ثمّ يرفض المحرّك نقل التذكرة — فتبقى «بانتظار
         * ملخّص الجلسة» والملخّص «معتمد» فتُرفض كلّ إعادة محاولة، ولا زرّ يُخرجها.
         *
         * القفل على الاستشارة يجعل الاعتمادين المتزامنين متتابعَين: الثاني يقرأ الاعتماد
         * الأوّل فيعود بـ`false` بدل أن يكرّر الرسالتين والإشعار.
         */
        return DB::transaction(function () use ($consult, $reviewer) {
            $consult = Consult::whereKey($consult->getKey())->lockForUpdate()->firstOrFail();

            // والجلسة المنعقدة وحدها لها ملخّص — الصندوق وشاشة الملفّ يلتزمان الحارس نفسه (ع٢٢)
            if ($consult->summaryApproved() || blank($consult->summary) || $consult->session !== 'منتهية') {
                return false;
            }

            // الكتابة وقيد التدقيق في الانتقال — انظر `ApproveConsultSummary`
            Workflow::run(new ApproveConsultSummary, $consult, $reviewer);

            // ما يخرج من النظام بعد الحفظ فقط — ويسقط إن ألغى رفضُ المحرّك المعاملة
            DB::afterCommit(function () use ($consult) {
                Notify::send(
                    $consult->user_id,
                    'doc',
                    't-green',
                    "اعتُمد ملخّص استشارتك ({$consult->ref}) وهو متاح الآن في «استشاراتي»."
                );

                Live::push(new ConsultStatusBroadcast($consult->fresh()));
            });

            // **واعتماد الإدارة لملخّص الجلسة هو نفسه اعتماد نتيجة التذكرة** — بطاقةٌ واحدة بالنصّ نفسه
            ConsultSessionOutcome::publish($consult->fresh(), $reviewer);

            return true;
        });
    }

    /**
     * **المرحلة الأولى: المحامي يعتمد ملخّص الجلسة ويرفعه للإدارة** (قرار المالك 2026-09-14).
     *
     * لا يصل العميلَ شيء، ويُقفل التحرير على المحامي؛ والإدارة تُنبَّه لتعتمده نهائيّاً.
     *
     * @return bool هل وقع الاعتماد الآن؟
     */
    public static function lawyerApproveConsultSummary(Consult $consult, User $lawyer): bool
    {
        if ($consult->summaryApproved() || $consult->summary_lawyer_approved_at !== null
            || blank($consult->summary) || $consult->session !== 'منتهية') {
            return false;
        }

        $consult->update([
            'summary_lawyer_approved_at' => now(),
            'summary_lawyer_approved_by' => $lawyer->id,
        ]);
        $consult->logAudit($lawyer->name, 'اعتماد ملخّص الاستشارة', 'مبدئيّ', 'اعتمده المستشار — بانتظار الإدارة');
        $consult->save();

        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send($adminId, 'doc', 't-amber', "اعتمد المستشار {$lawyer->name} ملخّص جلسة الاستشارة ({$consult->ref}) — بانتظار اعتمادكم النهائيّ قبل إرساله للعميل.");
        }

        Live::push(new ConsultStatusBroadcast($consult->fresh()));

        return true;
    }

    /**
     * إطلاق تحليل طلب التنفيذ إلى صاحبه بعد اعتماد محامٍ.
     *
     * `ExecService::applyAnalysis` يسجّل القيد «يتطلّب مراجعة» بتعليقٍ صريح («لا
     * اعتماد آليّ لمخرجٍ يغيّر مسار ملفّ قانونيّ») ثم يكتب المخرج في الملفّ، وبطاقة
     * العميل تمرّره في الطلب نفسه — فالوسم يقول «انتظروا» والمخرج قد وصل.
     */
    private static function releaseExecutionAnalysis(AiRun $run, User $reviewer): void
    {
        $exec = $run->entity instanceof Execution
            ? $run->entity
            : Execution::where('number', $run->entity_ref)->first();

        if ($exec === null || $exec->aiApproved() || blank($exec->ai_summary)) {
            return;
        }

        // **الاحتياطيّ لا يُنشر للعميل وسمُه «دراسة معتمدة».** القالب الاحتياطيّ لا يفحص مستنداً
        // واحداً (`ExecService::applyAnalysis`)، والبطاقة ترفض عرضه للعميل أصلاً — فاعتمادُه
        // إقرارٌ بمراجعةٍ بشريّة للملفّ لا نشرٌ لنصٍّ آليّ. نظير الحارس في `releaseCasePleading`.
        if ($run->source === AiSource::Fallback) {
            return;
        }

        $exec->update(['ai_approved_at' => now(), 'ai_approved_by' => $reviewer->id]);

        // الدراسة تُكتب ملاحظةً داخليّة عند إنتاجها (`ExecService::applyAnalysis`)، فالاعتماد هو
        // الذي ينشرها في المحادثة — كما يُطلق اعتمادُ اللائحة مسودّتَها للعميل.
        $missing = is_array($exec->ai_missing) ? array_values($exec->ai_missing) : [];
        $exec->messages()->create([
            'who' => 'ai',
            'name' => 'المساعد القانوني',
            'role' => 'دراسة معتمدة',
            'body' => '<p>'.e((string) $exec->ai_summary).'</p>'
                .($missing ? '<p><b>نواقص مطلوبة:</b> '.e(implode(' · ', $missing)).'</p>' : ''),
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        Notify::send(
            $exec->user_id,
            'doc',
            't-green',
            "اعتُمدت دراسة طلب التنفيذ ({$exec->number}) وهي متاحة الآن في «طلبات التنفيذ»."
        );
    }

    /**
     * إطلاق مسودّة اللائحة إلى العميل بعد اعتماد محامٍ.
     *
     * المسودّة تُنشَر **رسالةً** في محادثة القضية، والعروض الثلاثة (عميل/محامٍ/موظّف)
     * كانت تستعمل الترشيح نفسه `who != 'note'` — فوثيقةٌ قضائيّة رسميّة بلا سندٍ
     * متحقَّق تصل العميل قبل أن يقرأها محامٍ. و`withheld_at` هو الفاصل.
     *
     * ويُطلَق **أحدث** مسودّة: إعادة التشغيل تُنشئ رسالةً جديدة، والقرار يقع على
     * ما يراه المراجع في المعاينة — وهي أحدثها (`AiReviewPreview::pleading`).
     */
    private static function releaseCasePleading(AiRun $run, User $reviewer): void
    {
        $case = $run->entity instanceof LegalCase
            ? $run->entity
            : LegalCase::where('number', $run->entity_ref)->first();

        // **لا يُطلَق نصٌّ احتياطيّ لائحةً.** حين يتعذّر المزوّد تُكتب «تعذّر توليد المسودّة…»
        // بدور المسودّة نفسه، وقبولُه هنا كان يرسله للعميل بوصفه لائحة الدعوى.
        if ($run->source === AiSource::Fallback) {
            return;
        }

        // **اعتمادٌ واحد** (قرار المالك 2026-09-11): الإطلاقُ ورفعُ الدعوى في مصدرٍ يناديه
        // زرُّ المحامي أيضاً. كان القبول هنا يُطلق النصّ ويترك القضيّة «قيد التحضير»،
        // والزرُّ ينقلها إلى «منظورة» ويترك النصّ محجوباً.
        // ولا نصٌّ يحمل تنبيهاً داخلياً (أسانيد غير مُتحقَّقة…) — يُعالَج في محرّر اللائحة أوّلاً
        if ($case !== null && ! CasePleading::hasWarnings($case)) {
            CasePleading::approve($case, $reviewer);
        }
    }

    /**
     * تطبيق تصنيف القضيّة المقترح بعد اعتماده.
     *
     * النوع والقسم يوجّهان الملفّ كلّه، ورسالة «التحليل» يراها العميل — فلا يُكتب شيءٌ منها
     * قبل أن يقرأ المراجع المقترح بجوار الحاليّ (`AiReviewPreview::caseClassification`).
     */
    private static function releaseCaseClassification(AiRun $run, User $reviewer): void
    {
        $case = $run->entity instanceof LegalCase
            ? $run->entity
            : LegalCase::where('number', $run->entity_ref)->first();

        $proposal = $case?->ai_classification;
        if ($case === null || ! is_array($proposal) || blank($proposal['type'] ?? null) || blank($proposal['department'] ?? null)) {
            return;
        }

        $before = ['النوع' => $case->type, 'القسم' => $case->department];
        // الاسم المعتمد في الكتالوج إن طابق المقترح قسماً — فلا تدخل القضيّةَ صياغةٌ ثانية لقسمٍ واحد
        $department = LegalCatalogue::resolveDepartment($proposal['department']);
        $case->update([
            'type' => $proposal['type'],
            'department' => $department !== null ? $department->name : $proposal['department'],
            'ai_classification' => null,
        ]);

        // تُعاد كتابة رسالة «التحليل» القائمة لا تُضاف ثانية — وإلا رأى العميل تحليلين متناقضين
        $message = $case->messages()->where('role', 'تحليل')->reorder('id', 'desc')->first();
        if ($message !== null && $case->ticket !== null) {
            $message->update(['body' => ClassifyConvertedCaseJob::analysisBody($case->ticket, $proposal, refined: true)]);
        }

        Audit::log(
            action: 'اعتماد تصنيف القضية',
            description: "اعتمد {$reviewer->name} التصنيف المقترح للقضية {$case->number}: {$proposal['type']} — {$proposal['department']}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: $before,
            afterState: ['النوع' => $proposal['type'], 'القسم' => $proposal['department']],
        );

        Live::push(new CaseStatusBroadcast($case->fresh()));
    }
}
