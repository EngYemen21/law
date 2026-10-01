<?php

namespace App\Support;

use App\Domain\Journey\Enums\ExecutionDocumentStatus;
use App\Domain\Journey\Enums\ExecutionOfferStatus;
use App\Domain\Journey\Transitions\Execution\ApplyExecutionAnalysis;
use App\Domain\Journey\Transitions\Execution\ApplyExecutionMeasures;
use App\Domain\Journey\Transitions\Execution\ApproveExecutionFee;
use App\Domain\Journey\Transitions\Execution\AssignExecutionLawyer;
use App\Domain\Journey\Transitions\Execution\CloseExecution;
use App\Domain\Journey\Transitions\Execution\FileExecutionNajiz;
use App\Domain\Journey\Transitions\Execution\NotifyExecutionDebtor;
use App\Domain\Journey\Transitions\Execution\RecordExecutionCollection;
use App\Domain\Journey\Transitions\Execution\ReferExecution;
use App\Domain\Journey\Transitions\Execution\RegisterExecutionNajiz;
use App\Domain\Journey\Transitions\Execution\RejectExecutionOffer;
use App\Domain\Journey\Transitions\Execution\SetExecutionClaimAmount;
use App\Domain\Journey\Transitions\Execution\SetExecutionFee;
use App\Domain\Journey\Transitions\Execution\StudyExecution;
use App\Domain\Journey\Workflow;
use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\ExecStatusBroadcast;
use App\Jobs\AnalyzeExecutionJob;
use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\User;
use App\Services\Ai\AiRunLogger;
use App\Services\MailService;
use App\Services\Payments\PaymentGateways;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * تنسيق تدفّق طلب التنفيذ التجاريّ (10 مراحل) — نظير دوالّ execSubmit/execAI/…/execClose في التصميم.
 * كلّ انتقال: يحرس المرحلة، يحدّث الأعمدة + الحالة/النغمة، يضيف رسالة، يُشعر العميل، ويبثّ لحظيّاً.
 */
class ExecService
{
    // ── التحليل (ملفّاتٌ قائمة في المرحلة 1 — الطلب الجديد يُفتح تذكرةً في قسم التنفيذ) ──

    /**
     * يطبّق نتيجة الدراسة الذكيّة (من LegalAiService::analyzeExecution): يحدّث الحقول، يقدّم المرحلة
     * (2 عند الاكتمال / 1 عند وجود نواقص)، يضيف رسالة، يُشعر العميل، ويبثّ. يستدعيها AnalyzeExecutionJob.
     *
     * المفاتيح الستّة الجديدة (v2) **اختياريّة**: منادٍ قديم يمرّر الثلاثة وحدها فتُخزَّن
     * القيم الفارغة المعلنة — لا تخمين ولا انكسار.
     *
     * @param  array{summary:string,missing:array<int,string>,procedures:array<int,string>,readiness?:string,difficulty?:string,expected_procedures_count?:int,duration_estimate?:string,recovery_indicators?:array<int,string>,risks?:array<int,string>}  $result
     */
    public static function applyAnalysis(Execution $exec, array $result): void
    {
        $missing = array_values($result['missing']);
        $procedures = array_values($result['procedures']);
        $summary = (string) $result['summary'];
        $complete = count($missing) === 0;

        // مصدر النتيجة يحسم كل ما يلي. الافتراض `ai_success` يحفظ التوافق مع أي منادٍ
        // قديم لا يمرّر المفتاح، لكن `analyzeExecution` صار يمرّره في المسارين دائماً.
        $source = AiSource::tryFrom((string) ($result['source'] ?? AiSource::AiSuccess->value)) ?? AiSource::AiSuccess;
        $isRealAnalysis = $source->isRealAnalysis();

        $advance = $isRealAnalysis && $complete;
        $movable = $exec->effectiveStage() < 2;

        Workflow::run(new ApplyExecutionAnalysis, $exec, null, [
            'ai_done' => $isRealAnalysis,
            'ai_source' => $source->value,
            'ai_summary' => $summary,
            'ai_missing' => $missing,
            'ai_procedures' => $procedures,
            'ai_study' => [
                'readiness' => (string) ($result['readiness'] ?? ''),
                'difficulty' => (string) ($result['difficulty'] ?? ''),
                'expected_procedures_count' => (int) ($result['expected_procedures_count'] ?? 0),
                'duration_estimate' => (string) ($result['duration_estimate'] ?? ''),
                'recovery_indicators' => array_values((array) ($result['recovery_indicators'] ?? [])),
                'risks' => array_values((array) ($result['risks'] ?? [])),
            ],
            'advance' => $advance,
            'last_action' => match (true) {
                ! $isRealAnalysis => 'الطلب قيد المراجعة',
                $complete => 'اكتمل التحليل الذكيّ — بانتظار الدراسة',
                default => 'التحليل الذكيّ: نواقص مطلوبة',
            },
        ]);

        // القيد يُكتب قبل الآثار الجانبيّة (رسالة/إشعار/بثّ) لا بعدها: هو مفتاح منع
        // التكرار الذي يفحصه AnalyzeExecutionJob، فكتابته بعدها تترك نافذة تعطّل
        // تتكرّر فيها الرسالة والإشعارات عند إعادة تشغيل الطابور.
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        // الحالة من بوّابة السياسة لا من شرطٍ ضمنيّ: تحليل التنفيذ عالي الحساسيّة،
        // فيُسجَّل «يتطلّب مراجعة» ولو نجح وبلغت ثقته حدّها — لا اعتماد آليّ لمخرج
        // يغيّر مسار ملفّ قانونيّ.
        AiRunLogger::log('execution', $source, $meta, $exec, (string) $exec->number, policyTask: 'execution.analyze');

        // **لا يصل العميل تحليلٌ لم يعتمده محامٍ.** البطاقة تحجب `aiSummary` حتى الاعتماد، وكانت
        // الرسالة نفسها تُكتب ظاهرةً للعميل (`who=ai`) فيقرأ في المحادثة ما حجبته البطاقة — والقيد
        // مسجَّلٌ «يتطلّب مراجعة». فتبقى ملاحظةً داخليّة، ويُطلقها الاعتماد في
        // `AiReviewOutcome::releaseExecutionAnalysis` كما تُطلق مسودّة اللائحة.
        // والتعذّر ملاحظة داخليّة أيضاً — سياسة المكتب: لا تُعرَض الأعطال للعميل.
        $exec->messages()->create($isRealAnalysis ? [
            'who' => 'note',
            'name' => 'المساعد القانوني',
            'role' => 'تحليل — بانتظار اعتماد المحامي',
            'body' => '<p>'.e($summary).'</p>'
                .($missing ? '<p><b>نواقص مطلوبة:</b> '.e(implode(' · ', $missing)).'</p>' : ''),
            'time_label' => self::clock(),
        ] : [
            'who' => 'note',
            'name' => 'النظام',
            'role' => 'تعذّر التحليل الذكيّ',
            // لا تقييم مُصطنَع: المحاولة تُعاد بالطابور، وإلى أن تنجح يبقى الفحص يدويّاً
            'body' => '<p><b>تعذّرت الدراسة الذكيّة لهذا الطلب — لم يُفحص أي مستند.</b> '
                .'أُعيدت جدولة المحاولة تلقائياً، ويلزم فحص المستندات يدوياً قبل الإحالة إن تأخّرت.</p>'
                .($missing ? '<p><b>نواقص في بيانات الطلب:</b> '.e(implode(' · ', $missing)).'</p>' : ''),
            'time_label' => self::clock(),
        ]);

        if ($isRealAnalysis) {
            // الإشعار محايد: المخرج لم يُعتمد بعد، فلا يُقال للعميل «تمّ تحليل طلبك» ثم لا يجد شيئاً
            // — ولا يُرسَل أصلاً لملفٍّ تجاوز الاستقبال: عميلٌ أُشعر بأنّ عرض الأتعاب قادم
            // لا يُقال له بعدها «طلبك قيد الدراسة»، فتتراجع حالته في عينه بلا سبب.
            if ($movable) {
                self::notify($exec, 'exec', 't-blue', "طلب التنفيذ {$exec->number} قيد الدراسة.");
            }
            self::alertStaff($exec, "طلب التنفيذ {$exec->number}: جهزت الدراسة الذكيّة — تنتظر اعتماد محامٍ قبل إطلاقها للعميل.");
        } else {
            // العميل: إشعار محايد بلا ذكر لأي عطل. المكتب: تنبيه صريح ليتحرّك أحد.
            if ($movable) {
                self::notify($exec, 'exec', 't-blue', "طلب التنفيذ {$exec->number} قيد المراجعة.");
            }
            self::alertStaff($exec, "طلب التنفيذ {$exec->number}: تعذّر التحليل الذكيّ — يلزم فحص المستندات يدوياً قبل الإحالة.");
        }
        Live::push(new ExecStatusBroadcast($exec));

        if ($advance) {
            self::alertUnassigned($exec);
        }
    }

