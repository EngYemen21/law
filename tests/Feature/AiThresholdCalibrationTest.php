<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Models\AiRun;
use App\Models\Setting;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiThresholdCalibration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * معايرة العتبة بالقياس لا بالحدس.
 *
 * الخطة تفرض السؤال حرفياً: «عند العتبة الحاليّة، كم مخرجاً قُبل آلياً ثم رفضه
 * إنسان؟» — وكان الدليل يطلب من المكتب أن يقرأ التوزيع بنفسه من قاعدة البيانات.
 */
class AiThresholdCalibrationTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array{0:int,1:AiReviewAction}>  $rows [الثقة، قرار المراجع] */
    private function reviews(array $rows, string $task = 'ticket.triage'): void
    {
        foreach ($rows as [$confidence, $action]) {
            AiRun::create([
                'task_type' => $task,
                'source' => AiSource::AiSuccess->value,
                'status' => AiRun::STATUS_COMPLETED,
                'trace_id' => (string) Str::uuid(),
                'confidence' => $confidence,
                'review_action' => $action->value,
            ]);
        }
    }

    /** @return array<int, array{0:int,1:AiReviewAction}> */
    private function manyAt(int $confidence, AiReviewAction $action, int $times): array
    {
        return array_fill(0, $times, [$confidence, $action]);
    }

    // ── الحدّ الأدنى للعيّنة ──

    /**
     * توصيةٌ على ثلاث مراجعات تُلبِس الحدسَ ثوبَ القياس — وهو أسوأ من الحدس المُعلَن،
     * لأنه يُتخذ عليه قرار بثقة لا يستحقّها.
     */
    public function test_no_recommendation_is_given_on_a_sample_too_small_to_calibrate(): void
    {
        $this->reviews([[90, AiReviewAction::Accept], [40, AiReviewAction::Reject]]);

        $result = AiThresholdCalibration::analyse();

        $this->assertNull($result['recommended']);
        $this->assertSame(2, $result['sample']);
        $this->assertStringContainsString('الحدّ الأدنى', $result['reason']);
    }

    /** ولا مراجعات أصلاً ⇒ يُقال ذلك، لا تُعرض أصفار تُقرأ «لا أخطاء». */
    public function test_an_empty_sample_says_so_rather_than_showing_zeros(): void
    {
        $result = AiThresholdCalibration::analyse();

        $this->assertSame(0, $result['sample']);
        $this->assertNull($result['recommended']);
        $this->assertStringContainsString('لا شيء يُعاير عليه', $result['reason']);
    }

    // ── السؤال الحاسم ──

    /** «كم مخرجاً قُبل آلياً ثم رفضه إنسان؟» — يُحسب عند العتبة السارية. */
    public function test_it_counts_auto_accepts_that_a_human_then_rejected(): void
    {
        Setting::put('ai_auto_accept_threshold', 70);
        $this->reviews([
            [95, AiReviewAction::Reject],   // قُبل آلياً ورفضه إنسان ← الخطر
            [80, AiReviewAction::Reject],   // ومثله
            [90, AiReviewAction::Accept],   // صحيح
            [40, AiReviewAction::Accept],   // صُعِّد ثم قُبل ← وقتٌ ضائع لا أكثر
        ]);

        $result = AiThresholdCalibration::analyse();

        $this->assertSame(2, $result['wrongAccepts']);
        $this->assertSame(1, $result['wrongEscalations']);
        // الإنذار يُذكر ولو منعت العيّنةُ التوصية: قبولٌ خاطئ واقعةٌ حدثت لا استنتاجٌ إحصائيّ
        $this->assertStringContainsString('العتبة منخفضة', $result['reason']);
        $this->assertStringContainsString('الحدّ الأدنى', $result['reason'], 'ولا تُعطى توصية');
        $this->assertNull($result['recommended']);
    }

    /**
     * **«تعديل ثم قبول» ليس قبولاً**: احتاج يد إنسان، فلم يكن صالحاً للقبول الآليّ.
     * دمجه مع القبول يُخفي أثر العتبة تماماً.
     */
    public function test_an_edited_output_counts_against_auto_acceptance(): void
    {
        Setting::put('ai_auto_accept_threshold', 70);
        $this->reviews([[95, AiReviewAction::Edit]]);

        $this->assertSame(1, AiThresholdCalibration::analyse()['wrongAccepts']);
    }

    // ── التوصية ──

    /** أقلّ عتبة تُصفّر القبول الخاطئ — السلامة أوّلاً ثم توفير الوقت. */
    public function test_it_recommends_the_lowest_threshold_that_eliminates_wrong_accepts(): void
    {
        Setting::put('ai_auto_accept_threshold', 50);
        $this->reviews(array_merge(
            $this->manyAt(60, AiReviewAction::Reject, 10),  // مرفوضة كلّها دون 65
            $this->manyAt(90, AiReviewAction::Accept, 15),  // مقبولة كلّها فوق 85
        ));

        $result = AiThresholdCalibration::analyse();

        $this->assertSame(65, $result['recommended'], 'أوّل عتبة تستبعد المرفوضة عند 60');
        $this->assertSame(10, $result['wrongAccepts'], 'وعند العتبة الحاليّة عشرة قبولات خاطئة');
    }

    /**
     * مخرجٌ بثقة 100 رفضه إنسان ⇒ **لا توصية**: المشكلة في المقياس لا في العتبة،
     * ورفعها لن يُصلح مقياساً يمنح ثقةً عالية لمخرجٍ مرفوض.
     */
    public function test_no_threshold_is_recommended_when_the_confidence_signal_itself_is_broken(): void
    {
        $this->reviews(array_merge(
            $this->manyAt(100, AiReviewAction::Reject, 10),
            $this->manyAt(100, AiReviewAction::Accept, 15),
        ));

        $this->assertNull(AiThresholdCalibration::analyse()['recommended']);
    }

    // ── نطاق القياس ──

    /**
     * المهام عالية الحساسيّة خارج القياس: تُصعَّد دائماً مهما بلغت ثقتها، فإقحامها
     * يجعل كل تصعيد يبدو «خطأ عتبة» وهو قاعدة غير قابلة للمعايرة أصلاً.
     */
    public function test_high_sensitivity_tasks_are_excluded_from_calibration(): void
    {
        $this->reviews($this->manyAt(95, AiReviewAction::Reject, 30), 'case.pleading');

        $result = AiThresholdCalibration::analyse();

        $this->assertSame(0, $result['sample']);
    }

    /** وثقةٌ غير مقيسة لا تقول شيئاً عن العتبة — البوّابة تُصعّدها بلا نظر إليها. */
    public function test_unmeasured_confidence_is_not_part_of_the_sample(): void
    {
        AiRun::create([
            'task_type' => 'ticket.triage',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'confidence' => null,
            'review_action' => AiReviewAction::Reject->value,
        ]);

        $this->assertSame(0, AiThresholdCalibration::analyse()['sample']);
    }

    /** والمخرج الذي لم يراجعه أحد ليس شهادةً على شيء. */
    public function test_an_unreviewed_run_is_not_evidence(): void
    {
        AiRun::create([
            'task_type' => 'ticket.triage',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'confidence' => 95,
        ]);

        $this->assertSame(0, AiThresholdCalibration::analyse()['sample']);
    }
}
