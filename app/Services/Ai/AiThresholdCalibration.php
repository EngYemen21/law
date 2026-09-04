<?php

namespace App\Services\Ai;

use App\Models\AiRun;

/**
 * أداة معايرة عتبة القبول الآليّ — تجيب سؤال الخطة الحاسم **بالأرقام**.
 *
 * الخطة تفرض معايرة العتبة «بعد قياس لا بالحدس»، والسؤال الذي تفرضه حرفياً:
 * **«عند العتبة الحاليّة، كم مخرجاً قُبل آلياً ثم رفضه إنسان؟»** — وكان الدليل
 * يطلب من المكتب أن يقرأ التوزيع بنفسه من قاعدة البيانات. أي أن القرار كان مطلوباً
 * والأداة غائبة.
 *
 * **مبدآن حاكمان هنا:**
 *
 * 1. الخطأان ليسا متساويين. **قبولٌ آليّ لمخرجٍ رفضه إنسان** خطأ يصل إلى الملفّ بلا
 *    مراجعة؛ و**تصعيدُ مخرجٍ قَبِله إنسان** يكلّف وقتاً فقط. فالتوصية تحابي السلامة:
 *    لا تُقترح عتبةٌ تُبقي قبولاً خاطئاً واحداً ما دام رفعُها ممكناً.
 *
 * 2. عيّنةٌ صغيرة لا تُعاير. توصيةٌ مبنيّة على ثلاث مراجعات تُلبِس الحدسَ ثوبَ
 *    القياس — وهو أسوأ من الحدس المُعلَن. فدون الحدّ الأدنى تُعاد `null` صراحةً.
 */
class AiThresholdCalibration
{
    /**
     * أقلّ عدد مراجعات مكتملة تُبنى عليها توصية.
     *
     * الرقم اجتهاديّ ومُعلَن: ما دون ذلك يُقال «العيّنة لا تكفي» لا تُعطى توصية.
     */
    public const MIN_SAMPLE = 20;

    /**
     * توزيع القرارات البشريّة على درجات الثقة.
     *
     * يُقصر على المهام **متوسّطة الحساسيّة** وحدها: العتبة لا تحكم غيرها أصلاً —
     * فالعالية تُصعَّد دائماً والمنخفضة تُقبل دائماً، وإقحامها يلوّث القياس.
     *
     * @return array{
     *     sample:int,
     *     threshold:int,
     *     wrongAccepts:int,
     *     wrongEscalations:int,
     *     recommended:int|null,
     *     reason:string,
     *     curve:array<int,array{threshold:int,wrongAccepts:int,wrongEscalations:int}>
     * }
     */
    public static function analyse(int $days = 90): array
    {
        $rows = self::sample($days);
        $current = AiPolicyGate::threshold();

        $at = fn (int $t) => self::errorsAt($rows, $t);
        [$wrongAccepts, $wrongEscalations] = $at($current);

        // منحنى كامل بخطوة 5: يرى المكتب الثمن عند كل عتبة لا رقماً واحداً مُملى
        $curve = [];
        foreach (range(0, 100, 5) as $t) {
            [$wa, $we] = $at($t);
            $curve[] = ['threshold' => $t, 'wrongAccepts' => $wa, 'wrongEscalations' => $we];
        }

        return [
            'sample' => count($rows),
            'threshold' => $current,
            'wrongAccepts' => $wrongAccepts,
            'wrongEscalations' => $wrongEscalations,
            'curve' => $curve,
            'recommended' => self::recommend($rows, $curve),
            'reason' => self::reason($rows, $wrongAccepts),
        ];
    }

