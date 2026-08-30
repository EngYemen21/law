<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\RunAiEvaluationJob;
use App\Models\AiEvaluationRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiEvaluator;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * الطبقة الثانية من التقييم: تشغيل الحالات المجهّلة على مزوّد حيّ ومقارنتها بالمراجع.
 *
 * الطبقة الأولى تقيس **طبقاتنا** أمام مخرجات مثبَّتة — لا تقيس النموذج. وبلا هذه
 * الطبقة لا يُعرف أثر تغيير تعليمة أو ترقية نموذج إلّا بعد وقوعه على ملفّات حقيقيّة،
 * وهو ما تمنعه الخطة صراحةً: «لا تغيير في تعليمة أو نموذج بلا تقييم انحدار».
 */
class AiEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);

        return User::factory()->create(['role' => Role::Admin]);
    }

    // ── التشغيل الجافّ ──

    public function test_the_dry_run_measures_every_registered_task(): void
    {
        $results = (new AiEvaluator)->run();

        $this->assertSame(
            array_keys(AiEvaluator::GATES),
            array_column($results, 'task'),
            'كل مهمّة لها بوّابة يجب أن تُقاس'
        );

        foreach ($results as $r) {
            $this->assertTrue($r['meets'], "{$r['task']} سقطت دون بوّابتها: ".implode(' | ', $r['failures']));
        }
    }

    /** التشغيل الجافّ لا يلمس الشبكة — وإلّا لصار كل اختبار نداءً مدفوعاً. */
    public function test_a_dry_run_makes_no_provider_call(): void
    {
        $ai = \Mockery::mock(LegalAiService::class);
        $ai->shouldNotReceive('evaluationCall');

        (new AiEvaluator($ai))->run(live: false);

        $this->addToAssertionCount(1);
    }

    /** الوظائف الستّ التي تفرضها الخطة مغطّاة — لا خمسٌ ولا أربع. */
    public function test_every_function_the_plan_requires_has_a_gate(): void
    {
        $required = [
            'ticket.triage',      // فرز التذكرة
            'document.analyze',   // تحليل المستند
            'ticket.summary',     // تلخيص الملف
            'consult.analyze',    // تحليل الاستشارة
            'meeting.decisions',  // استخراج القرارات
            'case.pleading',      // صياغة المذكرات
        ];

        foreach ($required as $task) {
            $this->assertArrayHasKey($task, AiEvaluator::GATES, "«{$task}» بلا بوّابة عبور");
        }
    }

    /** ولكل بوّابة حالاتٌ فعليّة — بوّابةٌ بلا حالات رقمٌ لا يقيس شيئاً. */
    public function test_every_gate_has_cases_behind_it(): void
    {
        $measured = array_column((new AiEvaluator)->run(), 'task');

        $this->assertSame(array_keys(AiEvaluator::GATES), $measured);
    }

    /**
     * حالةٌ توقُّعها معلَّق بحكم النموذج (اختلاق/حقن) لا تُقاس على مخرجٍ مثبَّت:
     * المحقِّق لا يرى النصّ الأصليّ فلا سبيل له إلى كشف ما زاده النموذج عليه.
     * عدّها ساقطةً يجعل البوّابة غير قابلة للعبور أبداً، فيصير التحذير ضجيجاً.
     */
    public function test_a_model_judgment_case_is_deferred_not_failed_in_a_dry_run(): void
    {
        $results = array_column((new AiEvaluator)->run(), null, 'task');
        $decisions = $results['meeting.decisions'];

        $this->assertGreaterThan(0, $decisions['skipped'], 'حالات الاختلاق والحقن تُؤجَّل للتشغيل الحيّ');
        $this->assertTrue($decisions['meets'], 'وبقيّة الحالات تعبر بوّابتها');
    }

    /** والمقام ما قِيس فعلاً: نسبةٌ مقامها حالاتٌ لم تُشغَّل تُقرأ تغطيةً كاملة وهي ناقصة. */
    public function test_the_rate_denominator_excludes_deferred_cases(): void
    {
        $decisions = array_column((new AiEvaluator)->run(), null, 'task')['meeting.decisions'];
        $fixture = array_column(AiEvaluator::fixtures(), null, 'task')['meeting.decisions'];

        $this->assertSame(count($fixture['cases']) - $decisions['skipped'], $decisions['total']);
    }

    /** واستخراج القرارات أعلى البوّابات: مخرجه يُنشئ مهامّ، فاختلاقٌ واحد يُنشئ التزاماً. */
    public function test_decision_extraction_carries_the_strictest_gate(): void
    {
        $this->assertSame(0.98, AiEvaluator::GATES['meeting.decisions']);
        $this->assertGreaterThan(AiEvaluator::GATES['ticket.triage'], AiEvaluator::GATES['meeting.decisions']);
    }
    // ── خطّ الأساس والمقارنة ──

    /**
     * كانت النتيجة تُحفَظ في مفتاح واحد يُستبدَل، فلا يبقى ما يُقارَن به: تتراجع
     * مهمّة من 100% إلى 60% ولا يُلاحَظ. القيد الدائم هو ما يجعل السؤال قابلاً للإجابة.
     */
    public function test_each_completed_run_is_recorded_as_a_baseline(): void
    {
        (new AiEvaluator)->run();
        AiEvaluator::remember([['task' => 'ticket.triage', 'rate' => 1.0]], false, null, 'اختبار');

        $this->assertSame(1, AiEvaluationRun::count());
        $this->assertSame('اختبار', AiEvaluationRun::first()->triggered_by);
    }

    /** والتشغيل الساقط لا يُقيَّد: حصيلته ليست قياساً بل خبرُ تعذُّر. */
    public function test_a_failed_run_does_not_pollute_the_baseline(): void
    {
        AiEvaluator::remember([], true, null, 'اختبار', failure: 'تعذّر الاتصال بالمزوّد');

        $this->assertSame(0, AiEvaluationRun::count());
        // والحالة الراهنة تبقى معروضة كي يقرأ المشغّل سبب التعذّر
        $this->assertSame('تعذّر الاتصال بالمزوّد', AiEvaluator::lastRun()['failure']);
    }

    /** ما الذي تغيّر: مقارنةٌ تقول «تراجَعَ» ولا تقول «لماذا» ملاحظةٌ لا قرار. */
    public function test_the_baseline_records_prompt_versions_and_models(): void
    {
        AiEvaluator::remember([['task' => 'ticket.triage', 'rate' => 1.0]], false, null);

        $run = AiEvaluationRun::first();
        $this->assertSame('v1', $run->prompt_versions['ticket.triage']);
        $this->assertArrayHasKey('gemini', $run->models);
    }

    /** التراجع يُرصد لكل مهمّة على حدة — **المتوسّط يخفي التراجع**. */
    public function test_a_regression_is_detected_per_task_not_by_the_average(): void
    {
        AiEvaluator::remember([
            ['task' => 'ticket.triage', 'rate' => 1.0],
            ['task' => 'case.pleading', 'rate' => 0.6],
        ], false, null);

        // المتوسّط ارتفع (0.80 ← 0.85) بينما هبطت الصياغة القانونيّة
        $now = [
            ['task' => 'ticket.triage', 'rate' => 1.0],
            ['task' => 'case.pleading', 'rate' => 0.7],
        ];
        $now[1]['rate'] = 0.5;

        $diff = AiEvaluator::diff($now, AiEvaluator::latestRun());

        $this->assertTrue(AiEvaluator::hasRegression($diff));
        $this->assertTrue($diff[1]['regressed'], 'الصياغة القانونيّة تراجعت');
        $this->assertFalse($diff[0]['regressed'], 'والفرز لم يتراجع');
    }

    /** ومهمّة جديدة ليست تحسّناً ولا تراجعاً — لا سابق لها فلا يصحّ الحكم. */
    public function test_a_new_task_is_neither_progress_nor_regression(): void
    {
        AiEvaluator::remember([['task' => 'ticket.triage', 'rate' => 1.0]], false, null);

        $diff = AiEvaluator::diff([
            ['task' => 'ticket.triage', 'rate' => 1.0],
            ['task' => 'meeting.decisions', 'rate' => 0.4],
        ], AiEvaluator::latestRun());

        $this->assertTrue($diff[1]['isNew']);
        $this->assertNull($diff[1]['delta']);
        $this->assertFalse(AiEvaluator::hasRegression($diff), 'مهمّة بلا سابق لا تُعدّ تراجعاً');
    }

    /**
     * والتراجع يُرصد ولو عبرت كل البوّابات: مهمّة هبطت من 100% إلى 99% تعبر بوّابة
     * 98% وهي إشارة تدهور. البوّابة حدٌّ أدنى، والمقارنة اتّجاه — وكلاهما مطلوب.
     */
    public function test_a_drop_is_a_regression_even_while_still_above_its_gate(): void
    {
        AiEvaluator::remember(array_map(
            fn (string $task) => ['task' => $task, 'rate' => 1.0],
            array_keys(AiEvaluator::GATES),
        ), false, null);

        // تشغيلٌ حقيقيّ: كل البوّابات تعبر، لكن meeting.decisions تهبط عن 100%
        AiEvaluator::remember([['task' => 'meeting.decisions', 'rate' => 0.99]], false, null);
        $diff = AiEvaluator::diff([['task' => 'meeting.decisions', 'rate' => 0.99]], AiEvaluator::previousRun());

        $this->assertTrue(AiEvaluator::hasRegression($diff));
    }

    /** والأمر نفسه: ينجح ويقيّد خطّ أساس حين لا تراجع. */
    public function test_the_command_records_a_baseline_and_succeeds_without_a_regression(): void
    {
        $this->artisan('ai:evaluate')->assertSuccessful();
        $this->artisan('ai:evaluate')->assertSuccessful();

        $this->assertSame(2, AiEvaluationRun::count(), 'كل تشغيل مكتمل يُقيَّد');
    }

    /**
     * التقييم يعمل **حتى على مسارٍ مُطفأ**.
     *
     * لولا ذلك لبقي المطفأ مطفأً أبداً: تفعيله يشترط تجاوز معياره، وقياس معياره
     * يشترط تشغيله — حلقةٌ مغلقة تجعل كل إطفاء نهائياً بلا قصد.
     */
    public function test_evaluation_still_measures_a_disabled_path(): void
    {
        Setting::put('ai_enabled_tasks', json_encode(['ticket.triage' => false]));

        $results = array_column((new AiEvaluator)->run(), null, 'task');

        $this->assertArrayHasKey('ticket.triage', $results, 'المسار المطفأ يبقى مقيساً');
        $this->assertTrue($results['ticket.triage']['meets']);
    }
    // ── حدّ التشغيل الحيّ معلن ──

    /**
     * حالات التنفيذ تصف **إشارات** (عدد المستندات، وجود سند) لا نصّاً يُرسَل لنموذج.
     * تشغيلها حيّاً مستحيل، والصدق أن تُعلَن جافّة بسببها لا أن يوهم التقرير باختبارها.
     */
    public function test_a_task_that_cannot_run_live_says_so_instead_of_pretending(): void
    {
        $ai = \Mockery::mock(LegalAiService::class);
        $ai->shouldReceive('evaluationCall')->andReturn(['data' => null, 'cost' => null, 'model' => null, 'failure' => null]);

        $results = (new AiEvaluator($ai))->run(['execution.analyze'], live: true);

        $this->assertFalse($results[0]['live']);
        $this->assertNotNull($results[0]['liveSkipped'], 'سببُ عدم التشغيل الحيّ يجب أن يُعلَن');
    }

    // ── الكلفة ──

    /** سعرٌ مجهول واحد يجعل المجموع «غير معلوم» — لا رقماً ناقصاً يبدو كاملاً. */
    public function test_an_unpriced_call_makes_the_total_unknown_not_partial(): void
    {
        $ai = \Mockery::mock(LegalAiService::class);
        $ai->shouldReceive('evaluationCall')->andReturn(['data' => null, 'cost' => null, 'model' => 'x', 'failure' => null]);

        $evaluator = new AiEvaluator($ai);
        $evaluator->run(['ticket.triage'], live: true);

        $this->assertGreaterThan(0, $evaluator->liveCalls());
        $this->assertNull($evaluator->cost());
    }

    /** وبلا نداء حيّ أصلاً: `null` لا صفر — «لم يُقَس» غير «كلّف صفراً». */
    public function test_a_dry_run_reports_no_cost_rather_than_zero(): void
    {
        $evaluator = new AiEvaluator;
        $evaluator->run();

        $this->assertNull($evaluator->cost());
    }

    // ── الحصيلة المحفوظة ──

    public function test_the_last_run_is_remembered_with_its_date(): void
    {
        $this->assertNull(AiEvaluator::lastRun());

        AiEvaluator::remember([['task' => 'ticket.triage', 'meets' => true]], false, null, 'الإدارة');

        $last = AiEvaluator::lastRun();
        $this->assertNotNull($last['at'], 'نتيجةٌ بلا تاريخ لا تُقارَن بشيء');
        $this->assertFalse($last['running']);
        $this->assertSame('الإدارة', $last['by']);
    }

    /** تشغيلٌ جارٍ لا يُقرأ نتيجته القديمة على أنها الجديدة. */
    public function test_a_running_live_evaluation_is_flagged_as_such(): void
    {
        AiEvaluator::remember([['task' => 'ticket.triage', 'meets' => true]], false, null, 'الإدارة');
        AiEvaluator::markRunning('الإدارة');

        $last = AiEvaluator::lastRun();
        $this->assertTrue($last['running']);
        $this->assertCount(1, $last['results'], 'الحصيلة السابقة تبقى معروضة موسومةً بأنها سابقة');
    }

    // ── الشاشة ──

    public function test_the_screen_carries_the_evaluation_state(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.ai-ops'))
            ->assertInertia(fn ($page) => $page
                ->has('evaluation.tasks')
                ->has('evaluation.liveCapable')
                ->has('evaluation.providerReady')
                ->where('evaluation.last', null)
            );
    }

    public function test_a_dry_run_from_the_screen_stores_its_result(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.evaluate'), ['live' => false])
            ->assertRedirect();

        $last = AiEvaluator::lastRun();
        $this->assertNotNull($last);
        $this->assertFalse($last['live']);
        $this->assertCount(count(AiEvaluator::GATES), $last['results']);
    }

    /** النداء الحيّ يُدفع للخلفية: عشر نداءات متسلسلة تتجاوز مهلة الطلب. */
    public function test_a_live_run_is_queued_not_executed_inside_the_request(): void
    {
        Queue::fake();
        config(['services.gemini.key' => 'test-key']);

        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.evaluate'), ['live' => true])
            ->assertRedirect();

        Queue::assertPushed(RunAiEvaluationJob::class);
        $this->assertTrue(AiEvaluator::lastRun()['running']);
    }

    /** بلا مزوّد مهيَّأ لا يُطلَق تشغيل حيّ يفشل حتماً. */
    public function test_a_live_run_without_a_provider_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.evaluate'), ['live' => true])
            ->assertSessionHasErrors('live');

        Queue::assertNothingPushed();
    }

    public function test_a_client_cannot_launch_an_evaluation(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)
            ->postJson(route('admin.ai-ops.evaluate'), ['live' => false])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
