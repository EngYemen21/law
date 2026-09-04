<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunAiEvaluationJob;
use App\Models\AiEvaluationRun;
use App\Models\Setting;
use App\Services\Ai\AiDataClass;
use App\Services\Ai\AiEvaluator;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewReason;
use App\Services\Ai\AiThresholdCalibration;
use App\Services\LegalAiService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تشغيل الذكاء وحوكمته — لوحة واحدة تجمع ما يفرضه اجتماع الحوكمة الدوريّ،
 * وما كان يحتاج تعديل كود ونشراً ليُضبط.
 *
 * ثلاثة قرارات كانت حبيسة الشيفرة: عتبة القبول الآليّ، وأسعار النماذج، ومدد
 * الاحتفاظ. وكلّها **قرارات مكتب لا قرارات هندسة** — العتبة قانونيّة، والأسعار
 * محاسبيّة، والاحتفاظ نظاميّ. مكانها لوحة التحكّم.
 */
class AiOpsController extends Controller
{
    public function index(Request $request): Response
    {
        $days = (int) $request->integer('days', 30) ?: 30;

        return Inertia::render('admin/ai-ops', [
            'days' => $days,
            'metrics' => AiOpsMetrics::snapshot($days),
            'alerts' => AiOpsMetrics::alerts($days),
            'failureCodes' => AiOpsMetrics::failureCodes($days),
            'rejectionReasons' => $this->labelledReasons($days),
            'editRate' => AiReviewInbox::humanEditRate($days),
            'pendingReview' => AiReviewInbox::countFor($request->user()),
            'settings' => [
                'threshold' => AiPolicyGate::threshold(),
                'defaultThreshold' => AiPolicyGate::DEFAULT_THRESHOLD,
                'pricing' => Setting::aiPricing(),
                'retention' => $this->retentionRows(),
                'retentionApproval' => Setting::aiRetentionApproval(),
                'retentionApproved' => Setting::aiRetentionApproved(),
                'budget' => Setting::aiBudget(),
            ],
            'spending' => [
                // `null` = لم يُسعَّر نداء واحد هذا الشهر، لا «أنفقنا صفراً»
                'thisMonth' => AiOpsMetrics::spentThisMonth(),
                'stopped' => AiOpsMetrics::budgetStopsCalls(),
            ],
            'evaluation' => [
                'last' => AiEvaluator::lastRun(),
                'tasks' => array_keys(AiEvaluator::GATES),
                'liveCapable' => AiEvaluator::LIVE_CAPABLE,
                // بلا مزوّد مهيَّأ لا معنى لزرّ «تشغيل حيّ» — يُعطَّل ويُشرح سببه
                'providerReady' => app(LegalAiService::class)->isConfigured(),
                // الفرق عن التشغيل السابق: النتيجة وحدها تقول «كم هي اليوم»، والسؤال
                // الحاكم «هل تراجعت» — ولا يُجاب إلّا بمقارنة
                'diff' => $this->evaluationDiff(),
                'baselineAt' => AiEvaluator::previousRun()?->created_at?->toDateTimeString(),
                'runsRecorded' => AiEvaluationRun::count(),
            ],
            // المفتاح بجانب حصيلته: مسارٌ يُفعَّل بلا قياس تفعيلٌ بالحدس
            'taskSwitches' => $this->taskSwitches(),
            // معايرة العتبة بالأرقام: كان القرار مطلوباً والأداة غائبة
            'calibration' => AiThresholdCalibration::analyse(),
        ]);
    }

