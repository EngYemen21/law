<?php

namespace App\Services\Ai;

use App\Events\CaseMessageBroadcast;
use App\Events\CaseStatusBroadcast;
use App\Events\ConsultStatusBroadcast;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Live;
use App\Support\Notify;

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
            // وحده أصلاً (`ticket.summary` · `case.classify` · `meeting.*`)، أو
            // يعود للمتصفّح بلا تخزين (`najiz.statement` · `assistant.draft`).
            // و`case.pleading` يُطلَق في `releaseCasePleading` — انظر أدناه.
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
            self::approveConsultSummary($consult, $reviewer);
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
        if ($consult->summaryApproved() || blank($consult->summary)) {
            return false;
        }

        $consult->update([
            'summary_approved_at' => now(),
            'summary_approved_by' => $reviewer->id,
        ]);

        $consult->logAudit($reviewer->name, 'اعتماد ملخّص الاستشارة', 'مبدئيّ', 'معتمد');

        Notify::send(
            $consult->user_id,
            'doc',
            't-green',
            "اعتُمد ملخّص استشارتك ({$consult->ref}) وهو متاح الآن في «استشاراتي»."
        );

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

        $exec->update(['ai_approved_at' => now(), 'ai_approved_by' => $reviewer->id]);

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

        // `reorder` قبل الترتيب: العلاقة مرتّبة تصاعدياً في تعريفها، و`latest` تُلحق
        // ترتيباً ثانياً لا تستبدل الأوّل — فتعود أقدم رسالة لا أحدثها.
        $draft = $case?->messages()
            ->where('role', 'مسودة اللائحة')
            ->reorder('id', 'desc')
            ->first();

        if ($draft === null || $draft->withheld_at === null) {
            return;
        }

        $draft->update(['withheld_at' => null]);

        // البثّ **هنا** لا عند الإنشاء: `CaseMessage::booted` يتخطّى المحجوبة لأن
        // قناة `case.{id}` يُخوَّل عليها العميل. فتصله لحظة الاعتماد لا لحظة الكتابة.
        Live::push(new CaseMessageBroadcast($draft->fresh()));

        Notify::send(
            $case->user_id,
            'doc',
            't-green',
            "اعتمد المستشار مسودّة لائحة الدعوى في قضيتك ({$case->number}) وهي متاحة الآن في ملفّ القضية."
        );

        Live::push(new CaseStatusBroadcast($case->fresh()));
    }
}