    // ── الإدارة ──

    public static function refer(Execution $exec, ?User $actor = null): void
    {
        self::guard($exec, [0, 1], 'لا يمكن إحالة هذا الطلب في مرحلته الحالية — الطلب محالٌ للدراسة بالفعل أو تجاوز هذه المرحلة.');
        self::guardNotRejected($exec, 'هذا الطلب مرفوض بعد الدراسة — لا يُعاد إحالته لقسم التنفيذ.');
        Workflow::run(new ReferExecution, $exec, $actor);
        self::officeMsg($exec, $actor, 'إحالة', 'أُحيل الطلب إلى قسم التنفيذ للدراسة.');
        self::alertUnassigned($exec);
        Live::push(new ExecStatusBroadcast($exec));
    }

    /**
     * **الملفّ غير المسنَد عند بلوغ «قيد الدراسة» ملكُ الإدارة تُوجّهه** (قرار المالك).
     *
     * الالتقاط الأوّل يبقى كما هو — أوّل محامٍ يضغط «قبول» يملك الملفّ. لكنّ ملفّاً لم
     * يلتقطه أحد كان يظلّ معلّقاً في قائمة المحامين بلا صاحبٍ ولا من يُنبَّه إليه، فلا
     * أحدَ مسؤولٌ عن تأخّره. والمرحلة 2 أوّل لحظةٍ يصير فيها الملفّ قابلاً للإسناد
     * (`ExecFlowController::lawyer` يعرض غير المسنَد من `stage >= 2`)، فعندها يُرفع.
     *
     * مصدرٌ واحد لطريقَي بلوغها: الإحالة الإداريّة (`refer`) واكتمالُ الدراسة الذكيّة
     * (`applyAnalysis`) — فلا تتباعد صيغةُ التنبيه ولا شرطُه.
     */
    private static function alertUnassigned(Execution $exec): void
    {
        if ($exec->assigned_lawyer_id !== null) {
            return;
        }

        self::notifyAdmins($exec, 't-amber', "طلب التنفيذ {$exec->number} بلغ «قيد الدراسة» وبلا محامٍ مسنَد — بانتظار إسناد الإدارة.");
    }