    /**
     * تشغيل مجموعة التقييم — الطبقة الثانية.
     *
     * الجافّ فوريّ (بلا شبكة)، والحيّ يُدفع للخلفية: عشر حالات = عشرة نداءات
     * متسلسلة تتجاوز مهلة الطلب. والحالات **مصطنعة** لا بيانات عملاء.
     */
    public function evaluate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'live' => ['boolean'],
            'tasks' => ['array'],
            'tasks.*' => ['string', Rule::in(array_keys(AiEvaluator::GATES))],
        ]);

        $tasks = $data['tasks'] ?? [];

        if (! ($data['live'] ?? false)) {
            $evaluator = new AiEvaluator;
            $results = $evaluator->run($tasks, live: false);
            AiEvaluator::remember($results, false, null, $request->user()->name);

            return back()->with('flash', 'انتهى التقييم الجافّ على المخرجات المثبَّتة.');
        }

        if (! app(LegalAiService::class)->isConfigured()) {
            return back()->withErrors(['live' => 'لا مزوّد مهيَّأ — التشغيل الحيّ متعذّر.']);
        }

        AiEvaluator::markRunning($request->user()->name);
        RunAiEvaluationJob::dispatch($tasks, $request->user()->name);

        return back()->with('flash', 'انطلق التقييم الحيّ في الخلفية — حدّث الصفحة بعد دقائق لقراءة الحصيلة.');
    }

    /** حفظ معايرة العتبة — قرار قانونيّ يُتخذ بعد قياس لا بالحدس. */
    public function saveThreshold(Request $request): RedirectResponse
    {
        $data = $request->validate(['threshold' => ['required', 'integer', 'min:0', 'max:100']]);
        Setting::put('ai_auto_accept_threshold', $data['threshold']);

        return back()->with('flash', "ضُبطت عتبة القبول الآليّ على {$data['threshold']}%.");
    }

    /** أسعار النماذج لكل مليون توكن — بدونها تبقى الكلفة «غير معلومة» لا صفراً. */
    public function savePricing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pricing' => ['present', 'array'],
            'pricing.*.model' => ['required', 'string', 'max:120'],
            'pricing.*.input' => ['required', 'numeric', 'min:0'],
            'pricing.*.output' => ['required', 'numeric', 'min:0'],
        ]);

        $map = [];
        foreach ($data['pricing'] as $row) {
            $map[$row['model']] = ['input' => (float) $row['input'], 'output' => (float) $row['output']];
        }

        Setting::put('ai_pricing', json_encode($map, JSON_UNESCAPED_UNICODE));

        return back()->with('flash', 'حُفظت أسعار '.count($map).' نموذجاً.');
    }

    /**
     * مفاتيح تفعيل المسارات.
     *
     * الخطة تفرض «تفعيل مسارات منفردة **بعد تجاوز معيارها**». لذلك تُعرض حصيلة
     * التقييم بجانب كل مفتاح: مسارٌ يُفعَّل بلا قياس تفعيلٌ بالحدس، وهو ما تمنعه
     * الخطة صراحةً.
     */
    public function saveTasks(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tasks' => ['present', 'array'],
            'tasks.*' => ['boolean'],
        ]);

        // **المرجع سجلّ التعليمات لا جدولُ بوّابات التقييم.** كان الترشيح على
        // `AiEvaluator::GATES` (سبعة معرّفات لها حالات تقييم حيّة)، فسبعةٌ أخرى
        // بلا مفتاح إطفاءٍ إطلاقاً — منها **خمسٌ عالية الحساسيّة**: `consult.summary`
        // و`najiz.statement` و`assistant.draft` و`meeting.summary` و`case.classify`.
        // أي أن مولّد صحيفة الدعوى ومساعد المحامي لا يمكن إيقافهما إلّا بنشر شيفرة.
        // ووجودُ حالات تقييمٍ شرطٌ لقياس المسار، لا لامتلاك القدرة على إطفائه.
        $clean = [];
        foreach ($data['tasks'] as $task => $on) {
            if (array_key_exists($task, self::switchableTasks())) {
                $clean[$task] = (bool) $on;
            }
        }

        Setting::put('ai_enabled_tasks', json_encode($clean));

        $off = count(array_filter($clean, fn ($on) => ! $on));

        return back()->with('flash', $off === 0
            ? 'كل المسارات مفعَّلة.'
            : "أُطفئ {$off} مساراً — مخرجاتها تسقط إلى الاحتياطيّ الموسوم.");
    }

    /**
     * الميزانيّة الشهريّة — قرار محاسبيّ.
     *
     * السقف الفارغ = **بلا سقف** لا صفراً: الصفر يمنع كل نداء. والإيقاف التلقائيّ
     * قرارٌ صريح يُفعَّل بمعرفة أثره — تفعيله يوقف معالجة الذكاء كلّها عند التجاوز.
     */
    public function saveBudget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cap' => ['nullable', 'numeric', 'min:0'],
            'warnAt' => ['required', 'numeric', 'min:10', 'max:100'],
            'stop' => ['boolean'],
        ]);

        Setting::put('ai_budget', json_encode([
            'cap' => $data['cap'] === null || $data['cap'] === '' ? null : (float) $data['cap'],
            'warnAt' => round(((float) $data['warnAt']) / 100, 3),
            'stop' => (bool) ($data['stop'] ?? false),
        ]));

        return back()->with('flash', $data['cap'] === null
            ? 'أُلغي سقف الميزانيّة — لا إيقاف ولا تنبيه بالتجاوز.'
            : 'حُفظت الميزانيّة الشهريّة.');
    }

    /** مدد الاحتفاظ — قرار نظاميّ: الفارغ يعني «بلا حدّ» لا صفراً. */
    public function saveRetention(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retention' => ['present', 'array'],
            'retention.*' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'approve' => ['boolean'],
            'basis' => ['nullable', 'string', 'max:500'],
        ]);

        $clean = [];
        foreach ($data['retention'] as $class => $days) {
            if (AiDataClass::tryFrom((string) $class) !== null) {
                $clean[$class] = $days === null || $days === '' ? null : (int) $days;
            }
        }

        Setting::put('ai_retention', json_encode($clean));

        // الاعتماد واقعةٌ تُسجَّل: من قرّر ومتى وعلى أيّ سند. بدونه تبقى الأرقام
        // «افتراضاً» في الشاشة مهما حُفظت — ولا يُعرف عند التدقيق من قرّر.
        if ($data['approve'] ?? false) {
            Setting::put('ai_retention_approval', json_encode([
                'by' => $request->user()->name,
                'at' => now()->toDateTimeString(),
                'basis' => $data['basis'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

            Audit::log(
                action: 'اعتماد سياسة الاحتفاظ',
                description: 'اعتُمدت مدد الاحتفاظ بمخرجات الذكاء: '.json_encode($clean).'.'
                    .($data['basis'] ? ' السند: '.$data['basis'] : ''),
                category: 'الإدارة العليا',
                severity: 'warning',
            );

            return back()->with('flash', 'اعتُمدت مدد الاحتفاظ باسمك وتاريخ اليوم.');
        }

        return back()->with('flash', 'حُفظت مدد الاحتفاظ — تُطبَّق بأمر ai:purge.');
    }

    /** @return array<int, array{code:string,label:string,total:int,highRisk:bool}> */
    private function labelledReasons(int $days): array
    {
        $counts = AiReviewInbox::rejectionReasons($days);
        $rows = [];

        foreach ($counts as $code => $total) {
            $reason = AiReviewReason::tryFrom((string) $code);
            $rows[] = [
                'code' => (string) $code,
                'label' => $reason?->label() ?? (string) $code,
                'total' => (int) $total,
                'highRisk' => (bool) $reason?->isHighRisk(),
            ];
        }

        // الأخطر أولاً: تراجع فئة عالية الخطورة يمنع اعتماد نموذج جديد ولو تحسّن المتوسّط
        usort($rows, fn ($a, $b) => [$b['highRisk'], $b['total']] <=> [$a['highRisk'], $a['total']]);

        return $rows;
    }

    /**
     * الفرق بين آخر تشغيلين مسجَّلين.
     *
     * يُقارَن **القيد بالقيد** لا الحالةُ الراهنة بالقيد: الحالة الراهنة قد تكون
     * «تشغيلاً جارياً» بنتائج التشغيل الأسبق، فمقارنتها بنفسها تُظهر صفراً كاذباً.
     *
     * @return array<int,array<string,mixed>>
     */
    private function evaluationDiff(): array
    {
        $latest = AiEvaluator::latestRun();
        $previous = AiEvaluator::previousRun();

        if ($latest === null || $previous === null) {
            return [];
        }

        return AiEvaluator::diff($latest->results, $previous);
    }

    /**
     * حالة كل مسار وحصيلة تقييمه الأخيرة.
     *
     * @return array<int, array{task:string,enabled:bool,gate:float,rate:float|null,meets:bool|null}>
     */
    /**
     * المسارات التي لإطفائها معنى — المتقاعدة مستثناة.
     *
     * مفتاح إطفاءٍ لمسارٍ لا يعمل يوهم بأمرين كاذبين معاً: أنّ المسار يعمل،
     * وأنّ إطفاءه فعلٌ ذو أثر.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function switchableTasks(): array
    {
        return array_filter(AiPromptRegistry::PROMPTS, fn (array $p) => ($p['retired'] ?? false) === false);
    }

    private function taskSwitches(): array
    {
        $last = array_column(AiEvaluator::lastRun()['results'] ?? [], null, 'task');

        // كل تعليمة مسجَّلة لها مفتاح — لا المقيَّسة وحدها. و`gate = null` تعني
        // «لا حالات تقييم لهذا المسار»، وهو وصفٌ لحال القياس لا مبرّرٌ لحجب المفتاح.
        return array_map(fn (string $task) => [
            'task' => $task,
            'enabled' => Setting::aiTaskEnabled($task),
            'sensitivity' => AiPolicyGate::sensitivity($task),
            'gate' => AiEvaluator::GATES[$task] ?? null,
            // `null` = لم يُقَس بعد، لا «صفر» — والفارق هو ما يمنع تفعيلاً بالحدس
            'rate' => isset($last[$task]['rate']) ? (float) $last[$task]['rate'] : null,
            'meets' => isset($last[$task]['meets']) ? (bool) $last[$task]['meets'] : null,
        ], array_keys(self::switchableTasks()));
    }

    /** @return array<int, array{value:string,label:string,days:int|null,isDefault:bool}> */
    private function retentionRows(): array
    {
        $stored = Setting::aiRetention();

        return array_map(fn (AiDataClass $class) => [
            'value' => $class->value,
            'label' => $class->label(),
            'days' => $class->retentionDays(),
            'isDefault' => ! array_key_exists($class->value, $stored),
        ], AiDataClass::cases());
    }
}