    /**
     * المراجعات المكتملة ذات الثقة المقيسة على مهمّة متوسّطة الحساسيّة.
     *
     * تُشترط **ثقة مقيسة**: `null` تعني «لم يُقَس»، والبوّابة تُصعّدها دائماً بلا
     * نظر إلى العتبة — فلا تقول شيئاً عن صوابها.
     *
     * @return array<int, array{confidence:int, humanAccepted:bool}>
     */
    private static function sample(int $days): array
    {
        // **الأسماء كما تُخزَّن في `task_type` لا كما تُسجَّل في `SENSITIVITY`.**
        //
        // مفاتيح `SENSITIVITY` معرّفاتُ تعليمات (`ticket.triage`)، والمخزَّن في العمود
        // اسمٌ قصير (`triage`) لأن `TicketTriage` تمرّر المعرّف في `policyTask`. فكانت
        // `whereIn` تُطابق ما لا يُكتب: **العيّنة صفرٌ أبداً**، والشاشة تُظهر
        // «recommended: null» دائماً، ولا تُعايَر العتبة من قرارٍ بشريّ واحد مهما
        // راجع المكتب. وقد قِيس على قاعدة التطوير: ١٥ قيد `triage` بثقةٍ مقيسة،
        // ولا واحد منها يدخل العيّنة.
        $medium = array_keys(array_filter(AiPolicyGate::SENSITIVITY, fn ($s) => $s === 'medium'));
        $stored = array_values(array_unique(array_map(
            fn (string $promptId) => AiRunLogger::storedTaskType($promptId),
            $medium
        )));

        return AiRun::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('confidence')
            ->whereNotNull('review_action')
            ->whereIn('task_type', $stored)
            ->get(['confidence', 'review_action'])
            ->map(fn (AiRun $run) => [
                'confidence' => (int) $run->confidence,
                // «تعديل ثم قبول» ليس قبولاً: احتاج يد إنسان، فلم يكن صالحاً للقبول
                // الآليّ. دمجه مع القبول يُخفي أثر العتبة تماماً.
                'humanAccepted' => $run->review_action === AiReviewAction::Accept,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{confidence:int, humanAccepted:bool}>  $rows
     * @return array{0:int,1:int} [قبولٌ آليّ رفضه إنسان، تصعيدٌ قَبِله إنسان]
     */
    private static function errorsAt(array $rows, int $threshold): array
    {
        $wrongAccepts = 0;
        $wrongEscalations = 0;

        foreach ($rows as $row) {
            $wouldAccept = $row['confidence'] >= $threshold;

            if ($wouldAccept && ! $row['humanAccepted']) {
                $wrongAccepts++;   // وصل الملفَّ بلا مراجعة وكان يستحقّها
            } elseif (! $wouldAccept && $row['humanAccepted']) {
                $wrongEscalations++; // كلّف وقتاً بلا داعٍ
            }
        }

        return [$wrongAccepts, $wrongEscalations];
    }

    /**
     * أقلّ عتبة **تُصفّر القبول الخاطئ**، وعندها أقلّ تصعيد زائد.
     *
     * الترتيب مقصود: تصفير الخطأ الخطر أوّلاً، ثم توفير الوقت — لا العكس. وإن تعذّر
     * تصفيره عند أي عتبة (مخرجٌ بثقة 100 رفضه إنسان) فلا توصية: المشكلة في المقياس
     * لا في العتبة، ورفعها لن يُصلح مقياساً يمنح ثقةً عالية لمخرجٍ مرفوض.
     *
     * @param  array<int, array{confidence:int, humanAccepted:bool}>  $rows
     * @param  array<int, array{threshold:int,wrongAccepts:int,wrongEscalations:int}>  $curve
     */
    private static function recommend(array $rows, array $curve): ?int
    {
        if (count($rows) < self::MIN_SAMPLE) {
            return null;
        }

        $clean = array_values(array_filter($curve, fn ($p) => $p['wrongAccepts'] === 0));

        if ($clean === []) {
            return null;
        }

        usort($clean, fn ($a, $b) => [$a['wrongEscalations'], $a['threshold']] <=> [$b['wrongEscalations'], $b['threshold']]);

        return $clean[0]['threshold'];
    }

    /** تعليل التوصية أو سبب غيابها — رقمٌ بلا سببه لا يُتخذ عليه قرار. */
    private static function reason(array $rows, int $wrongAccepts): string
    {
        $n = count($rows);

        if ($n === 0) {
            return 'لا مراجعات مكتملة بثقة مقيسة على مهمّة متوسّطة الحساسيّة — لا شيء يُعاير عليه.';
        }

        // الإنذار يُذكر **ولو منعت العيّنةُ التوصية**: قبولٌ خاطئ واحد واقعةٌ حدثت،
        // لا استنتاجٌ إحصائيّ. حجبُه بحجّة صغر العيّنة يُخفي خطراً وقع فعلاً.
        $alarm = $wrongAccepts > 0
            ? "عند العتبة الحاليّة قُبل آلياً {$wrongAccepts} مخرجاً ثم رفضه إنسان — "
                .'أيّ قبولٍ خاطئ واحد يعني أن العتبة منخفضة.'
            : '';

        if ($n < self::MIN_SAMPLE) {
            $note = "العيّنة {$n} مراجعة، والحدّ الأدنى ".self::MIN_SAMPLE
                .' — توصيةٌ على عيّنة أصغر تُلبِس الحدسَ ثوبَ القياس.';

            return $alarm === '' ? $note : $alarm.' '.$note;
        }

        return $alarm !== ''
            ? $alarm
            : 'لا قبول آليّ خاطئ عند العتبة الحاليّة — الباقي موازنةُ وقتٍ لا سلامة.';
    }
}