    /**
     * **إسناد محامٍ لملفّ التنفيذ** (قرار المالك): الإدارة تُوجّه، والموظّف يُوجّه بصلاحيّة
     * «إجراءات المحكمة والجلسات» — والحرّاس في `ExecFlowController::act` لا هنا، كبقيّة الإجراءات.
     *
     * إعادة الإسناد **للإدارة وحدها** (يفرضها الحارس): تحويل ملفٍّ من محامٍ إلى آخر ينزع
     * ملفّاً من يد من يعمل عليه ويكسر عزلَه عنه (`assigned_lawyer_id` هو ما يحجب الملفّ عن
     * زملائه)، وهو قرار توزيعٍ إداريّ لا خطوةُ استقبال — بينما إسنادُ ملفٍّ بلا صاحب توجيهٌ
     * لا نزع. والملفّ المنتهي لا يُسنَد أصلاً: إسنادٌ بعد الإقفال يُحمّل محامياً مسؤوليّة
     * ملفّاً لا إجراء فيه.
     */
    public static function assignLawyer(Execution $exec, User $lawyer, ?User $actor = null): void
    {
        abort_if($exec->isClosed(), 422, 'ملفّ التنفيذ منتهٍ — لا يُسنَد بعد إغلاقه.');
        self::guardNotRejected($exec, 'هذا الطلب مرفوض بعد الدراسة — لا يُسنَد إليه محامٍ.');

        $assign = new AssignExecutionLawyer;
        Workflow::run($assign, $exec, $actor, [
            'lawyer_id' => $lawyer->id,
            'lawyer_name' => $lawyer->name,
        ]);

        /*
         * **نصُّ الرسالة يقرؤه العميل — فالمحاميان فيه باسمهما للعميل لا باسمهما الكامل.**
         *
         * كانت الرسالة تحمل الاسمين الكاملين للقديم والجديد فتصل محادثةَ العميل وبثَّه اللحظيّ، بينما خانة
         * المحامي في شاشته نفسها تعرض «محمد. ب». فكلاهما بالمصدر الواحد (`LawyerName`) — والسابق **يُذكر**
         * (قرار المالك 2026-09-26)، ويُحفظ كاملاً في سطر الرحلة (`previous_lawyer_id/name`) للطاقم.
         */
        $assignee = LawyerName::assignedTo($lawyer);
        $reassigned = $assign->previousId() !== null || $assign->previousName() !== null;
        $previous = $reassigned
            ? LawyerName::forClient($assign->previousId() !== null ? User::find($assign->previousId()) : null, $assign->previousName(), LawyerName::SPECIALIST)
            : null;

        self::officeMsg($exec, $actor, 'إسناد', $previous !== null
            ? 'أُعيد إسناد ملفّ التنفيذ من '.$previous.' إلى '.$assignee.'.'
            : 'أُسند ملفّ التنفيذ إلى '.$assignee.'.');

        // من أُسند إليه الملفّ يُبلَّغ بالإشعار وبالبريد معاً — نظير `ExecutionCreation::fromCase`
        Notify::send($lawyer->id, 'exec', 't-blue', "أُسند إليك ملفّ التنفيذ {$exec->number} — {$exec->subject}.");
        self::mailAssignedLawyer($exec->fresh());

        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    public static function approveFee(Execution $exec, ?int $adjustedFee = null, ?User $actor = null, ?int $lawyerPct = null): void
    {
        self::guard($exec, self::feeStages($exec), 'لا توجد أتعاب بانتظار الاعتماد.');
        self::guardNotRejected($exec);
        self::guardAssigned($exec);

        Workflow::run(new ApproveExecutionFee, $exec, $actor, [
            'adjusted_fee' => $adjustedFee,
            'lawyer_pct' => $lawyerPct,
        ]);

        self::officeMsg($exec, $actor, 'اعتماد', 'اعتمدت الإدارة أتعاب التنفيذ وأُرسل العرض للعميل.');
        self::notify($exec, 'card', 't-blue', "عرض خدمة التنفيذ لطلبك {$exec->number} جاهز — بانتظار قبولك.");
        self::mail($exec, 'feeApproved');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /**
     * تسعير الإدارة المباشر (execfeeset): الإدارة تحدّد الأتعاب وتعتمدها وترسل العرض في خطوة واحدة
     * (المراحل 2‑4 قبل الاعتماد، أو 5 لإعادة تسعير عرض رفضه/استفسر عنه العميل). الأتعاب النهائيّة
     * تُحسب في الواجهة (ثابت/نسبة) وتُمرَّر رقماً.
     */
    public static function setFee(Execution $exec, int $fee, string $duration, string $feeMode = 'fixed', ?float $feePct = null, ?User $actor = null, ?int $lawyerPct = null): void
    {
        self::guard($exec, self::feeStages($exec), 'لا يمكن تسعير الطلب في مرحلته الحالية.');
        self::guardNotRejected($exec);
        self::guardAssigned($exec);

        Workflow::run(new SetExecutionFee, $exec, $actor, [
            'fee' => $fee,
            'duration' => $duration,
            'fee_mode' => $feeMode,
            'collection_fee_pct' => $feePct,
            'is_admin' => true,
            'lawyer_pct' => $lawyerPct,
        ]);

        self::officeMsg($exec, $actor, 'تسعير', 'حدّدت الإدارة أتعاب التنفيذ واعتمدتها وأُرسل العرض للعميل.');
        self::notify($exec, 'card', 't-blue', "عرض خدمة التنفيذ لطلبك {$exec->number} جاهز — بانتظار قبولك.");
        self::mail($exec, 'feeApproved');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /**
     * **يجوز تسعيره الآن؟** — حارسا `setFee` نفسهما (المرحلة ضمن `feeStages` + غير مرفوضٍ بعد الدراسة)،
     * تقرؤهما البطاقة علَماً (`canReprice`). كانت الواجهة تعيد كتابتهما بمقارنة «مرفوض»/«استفسار» نصّاً.
     */
    public static function canPrice(Execution $exec): bool
    {
        return ! $exec->isRejectedAfterStudy() && in_array($exec->effectiveStage(), self::feeStages($exec), true);
    }

    /** مراحل التسعير المسموحة: 2‑4 عادةً، وتضمّ 5 إن رفض العميل العرض أو استفسر عنه، و6 (قبل السداد) لإعادة التسعير. */
    private static function feeStages(Execution $exec): array
    {
        if ($exec->paid || $exec->effectiveStage() >= 7) {
            return [];
        }

        return in_array($exec->offer_status, [ExecutionOfferStatus::Rejected->value, ExecutionOfferStatus::Inquiry->value], true) || $exec->effectiveStage() === 6
            ? [2, 3, 4, 5, 6]
            : [2, 3, 4];
    }

    /**
     * **الطلب المرفوض لا يُسعَّر ولا يُعرَض.** `reject` يكتب القرار ولا ينقل المرحلة (المرفوض ليس
     * مغلقاً)، فبقيت مراحل التسعير مفتوحةً على طلبٍ رفضه المحامي بعد الدراسة — وكان `saveFee` وحده
     * يفحص القرار، فتمرّ الإدارة من فوقه بـ`setFee`/`approveFee` ويصل العميلَ عرضٌ على طلبٍ مرفوض.
     *
     * **والقاعدة أوسع من التسعير**: مراحل المرفوض تبقى 2 أو 3، فكلّ إجراءٍ محروسٍ بها كان يعمل
     * عليه — الإحالة تمحو «رُفض الطلب بعد الدراسة» وتُنبّه الإدارة، وطلبُ المستندات يطالب عميلاً
     * وصله بريدُ الرفض، والإسنادُ يُراسل محامياً على ملفٍّ لا عمل فيه. فالرسالة وسيطٌ ليصف كلُّ
     * حارسٍ بابَه، ولا يُقال «لا يُسعَّر» لمن ضغط «إحالة».
     */
    private static function guardNotRejected(Execution $exec, string $message = 'هذا الطلب مرفوض بعد الدراسة — لا يُسعَّر ولا يُعرَض.'): void
    {
        abort_if($exec->isRejectedAfterStudy(), 422, $message);
    }

    /**
     * **لا تسعير لملفٍّ بلا صاحب.** كان `setFee` الإداريّ و`saveFee` المحاميّ يقبلان ملفّاً
     * `assigned_lawyer_id = null`: فيصل العميلَ عرضٌ بمبلغٍ ومدّةٍ على ملفٍّ لا محاميَ له
     * ولا من يُسأل عن تقدير المدّة، ويسدّد فيبقى الملفّ بلا منفِّذ. والمُسعِّر يقدّر جهداً —
     * فمَن سيبذله يجب أن يكون معروفاً قبل الرقم. (`saveFee` يمرّ بحارس الالتقاط فيُختم
     * الملفّ باسم المحامي بعده، لكنّه يبقى مفتوحاً للإدارة عبر `setFee` وللملفّات القديمة.)
     */
    private static function guardAssigned(Execution $exec): void
    {
        abort_if(
            $exec->assigned_lawyer_id === null,
            422,
            'يلزم إسناد محامٍ لملفّ التنفيذ قبل تحديد الأتعاب — الإسناد من الإدارة أو من الموظّف المخوَّل.'
        );
    }

    // ── المحامي ──

    public static function accept(Execution $exec, ?User $actor = null): void
    {
        self::guard($exec, [2], 'لا يمكن قبول هذا الطلب في مرحلته الحالية.');
        abort_if($exec->isRejectedAfterStudy(), 422, 'هذا الطلب مرفوض بالفعل.');

        Workflow::run(new StudyExecution, $exec, $actor, ['action' => 'accept']);

        self::officeMsg($exec, $actor, 'قبول', 'قُبل الطلب، ويجري تحديد أتعاب التنفيذ.');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    public static function requestDocs(Execution $exec, ?User $actor = null): void
    {
        // الاستقبال (0‑1) أو الدراسة (2) أو تحديد الأتعاب (3)
        self::guard($exec, [0, 1, 2, 3], 'لا يمكن طلب مستندات في مرحلته الحالية.');
        // ولا تُطلب مستنداتٌ على ملفٍّ أُغلق قرارُه: العميل وصله بريد الرفض، فطلبُ نواقصَ بعده
        // يَعِده باستكمالٍ لا يقع — ويُنشئ صفوف مستنداتٍ «مطلوبة» على ملفٍّ لا إجراء فيه.
        self::guardNotRejected($exec, 'هذا الطلب مرفوض بعد الدراسة — لا تُطلب عليه مستندات.');

        Workflow::run(new StudyExecution, $exec, $actor, ['action' => 'requestDocs']);

        // النواقص من تحليل الملفّ إن وُجدت، وإلّا قائمة الاستقبال العامّة.
        // **والفارق يُبلَّغ للعميل**: قائمةٌ خرجت من فحص مستنداته يقرؤها حكماً على
        // ملفّه، وقائمة الاستقبال تُبنى من نوع الطلب قبل أن يُفحص شيء.
        // **الشرط على المصدر لا على امتلاء القائمة.** الاحتياطيّ يملأ `ai_missing`
        // أيضاً بقالبٍ حتميّ لا يقرأ مستنداً واحداً (سطرٌ يفحص خلوّ `defendant`)،
        // فيُقال للعميل «بعد دراسة الطلب ومستنداته» ولم يُقرأ منها شيء. والمصدر
        // وحده يفصل فحصاً وقع من قالبٍ ملأ الفراغ.
        $fromAnalysis = ! empty($exec->ai_missing)
            && (AiSource::tryFrom((string) $exec->ai_source)?->isRealAnalysis() ?? false);
        $labels = $fromAnalysis ? array_values($exec->ai_missing) : ['السند التنفيذي', 'الهوية الوطنية', 'مستند داعم'];
        foreach ($labels as $label) {
            $exec->documents()->firstOrCreate(['label' => (string) $label], ['status' => ExecutionDocumentStatus::Required->value]);
        }

        self::officeMsg($exec, $actor, 'نواقص', $fromAnalysis
            ? 'بعد دراسة الطلب ومستنداته، يرجى تزويدنا بالمستندات المذكورة لاستكمال الملفّ.'
            : 'يرجى تزويدنا بمستندات الاستقبال الأساسيّة لاستكمال دراسة الطلب (قائمة عامّة بحسب نوع السند).');
        self::notify($exec, 'upload', 't-amber', "طلب قسم التنفيذ مستندات إضافية على طلبك {$exec->number}.");
    }

    public static function reject(Execution $exec, ?User $actor = null): void
    {
        self::guard($exec, [2, 3], 'لا يمكن رفض هذا الطلب في مرحلته الحالية.');

        Workflow::run(new StudyExecution, $exec, $actor, ['action' => 'reject']);

        self::officeMsg($exec, $actor, 'رفض', 'تعذّر قبول الطلب بعد الدراسة.');
        self::notify($exec, 'exec', 't-red', "تعذّر قبول طلب التنفيذ {$exec->number} بعد الدراسة.");
        self::mail($exec, 'rejected');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    public static function saveFee(Execution $exec, int $fee, string $duration, string $feeMode = 'fixed', ?float $feePct = null, ?User $actor = null): void
    {
        self::guard($exec, [3], 'لا يمكن تحديد الأتعاب في مرحلته الحالية.');
        abort_if($exec->isRejectedAfterStudy(), 422, 'هذا الطلب مرفوض بالفعل.');
        self::guardAssigned($exec);

        Workflow::run(new SetExecutionFee, $exec, $actor, [
            'fee' => $fee,
            'duration' => $duration,
            'fee_mode' => $feeMode,
            'collection_fee_pct' => $feePct,
            'is_admin' => false,
        ]);

        self::officeMsg($exec, $actor, 'أتعاب', 'حُدّدت أتعاب التنفيذ وأُرسلت لاعتماد الإدارة.');
        // الاعتماد قرار الإدارة — وكان الملفّ ينتظر في المرحلة 4 بلا أن يعلم أحدٌ به
        self::notifyAdmins($exec, 't-amber', "أتعاب التنفيذ للطلب {$exec->number} بانتظار اعتماد الإدارة.");
        self::mailStaff($exec, 'feeAwaitingApproval');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    // ── العميل ──

    /**
     * **قبول العرض** — أثره يتبع نموذج الأتعاب، و`ExecFee::openOnAcceptance` تملكه:
     * الثابت تصدر له فاتورة وينتظر السداد، والنسبيّ لا مبلغ مستحقّاً فيه اليوم فيُفتح
     * ملفّه بالقبول نفسه (قرار المالك 2026-09-12: لا مقدَّم). والخبرُ هنا يصف ما وقع فعلاً
     * لا فاتورةً في كلّ حال — كان يُقال «صدرت الفاتورة» أيّاً كان النموذج.
     */
    public static function acceptOffer(Execution $exec): void
    {
        self::guard($exec, [5], 'لا يوجد عرض بانتظار القبول.');
        abort_unless($exec->fee_approved, 422, 'العرض غير معتمد بعد.');

        ExecFee::openOnAcceptance($exec);
        $exec->refresh();

        if ($exec->feeMode() === 'percent') {
            // فتحُ الملفّ أشعر الطرفين بنفسه؛ الباقي وصف النموذج كي لا ينتظر العميل فاتورة
            self::notify($exec, 'card', 't-blue', "قُبل عرض التنفيذ {$exec->number} — لا مبلغ مقدَّم، وتُصدَر فاتورة أتعاب مع كلّ مبلغ يُحصَّل.");
            self::notifyOffice($exec, 't-green', "قبل العميل عرض التنفيذ {$exec->number} بنموذج نسبة من المحصّل — فُتح الملفّ بلا فاتورة.");

            return;
        }

        self::notify($exec, 'card', 't-blue', "صدرت فاتورة أتعاب التنفيذ لطلبك {$exec->number} — بانتظار السداد.");
        self::notifyOffice($exec, 't-green', "قبل العميل عرض التنفيذ {$exec->number} وصدرت الفاتورة — بانتظار السداد.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function inquire(Execution $exec): void
    {
        self::guard($exec, [5, 6], 'لا يوجد عرض للاستفسار عنه.');
        abort_if($exec->paid, 422, 'تم سداد أتعاب هذا الملف بالفعل.');

        $exec->update(['offer_status' => ExecutionOfferStatus::Inquiry->value]);
        $exec->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>لديّ استفسار حول عرض خدمة التنفيذ.</p>', 'time_label' => self::clock(),
        ]);
        self::notifyOffice($exec, 't-amber', "استفسار العميل حول عرض التنفيذ {$exec->number}.");
        self::mailStaff($exec, 'offerInquiry');
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function rejectOffer(Execution $exec): void
    {
        self::guard($exec, [5, 6], 'لا يوجد عرض للرفض.');
        abort_if($exec->paid, 422, 'تم سداد أتعاب هذا الملف بالفعل.');

        Workflow::run(new RejectExecutionOffer, $exec);

        $exec->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>رفضتُ عرض خدمة التنفيذ.</p>', 'time_label' => self::clock(),
        ]);
        self::notifyOffice($exec, 't-red', "رفض العميل عرض خدمة التنفيذ {$exec->number}.");
        self::mailStaff($exec, 'offerRejected');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /**
     * يُشعر المكتب بحدثٍ من العميل يخصّ العرض: المحامي المسنَد **والإدارة**.
     * كان الإشعار للمحامي وحده، وإعادةُ التسعير بعد الاستفسار أو الرفض من صلاحيّة الإدارة
     * (`setFee`/`approveFee`) — فيصل الخبرُ من لا يملك الإجراء، ولا يصل من يملكه.
     */
    public static function notifyOffice(Execution $exec, string $tone, string $body, ?User $actor = null): void
    {
        // الفاعل لا يُشعَر بفعله (نظير `ManagesCourtProceedings::tellLawyer`). و`$actor=null`
        // يبقي السلوك السابق كما هو: أحداث العميل تصل المحامي دائماً.
        if ($exec->assigned_lawyer_id !== null && (int) $exec->assigned_lawyer_id !== (int) ($actor?->id ?? 0)) {
            Notify::send($exec->assigned_lawyer_id, 'exec', $tone, $body);
        }

        self::notifyAdmins($exec, $tone, $body, $actor);
    }

    /**
     * **خطوةُ ناجز يسجّلها أحدهم فيعلمها من يملك الملفّ.** نظير `tellLawyer` في القضايا:
     * كانت الخطوات الخمس تُشعر العميل وحده، فيسجّل الموظّف أو الإدارة قيداً أو حجزاً على
     * ملفٍّ مسنَدٍ لمحامٍ ولا يعلم به. العميل يُشعَر برسالته الخاصّة قبلها — فلا تكرار عليه.
     */
    private static function tellOffice(Execution $exec, ?User $actor, string $what): void
    {
        $actor ??= auth()->user();
        $by = $actor?->name ?? 'المكتب';

        self::notifyOffice($exec, 't-blue', "{$what} على ملفّ التنفيذ {$exec->number} — سجّله {$by}.", $actor);
    }

    /** إشعار الإدارة العليا — قرارات الأتعاب والعرض عندها (يناديه أيضاً فتحُ التنفيذ من قضيّة). */
    public static function notifyAdmins(Execution $exec, string $tone, string $body, ?User $except = null): void
    {
        // الإداريّ الفاعل لا يُشعَر بفعله — نظير المحامي في `notifyOffice` (كان يصله إشعار ما أغلقه أو سجّله بنفسه)
        foreach (User::where('role', Role::Admin)->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))->pluck('id') as $id) {
            Notify::send((int) $id, 'exec', $tone, $body);
        }
    }

    /**
     * تنبيه طاقم المكتب بعطل تشغيليّ لا يُعرَض للعميل: الموظفون والإدارة العليا
     * (ومعهم المحامي المسند إن وُجد). نظير التصعيد في GenerateTicketSummaryJob.
     */
    private static function alertStaff(Execution $exec, string $body): void
    {
        $recipients = User::whereIn('role', [Role::Employee, Role::Admin])->pluck('id')->all();
        if ($exec->assigned_lawyer_id !== null) {
            $recipients[] = $exec->assigned_lawyer_id;
        }

        foreach (array_unique($recipients) as $userId) {
            Notify::send($userId, 'exec', 't-amber', $body);
        }
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة أتعاب التنفيذ (محروس بالمرحلة 6) ويعيد رابط الدفع أو null.
     * التأكيد يتمّ عبر webhook/callback → PaymentReconciler::settle → markPaid.
     */
    public static function initiatePayment(Execution $exec, string $callbackUrl): ?string
    {
        self::guard($exec, [6], 'لا يمكن السداد قبل قبول العرض.');

        // **الأقدم لا الأحدث.** بعد تقسيم الأتعاب إلى ثلاث دفعات كانت `latest('id')` هي
        // الدفعة الثالثة: يسدّدها العميل ويبقى الأوّل مستحقّاً والملفّ مغلقاً.
        $invoice = ExecFee::nextPayable($exec);

        return $invoice ? app(PaymentGateways::class)->default()->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    // ── إجراءات ما بعد فتح الملف (محامي/إدارة) ──

    public static function addProcedure(Execution $exec, string $title, ?User $actor = null): void
    {
        self::guard($exec, [7, 8], 'يلزم فتح ملف التنفيذ أولاً.');
        $t = trim($title);
        abort_if($t === '', 422, 'أدخل وصف الإجراء.');
        $exec->procedures()->create(['title' => $t, 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
        $exec->update(['last_action' => $t]);
        self::officeMsg($exec, $actor, 'إجراء', 'إجراء تنفيذ جديد: '.$t.'.');
        self::notify($exec, 'exec', 't-blue', 'تحديث على ملف تنفيذك ('.self::ref($exec).'): '.$t.'.');
        Live::push(new ExecStatusBroadcast($exec));
    }

    /**
     * **إنهاء الملفّ** — بابان لا باب:
     *
     * 1. الملفّ العامل (7‑8): يُنهيه المحامي المسنَد أو الإدارة كما كان.
     * 2. **الملفّ المرفوض (2‑3) — للإدارة وحدها** (قرار المالك 2026-09-13): الرفض يكتب
     *    القرار ولا ينقل المرحلة، فالمرفوض يبقى مفتوحاً بلا مخرج — لا يُسعَّر ولا يُحال ولا
     *    يُسنَد ولا يُغلق، فيتراكم في القوائم أبداً. والمالك قرّر ألّا يُغلق تلقائيّاً بل بزرٍّ
     *    إداريّ صريح، فيبقى الرفضُ قابلاً للمراجعة حتى تحسم الإدارةُ أرشفتَه.
     *
     * والفحص هنا لا في المتحكّم: حارس `act` يقيس **الدور** لكلّ إجراء، وهذا شرطٌ يقرأ حالة
     * الصفّ (`decision` والمرحلة) مع الدور معاً — فمحلّه مع بقيّة حرّاس الحالة، ولا يُنسخ
     * في مسارٍ ثانٍ يتباعد عنه. والفاعل يصل صراحةً من المتحكّم، ويسقط على الجلسة لمن يناديها
     * برمجيّاً (نظير `officeMsg`).
     */
    public static function close(Execution $exec, string $reason = 'أخرى', ?User $actor = null): void
    {
        $actor ??= auth()->user();

        // القاعدة الواحدة في النموذج (`isRejectedOpen`) — كانت منسوخةً هنا بنصّ القرار العربيّ
        if ($exec->isRejectedOpen()) {
            abort_unless(
                $actor?->role === Role::Admin,
                403,
                'إنهاء الملفّ المرفوض وأرشفته من صلاحيّة الإدارة وحدها.'
            );
        } else {
            self::guard($exec, [7, 8], 'لا يمكن إغلاق الملف في مرحلته الحالية.');
        }

        $reason = in_array($reason, ExecFlow::CLOSE_REASONS, true) ? $reason : 'أخرى';

        Workflow::run(new CloseExecution, $exec, $actor, [
            'reason' => $reason,
        ]);

        self::officeMsg($exec, $actor, 'إغلاق', "أُغلق ملف التنفيذ وأُرشف — السبب: {$reason}.");
        self::notify($exec, 'check', 't-green', "أُغلق ملف التنفيذ لطلبك {$exec->number} ({$reason}) — ".self::ref($exec).'.');
        // والمكتب يعلم أيضاً: المحامي المسنَد حين تُغلقه الإدارة، والإدارة حين يُغلقه المحامي — كان العميل وحده
        // يُشعَر (ثبت في المتصفّح 2026-09-30، EXE-2026-5518)
        // الإغلاق بلا فاعلٍ جائز (النظام — `CloseExecution::deny`)، فلا يُقرأ اسمه بلا فحص
        $closer = $actor instanceof User ? $actor->name : 'النظام';
        self::notifyOffice($exec, 't-green', "أُغلق ملفّ التنفيذ {$exec->number} وأُرشف ({$reason}) — أغلقه {$closer}.", $actor);
        self::mail($exec, 'closed');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    // ── مسار ناجز داخل ملفّ التنفيذ (المرحلتان 7 و8) ──

    /** رفع الطلب في ناجز ⇐ «قيد التنفيذ» (8) برقم الطلب وتاريخه. */
    public static function fileNajiz(Execution $exec, string $requestNo, string $filedAt, ?User $actor = null): void
    {
        self::guard($exec, [7], 'يُسجَّل الرفع في ناجز بعد سداد الأتعاب وفتح الملفّ.');

        Workflow::run(new FileExecutionNajiz, $exec, $actor, [
            'request_no' => $requestNo,
            'filed_at' => $filedAt,
        ]);

        self::officeMsg($exec, $actor, 'ناجز', "رُفع طلب التنفيذ في منصّة ناجز برقم {$requestNo}.");
        self::notify($exec, 'exec', 't-blue', "رُفع طلب تنفيذك {$exec->number} في منصّة ناجز برقم الطلب {$requestNo}.");
        self::tellOffice($exec, $actor, "سُجّل رفع الطلب في ناجز برقم {$requestNo}");
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /** قيد الطلب لدى محكمة التنفيذ: المحكمة والدائرة وتاريخ القيد. */
    public static function registerNajiz(Execution $exec, string $court, string $circuit, string $registeredAt, ?User $actor = null): void
    {
        self::guard($exec, [8], 'يُسجَّل القيد بعد رفع الطلب في ناجز.');
        abort_if(blank($exec->najiz_request_no), 422, 'سجّل رقم الطلب في ناجز أوّلاً.');

        Workflow::run(new RegisterExecutionNajiz, $exec, $actor, [
            'court' => $court,
            'circuit' => $circuit,
            'registered_at' => $registeredAt,
        ]);

        self::officeMsg($exec, $actor, 'ناجز', "قُيّد طلب التنفيذ لدى {$court} — {$circuit}.");
        self::notify($exec, 'scale', 't-green', "قُيّد طلب تنفيذك {$exec->number} لدى {$court} — {$circuit}.");
        self::tellOffice($exec, $actor, "قُيّد الطلب لدى {$court} — {$circuit}");
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /** الإبلاغ بأمر التنفيذ — منه تبدأ مهلة الوفاء، ويحسبها `ExecFlow::payDueAfter`. */
    public static function notifyDebtor(Execution $exec, string $notifiedAt, ?User $actor = null): void
    {
        self::guard($exec, [8], 'يُسجَّل الإبلاغ بعد القيد لدى محكمة التنفيذ.');
        abort_if($exec->registered_at === null, 422, 'سجّل قيد الطلب لدى محكمة التنفيذ أوّلاً.');

        Workflow::run(new NotifyExecutionDebtor, $exec, $actor, [
            'notified_at' => $notifiedAt,
        ]);

        $due = ExecFlow::payDueAfter(Carbon::parse($notifiedAt));
        $label = $due->locale('ar')->translatedFormat('j F Y');
        self::officeMsg($exec, $actor, 'ناجز', "أُبلغ المنفَّذ ضدّه بأمر التنفيذ، ومهلة الوفاء حتى {$label}.");
        self::notify($exec, 'cal', 't-amber', "أُبلغ المنفَّذ ضدّه بأمر التنفيذ على طلبك {$exec->number} — مهلة الوفاء حتى {$label}.");
        self::tellOffice($exec, $actor, "أُبلغ المنفَّذ ضدّه بأمر التنفيذ ومهلة الوفاء حتى {$label}");
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /**
     * إجراءات عدم الوفاء بعد انقضاء المهلة (منع سفر، إيقاف خدمات، حجز…) — تُسجَّل كما اتُّخذت
     * في ناجز، ويُبلَّغ بها العميل. القائمة تحلّ محلّ سابقتها فتُسحب المرفوعة بإزالتها.
     *
     * @param  array<int, string>  $measures
     */
    public static function applyMeasures(Execution $exec, array $measures, ?User $actor = null): void
    {
        self::guard($exec, [8], 'تُتّخذ الإجراءات بعد الإبلاغ بأمر التنفيذ.');
        abort_if($exec->notified_at === null, 422, 'سجّل الإبلاغ بأمر التنفيذ أوّلاً.');
        $picked = array_values(array_intersect(ExecFlow::MEASURES, $measures));

        Workflow::run(new ApplyExecutionMeasures, $exec, $actor, [
            'measures' => $picked,
        ]);

        $text = $picked ? 'سُجّلت إجراءات عدم الوفاء: '.implode(' · ', $picked).'.' : 'رُفعت إجراءات عدم الوفاء عن المنفَّذ ضدّه.';
        self::officeMsg($exec, $actor, 'ناجز', $text);
        self::notify($exec, 'exec', 't-blue', "تحديث على طلب تنفيذك {$exec->number}: {$text}");
        self::tellOffice($exec, $actor, rtrim($text, '.'));
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    /** تحصيلٌ جزئيّ أو كامل من المنفَّذ ضدّه — يُراكَم ويُقاس عليه المتبقّي، وأثره في سجلّ الإجراءات. */
    /**
     * **تصحيح مبلغ المطالبة** (قرار المالك 2026-09-30) — قبل أوّل تحصيل، للمحامي المسنَد أو الإدارة، بسببٍ مكتوب.
     * الحرّاس كلّها في الانتقال (`SetExecutionClaimAmount`)؛ وهنا أثره: سطرٌ في المحادثة وإشعار العميل والمكتب.
     */
    public static function setClaimAmount(Execution $exec, int $amount, string $reason, ?User $actor = null): void
    {
        $previous = (int) $exec->amount;

        Workflow::run(new SetExecutionClaimAmount, $exec, $actor, ['amount' => $amount, 'reason' => $reason]);

        $text = 'صُحّح مبلغ المطالبة من '.number_format($previous).' إلى '.number_format($amount).' ريال — السبب: '.trim($reason);
        self::officeMsg($exec, $actor, 'مبلغ المطالبة', $text);
        self::notify($exec, 'card', 't-blue', "صُحّح مبلغ المطالبة في طلب تنفيذك {$exec->number} إلى ".number_format($amount).' ريال.');
        self::tellOffice($exec, $actor, 'صُحّح مبلغ المطالبة إلى '.number_format($amount).' ريال');
        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    public static function addCollection(Execution $exec, int $amount, string $note = '', ?User $actor = null): void
    {
        self::guard($exec, [8], 'التحصيل بعد قيد الطلب وبدء التنفيذ.');
        // الرسالة تَعِد بالقيد والحارس يفحص المرحلة وحدها: فيُسجَّل تحصيلٌ و`registered_at` فارغ —
        // مبلغٌ محصَّل على ملفٍّ لم يُقيَّد لدى محكمة التنفيذ بعد. الحارس الآن يطابق ما تقوله الرسالة.
        abort_if($exec->registered_at === null, 422, 'سجّل قيد الطلب لدى محكمة التنفيذ أوّلاً.');

        // المبلغ وسقف المتبقّي يحرسهما الانتقال وحده (`RecordExecutionCollection::guard`) — على الصفّ المقفول،
        // فتحصيلان متزامنان لا يتجاوزان المطالبة؛ ونسخةٌ ثانية هنا كانت تكرّر الشروط والرسائل نفسها
        Workflow::run(new RecordExecutionCollection, $exec, $actor, [
            'amount' => $amount,
            'note' => $note,
        ]);

        $total = (int) $exec->fresh()->collected;
        $exec->procedures()->create([
            'title' => 'تحصيل '.number_format($amount).' ريال'.($note !== '' ? ' — '.$note : ''),
            'type' => 'تحصيل', 'detail' => '', 'status' => 'منفّذ',
        ]);
        $remaining = max(0, (int) $exec->amount - $total);
        self::officeMsg($exec, $actor, 'تحصيل', 'حُصّل '.number_format($amount).' ريال'.($note !== '' ? " ({$note})" : '').'، والمتبقّي '.number_format($remaining).' ريال.');
        self::notify($exec, 'card', 't-green', 'حُصّل '.number_format($amount)." ريال على طلب تنفيذك {$exec->number} — المتبقّي ".number_format($remaining).' ريال.');
        self::mail($exec->fresh(), 'collection');
        self::tellOffice($exec, $actor, 'حُصّل '.number_format($amount).' ريال، والمتبقّي '.number_format($remaining).' ريال');

        // **النموذج النسبيّ يُفوتَر مع التحصيل** (قرار المالك: لا مقدَّم، والأتعاب نسبةٌ من
        // كلّ مبلغ يُحصَّل). وملفٌّ بنموذجٍ ثابت يُسجَّل تحصيله ولا يُفوتَر — كما كان.
        if (($invoice = ExecFee::issueCollectionFee($exec->fresh(), $amount, $note)) !== null) {
            $pct = ExecFee::pctLabel((float) $exec->collection_fee_pct);
            $exec->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'أتعاب',
                'body' => '<p>صدرت فاتورة أتعاب التنفيذ <b>'.e((string) $invoice->number).'</b> — '.e($pct).'% من '
                    .number_format($amount).' ريال، بإجمالي '.number_format((int) $invoice->amount).' ريال شاملاً الضريبة.</p>',
                'time_label' => self::clock(),
            ]);
            self::notify($exec, 'card', 't-amber', "صدرت فاتورة أتعاب التنفيذ {$invoice->number} ({$pct}% من المبلغ المحصَّل) بقيمة ".number_format((int) $invoice->amount).' ريال — بانتظار السداد.');
            self::mail($exec->fresh(), 'feeInvoice');
        }

        Live::push(new ExecStatusBroadcast($exec->fresh()));
    }

    // ── مساعدات ──

    /**
     * @param  array<int>  $allowed
     *
     * يستخدم المرحلة الفعّالة: التنفيذات القديمة (stage=null) تُعامَل كملفّات مفتوحة (8) أو مغلقة (9)
     * وفق حالتها، فتقبل إجراءات ما بعد فتح الملف (إجراء/إغلاق) دون أن تلتبس بالمراحل المبكّرة.
     */
    private static function guard(Execution $exec, array $allowed, string $message): void
    {
        if (! in_array($exec->effectiveStage(), $allowed, true)) {
            throw ValidationException::withMessages(['stage' => $message]);
        }
    }

    /**
     * **رسالة الإجراء باسم من قام به.** كانت ثابتة بحسب الدالّة لا بحسب الفاعل: إغلاق المحامي
     * يظهر باسم «الإدارة العليا»، وطلب الموظّف للنواقص يظهر باسم المحامي المسنَد — والمحادثة
     * سجلٌّ يقرأه العميل والمكتب. الفاعل من الوسيط إن مُرِّر، وإلّا من الجلسة.
     */
    private static function officeMsg(Execution $exec, ?User $actor, string $role, string $text): void
    {
        $actor ??= auth()->user();

        [$who, $name] = match ($actor?->role) {
            Role::Lawyer => ['lawyer', $actor->name],
            Role::Admin => ['admin', $actor->name],
            Role::Employee => ['staff', $actor->name],
            // بلا جلسة (طابور/أمر مجدول): باسم القسم لا باسم شخصٍ لم يفعل شيئاً
            default => ['lawyer', $exec->assigned_lawyer ?: 'قسم التنفيذ'],
        };

        $exec->messages()->create(['who' => $who, 'name' => $name, 'role' => $role, 'body' => '<p>'.e($text).'</p>', 'time_label' => self::clock()]);
    }

    /** المرجع المعروض للعميل: رقم ملفّ التنفيذ الداخليّ، وإلّا رقم الطلب — لا فراغ. */
    private static function ref(Execution $exec): string
    {
        return $exec->exec_no ? "الرقم المرجعيّ الداخليّ {$exec->exec_no}" : "رقم الطلب {$exec->number}";
    }

    /** إشعار صاحب الطلب — عامّ لأن `ExecFee` يشارك في الإخبار عن السداد وفتح الملفّ. */
    public static function notify(Execution $exec, string $icon, string $tone, string $body): void
    {
        Notify::send($exec->user_id, $icon, $tone, $body);
    }

    /**
     * **إعادة جدولة الدراسة** (قرار المالك 2026-09-12): تعذّر الذكاء لا يُملأ بقالب ولا يُترك.
     * التأخير ساعة: تهدئة المزوّد عند 429 تُقاس بالدقائق والساعات، فالإعادة الفوريّة تُهدر.
     */
    public static function scheduleStudyRetry(Execution $exec): void
    {
        if ($exec->ai_done || $exec->isClosed() || (int) $exec->ai_attempts >= AnalyzeExecutionJob::MAX_ATTEMPTS) {
            return;
        }

        AnalyzeExecutionJob::dispatch($exec)->delay(now()->addHour());
    }

    /**
     * ماتت مهمّة الدراسة (مهلة/استنفاد محاولات) — تُسجَّل الحقيقة على الملفّ: ملاحظةٌ داخليّة
     * لا يراها العميل، وتنبيهٌ للمكتب، وإعادة جدولة ما لم يُبلَغ السقف. **ولا مخرجَ مصطنَع**.
     */
    public static function studyUnavailable(Execution $exec, string $reason = ''): void
    {
        if ($exec->ai_done || $exec->isClosed()) {
            return;
        }

        $capped = (int) $exec->ai_attempts >= AnalyzeExecutionJob::MAX_ATTEMPTS;
        $exec->messages()->create([
            'who' => 'note', 'name' => 'النظام', 'role' => 'تعذّر التحليل الذكيّ',
            'body' => '<p><b>تعذّرت الدراسة الذكيّة لهذا الطلب — لم يُفحص أي مستند.</b> '
                .($capped
                    ? 'استُنفدت المحاولات التلقائيّة، ويلزم فحص المستندات يدوياً.'
                    : 'أُعيدت جدولة المحاولة تلقائياً.').'</p>',
            'time_label' => self::clock(),
        ]);

        self::alertStaff($exec, $capped
            ? "تعذّرت دراسة طلب التنفيذ {$exec->number} بعد استنفاد المحاولات — يلزم فحص المستندات يدوياً."
            : "تعذّرت دراسة طلب التنفيذ {$exec->number} — أُعيدت جدولة المحاولة تلقائياً.");

        self::scheduleStudyRetry($exec);
    }

    /** بريد أفضل-جهد لصاحب الطلب — بجانب إشعار النظام، لا بدلاً عنه. عامّ لـ`ExecFee`. */
    public static function mail(Execution $exec, string $event): void
    {
        if ($exec->user) {
            app(MailService::class)->send($exec->user, new ExecutionEventMail($exec, $event, 'client'));
        }
    }

    /**
     * بريد المكتب (قرار المالك 2026-09-12) — كان التنفيذ كلّه يراسل العميل وحده، فيبقى الطلب
     * الجديد والأتعاب المنتظِرة بلا علم أحد. ولكلّ فئةٍ رابطُ لوحتها في الرسالة.
     *
     * @param  array<int, 'admin'|'employee'>  $audiences
     */
    private static function mailStaff(Execution $exec, string $event, array $audiences = ['admin']): void
    {
        foreach ($audiences as $audience) {
            $users = User::where('role', $audience === 'admin' ? Role::Admin : Role::Employee)->get()->all();

            if ($users !== []) {
                app(MailService::class)->send($users, new ExecutionEventMail($exec, $event, $audience));
            }
        }
    }

    /** بريدُ إسنادٍ للمحامي — يناديه التقاطُ الملفّ وفتحُ التنفيذ من قضية. */
    public static function mailAssignedLawyer(Execution $exec): void
    {
        if ($exec->assignedLawyer) {
            app(MailService::class)->send($exec->assignedLawyer, new ExecutionEventMail($exec, 'assigned', 'lawyer'));
        }
    }

    /** ختم الوقت في رسائل المحادثة — عامّ لأن `ExecFee` يكتب رسائل على المحادثة نفسها. */
    public static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
