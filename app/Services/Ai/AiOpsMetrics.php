<?php

namespace App\Services\Ai;

use App\Enums\AiSource;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * مؤشّرات التشغيل التي تفرضها المرحلة P6: نسبة الفشل، ونسبة الاحتياطيّ، وزمن
 * P50/P95، والكلفة، ونسبة ما يحتاج مراجعة.
 *
 * **قاعدة ثابتة هنا كما في بقيّة الطبقات:** لا بيانات ⇒ `null` لا صفر. لوحةٌ تقول
 * «نسبة الفشل 0%» بينما لم يجرِ نداءٌ واحد تكذب على من يقرؤها، وتُخفي أن التشغيل
 * متوقّف أصلاً — وهو أخطر من فشلٍ ظاهر.
 */
class AiOpsMetrics
{
    /**
     * لقطة تشغيليّة لفترة بالأيام.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(int $days = 7, ?string $taskType = null): array
    {
        $base = fn () => self::scope($days, $taskType);
        $total = $base()->count();

        if ($total === 0) {
            // لا نداءات في الفترة: كل النسب `null` — لا يُدّعى نجاحٌ ولا فشل
            return [
                'total' => 0,
                'fallback_rate' => null,
                'failure_rate' => null,
                'needs_review_rate' => null,
                'latency_p50_ms' => null,
                'latency_p95_ms' => null,
                'total_tokens' => 0,
                'estimated_cost' => null,
                'cost_coverage' => null,
            ];
        }

        $fallback = (clone $base())->where('source', AiSource::Fallback->value)->count();
        $failed = (clone $base())->where('status', AiRun::STATUS_FAILED)->count();
        $needsReview = (clone $base())->where('status', AiRun::STATUS_NEEDS_REVIEW)->count();

        $durations = (clone $base())->whereNotNull('duration_ms')->orderBy('duration_ms')->pluck('duration_ms')->all();
        $withCost = (clone $base())->whereNotNull('estimated_cost')->count();

        return [
            'total' => $total,
            'fallback_rate' => round($fallback / $total, 3),
            'failure_rate' => round($failed / $total, 3),
            'needs_review_rate' => round($needsReview / $total, 3),
            'latency_p50_ms' => self::percentile($durations, 0.50),
            'latency_p95_ms' => self::percentile($durations, 0.95),
            'total_tokens' => (int) ((clone $base())->sum('input_tokens') + (clone $base())->sum('output_tokens')),
            // الكلفة تُجمع من المسعَّر وحده
            'estimated_cost' => $withCost > 0 ? round((float) (clone $base())->sum('estimated_cost'), 4) : null,
            // نسبة النداءات التي يُعرف سعرها — بلا هذا الرقم يبدو المجموع كاملاً وهو جزئيّ
            'cost_coverage' => round($withCost / $total, 3),
        ];
    }

    /**
     * تنبيهات تتجاوز عتباتها. النتيجة فارغة = لا شيء يستدعي التدخّل.
     *
     * @return array<int, array{code:string, message:string, value:float}>
     */
    public static function alerts(int $days = 1): array
    {
        $snapshot = self::snapshot($days);
        $alerts = [];

        if (($snapshot['fallback_rate'] ?? null) !== null && $snapshot['fallback_rate'] > 0.20) {
            $alerts[] = [
                'code' => 'high_fallback_rate',
                'message' => 'خُمس المخرجات أو أكثر احتياطيّة — المزوّد متعثّر أو مهدَّأ.',
                'value' => $snapshot['fallback_rate'],
            ];
        }

        if (($snapshot['failure_rate'] ?? null) !== null && $snapshot['failure_rate'] > 0.10) {
            $alerts[] = [
                'code' => 'high_failure_rate',
                'message' => 'نسبة الفشل تجاوزت العُشر.',
                'value' => $snapshot['failure_rate'],
            ];
        }

        if (($snapshot['latency_p95_ms'] ?? null) !== null && $snapshot['latency_p95_ms'] > 30_000) {
            $alerts[] = [
                'code' => 'slow_p95',
                'message' => 'زمن P95 تجاوز 30 ثانية.',
                'value' => (float) $snapshot['latency_p95_ms'],
            ];
        }

        // تغطية سعريّة ناقصة: المجموع المعروض أقلّ من الحقيقة
        if ($snapshot['total'] > 0 && $snapshot['cost_coverage'] < 1.0) {
            $alerts[] = [
                'code' => 'incomplete_cost_coverage',
                'message' => 'بعض النماذج بلا سعر مُهيَّأ — الكلفة المعروضة جزئيّة لا كاملة.',
                'value' => (float) $snapshot['cost_coverage'],
            ];
        }

        return $alerts;
    }

    /** توزيع أسباب الفشل — يميّز عطل المزوّد عن عطل العقد. */
    public static function failureCodes(int $days = 7): array
    {
        return self::scope($days)
            ->whereNotNull('failure_code')
            ->selectRaw('failure_code, count(*) as total')
            ->groupBy('failure_code')
            ->pluck('total', 'failure_code')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private static function scope(int $days, ?string $taskType = null): Builder
    {
        $query = AiRun::query()->where('created_at', '>=', now()->subDays($days));

        return $taskType === null ? $query : $query->where('task_type', $taskType);
    }

    /** @param array<int,int> $sorted قيم مرتَّبة تصاعدياً */
    private static function percentile(array $sorted, float $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        $index = (int) ceil($p * count($sorted)) - 1;

        return (int) $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
