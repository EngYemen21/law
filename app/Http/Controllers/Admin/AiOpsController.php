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
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewReason;
use App\Services\LegalAiService;
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

    /** مدد الاحتفاظ — قرار نظاميّ: الفارغ يعني «بلا حدّ» لا صفراً. */
    public function saveRetention(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retention' => ['present', 'array'],
            'retention.*' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $clean = [];
        foreach ($data['retention'] as $class => $days) {
            if (AiDataClass::tryFrom((string) $class) !== null) {
                $clean[$class] = $days === null || $days === '' ? null : (int) $days;
            }
        }

        Setting::put('ai_retention', json_encode($clean));

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
