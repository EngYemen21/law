<?php

namespace App\Services\Ai;

use App\Enums\AiSource;
use App\Models\AiBlindReview;
use App\Models\AiRun;
use App\Models\User;

/**
 * الطبقة الثالثة — سحب عيّنة عمياء وقياس اتّفاقها مع حكم الآلة.
 *
 * الخطة: «عيّنة دوريّة (10–20 مخرجاً) يراجعها محامٍ **لا يعرف** أنها من الذكاء …
 * تُقارن نتيجته بحكم الطبقة الثانية: **اختلافهما يكشف عيباً في المعايير لا في
 * النموذج**».
 *
 * ما تفعله هذه الخدمة: تسحب عيّنة، وتحفظ حكم الآلة **مجمَّداً** وقت السحب، وتحسب
 * الاتّفاق بعد الحكم. وما لا تفعله: لا تراجع نيابةً عن المحامي — الحكم بشريّ، وهذه
 * أداته لا بديله.
 */
class AiBlindSample
{
    /** حجم العيّنة الدوريّة كما تفرضه الخطة (10–20). */
    public const DEFAULT_SIZE = 15;

    public const MAX_SIZE = 20;

    /**
     * يسحب عيّنة جديدة للمراجع.
     *
     * **عشوائيّة لا أحدث**: اختيار الأحدث يجعل العيّنة تتبع ما جرى هذا الأسبوع لا ما
     * يمثّل التشغيل، ويسهّل — بلا قصد — أن تُسحب بعد إصلاحٍ فتبدو الجودة أعلى.
     *
     * وتُستبعد المخرجات التي **راجعها هذا المراجع من قبل**: الحكم على ما رآه المرء
     * سابقاً ليس أعمى.
     *
     * @return int عدد ما سُحب فعلاً
     */
    public static function draw(User $reviewer, int $size = self::DEFAULT_SIZE): int
    {
        $size = max(1, min(self::MAX_SIZE, $size));

        $already = AiBlindReview::where('reviewer_id', $reviewer->id)->pluck('ai_run_id');

        $runs = AiRun::query()
            ->whereNotNull('confidence')      // بلا ثقة مقيسة لا مقارنة
            ->where('source', AiSource::AiSuccess->value) // الاحتياطيّ ليس مخرج نموذج
            ->whereNotIn('id', $already)
            ->inRandomOrder()
            ->limit($size)
            ->get();

        foreach ($runs as $run) {
            AiBlindReview::create([
                'ai_run_id' => $run->id,
                'reviewer_id' => $reviewer->id,
                // يُجمَّد وقت السحب: معايرةُ العتبة لاحقاً تغيّر حكم الآلة، فتصير
                // المقارنة بين حكمٍ بشريّ قديم وحكمٍ آليّ جديد — وهي مقارنة بلا معنى
                'machine_status' => $run->status,
                'machine_confidence' => $run->confidence,
            ]);
        }

        return $runs->count();
    }

    /**
     * حصيلة الاتّفاق بين المحامي والآلة.
     *
     * `agreement = null` حين لا أحكام: نسبةٌ من صفرٍ ليست «صفر اتّفاق» بل لا قياس.
     *
     * @return array{judged:int,pending:int,agreement:float|null,machineTooStrict:int,machineTooLax:int,blindnessBroken:int}
     */
    public static function summary(?User $reviewer = null): array
    {
        $query = AiBlindReview::query();
        if ($reviewer !== null) {
            $query->where('reviewer_id', $reviewer->id);
        }

        $judged = (clone $query)->whereNotNull('judged_at')->get();
        $pending = (clone $query)->whereNull('judged_at')->count();

        if ($judged->isEmpty()) {
            return [
                'judged' => 0,
                'pending' => $pending,
                'agreement' => null,
                'machineTooStrict' => 0,
                'machineTooLax' => 0,
                'blindnessBroken' => 0,
            ];
        }

        $agreed = $judged->filter(fn (AiBlindReview $r) => $r->agrees())->count();

        return [
            'judged' => $judged->count(),
            'pending' => $pending,
            'agreement' => round($agreed / $judged->count(), 3),
            // الخلافان ليسا سواءً: التشدّد يكلّف وقتاً، والتساهل يكلّف ملفّاً
            'machineTooStrict' => $judged->filter(fn (AiBlindReview $r) => $r->agrees() === false
                && $r->verdict === AiReviewAction::Accept)->count(),
            'machineTooLax' => $judged->filter(fn (AiBlindReview $r) => $r->agrees() === false
                && $r->verdict !== AiReviewAction::Accept)->count(),
            // أحكامٌ سُجِّلت بعد الكشف — تُعدّ ولا تُخفى، فالعيّنة تفقد بها عماها
            'blindnessBroken' => $judged->filter(fn (AiBlindReview $r) => ! $r->wasBlind())->count(),
        ];
    }
}
