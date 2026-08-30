<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Jobs\TriageDocumentJob;
use App\Jobs\TriageTicketOnOpenJob;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiCost;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التشغيل والكلفة (P6): استهلاك مقروء من المزوّد، وكلفة من أسعار مُهيَّأة،
 * وطوابير مفصولة بالحساسيّة، ومؤشّرات لا تكذب حين لا بيانات.
 */
class AiOpsTest extends TestCase
{
    use RefreshDatabase;

    private function aiRun(array $overrides = []): AiRun
    {
        return AiRun::create(array_merge([
            'task_type' => 'consult',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
        ], $overrides));
    }

    // ── الاستهلاك يُقرأ لا يُقدَّر ──

    public function test_usage_is_read_from_each_provider_response_shape(): void
    {
        $gemini = AiUsage::fromGemini(['usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 300]]);
        $this->assertSame(1200, $gemini->inputTokens);
        $this->assertSame(1500, $gemini->total());

        $glm = AiUsage::fromOpenAiCompatible(['usage' => ['prompt_tokens' => 800, 'completion_tokens' => 200]]);
        $this->assertSame(1000, $glm->total());
    }

    public function test_a_response_without_usage_reports_empty_not_invented(): void
    {
        $this->assertTrue(AiUsage::fromGemini([])->isEmpty());
        $this->assertTrue(AiUsage::fromOpenAiCompatible(null)->isEmpty());
    }

    // ── الكلفة: لا رقم بلا سعر ──

    /** لا سعر مُهيَّأ ⇒ `null` لا صفر. الصفر يقول «مجّانيّ» وهو ادّعاء كاذب. */
    public function test_cost_is_null_when_no_rate_is_configured(): void
    {
        config(['services.ai.pricing' => []]);

        $this->assertNull(AiCost::estimate('gemini-2.5-flash', new AiUsage(1000, 500)));
        $this->assertFalse(AiCost::hasRate('gemini-2.5-flash'));
    }

    public function test_cost_is_computed_from_the_configured_rate(): void
    {
        config(['services.ai.pricing' => ['test-model' => ['input' => 1.0, 'output' => 4.0]]]);

        // مليون إدخال بسعر 1 + نصف مليون إخراج بسعر 4 = 1 + 2 = 3
        $this->assertSame(3.0, AiCost::estimate('test-model', new AiUsage(1_000_000, 500_000)));
        $this->assertTrue(AiCost::hasRate('test-model'));
    }

    public function test_cost_of_an_empty_usage_is_null(): void
    {
        config(['services.ai.pricing' => ['test-model' => ['input' => 1.0, 'output' => 4.0]]]);

        $this->assertNull(AiCost::estimate('test-model', new AiUsage(0, 0)));
        $this->assertNull(AiCost::estimate(null, new AiUsage(100, 100)));
    }

    // ── الطوابير ──

    public function test_queues_separate_by_sensitivity_not_only_weight(): void
    {
        $this->assertSame(AiQueue::LOW_RISK, AiQueue::for('chat.reply'));
        $this->assertSame(AiQueue::LOW_RISK, AiQueue::for('ticket.triage'));
        $this->assertSame(AiQueue::DOCUMENTS, AiQueue::for('document.analyze'));
        $this->assertSame(AiQueue::LEGAL_REVIEW, AiQueue::for('case.pleading'));
    }

    /** المجهول يُعامَل قانونياً — نفس الافتراض الآمن في بوّابة السياسة. */
    public function test_an_unregistered_task_defaults_to_the_legal_queue(): void
    {
        $this->assertSame(AiQueue::LEGAL_REVIEW, AiQueue::for('مهمّة.جديدة.لم.تُصنَّف'));
    }

    // ── المؤشّرات لا تكذب ──

    /** لوحةٌ تقول «الفشل 0%» بلا نداءات تُخفي أن التشغيل متوقّف — وهو أخطر من فشل ظاهر. */
    public function test_metrics_report_null_not_zero_when_there_is_no_data(): void
    {
        $snapshot = AiOpsMetrics::snapshot();

        $this->assertSame(0, $snapshot['total']);
        $this->assertNull($snapshot['failure_rate']);
        $this->assertNull($snapshot['fallback_rate']);
        $this->assertNull($snapshot['latency_p95_ms']);
        $this->assertNull($snapshot['estimated_cost']);
    }

    public function test_rates_and_percentiles_are_computed_from_real_rows(): void
    {
        $this->aiRun(['duration_ms' => 100]);
        $this->aiRun(['duration_ms' => 200]);
        $this->aiRun(['duration_ms' => 300]);
        $this->aiRun(['duration_ms' => 40_000, 'source' => AiSource::Fallback->value, 'status' => AiRun::STATUS_NEEDS_REVIEW]);

        $snapshot = AiOpsMetrics::snapshot();

        $this->assertSame(4, $snapshot['total']);
        $this->assertSame(0.25, $snapshot['fallback_rate']);
        $this->assertSame(0.25, $snapshot['needs_review_rate']);
        $this->assertSame(40_000, $snapshot['latency_p95_ms']);
        $this->assertSame(200, $snapshot['latency_p50_ms']);
    }

    /** تغطية سعريّة ناقصة تُعلَن: المجموع المعروض جزئيّ لا كامل. */
    public function test_partial_cost_coverage_is_surfaced_not_hidden(): void
    {
        $this->aiRun(['estimated_cost' => 0.5]);
        $this->aiRun(['estimated_cost' => null]);

        $snapshot = AiOpsMetrics::snapshot();

        $this->assertSame(0.5, $snapshot['estimated_cost']);
        $this->assertSame(0.5, $snapshot['cost_coverage'], 'نصف النداءات بلا سعر معروف');
        $this->assertContains(
            'incomplete_cost_coverage',
            array_column(AiOpsMetrics::alerts(), 'code'),
            'المجموع الجزئيّ يجب أن يُنبَّه عليه لا أن يُعرض كأنه كامل'
        );
    }

    public function test_alerts_fire_on_excessive_fallback_and_latency(): void
    {
        foreach (range(1, 4) as $i) {
            $this->aiRun(['source' => AiSource::Fallback->value, 'duration_ms' => 45_000, 'estimated_cost' => 0.1]);
        }
        $this->aiRun(['duration_ms' => 100, 'estimated_cost' => 0.1]);

        $codes = array_column(AiOpsMetrics::alerts(), 'code');

        $this->assertContains('high_fallback_rate', $codes);
        $this->assertContains('slow_p95', $codes);
    }

    public function test_a_healthy_period_raises_no_alerts(): void
    {
        config(['services.ai.pricing' => ['m' => ['input' => 1.0, 'output' => 1.0]]]);
        foreach (range(1, 5) as $i) {
            $this->aiRun(['duration_ms' => 800, 'model' => 'm', 'estimated_cost' => 0.01]);
        }

        $this->assertSame([], AiOpsMetrics::alerts());
    }

    // ── الربط الفعليّ: الطوابير موصولة بالوظائف لا معرَّفة وحدها ──

    /**
     * مُطفأ افتراضياً ⇒ الطابور الافتراضيّ.
     *
     * الإنتاج يشغّل `queue:work --queue=default`؛ فتفعيلُ الفصل قبل تحديث أمر
     * العامل يوقف **كل** معالجة الذكاء صامتةً: لا خطأ ولا سجلّ، فقط مهامّ لا
     * تُلتقط أبداً. لذا يبدأ مُطفأً ويُفعَّل بعد ضبط العامل.
     */
    public function test_jobs_stay_on_the_default_queue_until_separation_is_enabled(): void
    {
        config(['services.ai.separate_queues' => false]);

        $this->assertNull((new AnalyzeExecutionJob($this->execution()))->queue);
        $this->assertNull(AiQueue::resolve('case.pleading'));
    }

    public function test_enabling_separation_routes_each_job_to_its_own_queue(): void
    {
        config(['services.ai.separate_queues' => true]);

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-'.uniqid(),
            'type' => 'نزاع', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);

        $this->assertSame(AiQueue::LEGAL_REVIEW, (new AnalyzeExecutionJob($this->execution()))->queue);
        $this->assertSame(AiQueue::LOW_RISK, (new TriageTicketOnOpenJob($ticket, 'تفاصيل', 'نزاع'))->queue);
        $doc = $ticket->documents()->create(['name' => 'عقد.pdf', 'path' => 'x/عقد.pdf', 'status' => 'مرفوع']);
        $this->assertSame(AiQueue::DOCUMENTS, (new TriageDocumentJob($ticket, $doc))->queue);
    }

    private function execution(): Execution
    {
        return Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EX-'.uniqid(), 'sanad' => 'شيك', 'subject' => 'تحصيل',
            'amount' => 1000, 'defendant' => 'خصم', 'stage' => 1,
        ]);
    }

    public function test_failure_codes_distinguish_provider_faults_from_contract_faults(): void
    {
        $this->aiRun(['failure_code' => 'provider_unavailable']);
        $this->aiRun(['failure_code' => 'provider_unavailable']);
        $this->aiRun(['failure_code' => 'invalid_structure']);

        $codes = AiOpsMetrics::failureCodes();

        $this->assertSame(2, $codes['provider_unavailable']);
        $this->assertSame(1, $codes['invalid_structure']);
    }
    // ── تقرير الحوكمة ──

    /**
     * صفرُ نداءات ليس 'أداءً ممتازاً': لا قياس أصلاً. التقرير الذي يعرض 0% فشل
     * على قاعدة فارغة يقود اجتماع الحوكمة إلى قرار مبنيّ على لا شيء.
     */
    public function test_the_report_says_there_is_nothing_to_measure_rather_than_reporting_zero(): void
    {
        $this->artisan('ai:report --days=30')
            ->expectsOutputToContain('لا نداءات في الفترة')
            ->assertSuccessful();
    }

    /** وحين لا مصدر معتمد يقولها صراحةً: الاستشهاد معطَّل لا 'يعمل بلا مصادر'. */
    public function test_the_report_flags_an_empty_legal_corpus(): void
    {
        $this->artisan('ai:report')
            ->expectsOutputToContain('لا مصدر معتمد')
            ->assertSuccessful();
    }
    // ── الميزانيّة الشهريّة (P6) ──

    private function costedRun(float $cost, ?string $at = null): AiRun
    {
        $run = AiRun::create([
            'task_type' => 'consult',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'model' => 'gemini-2.5-flash',
            'estimated_cost' => $cost,
        ]);

        if ($at !== null) {
            $run->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $run;
    }

    /** بلا سقف: لا تنبيه ولا إيقاف — الفراغ «بلا حدّ» لا صفر يمنع كل نداء. */
    public function test_no_cap_means_no_budget_alert_and_no_stop(): void
    {
        $this->costedRun(9999.0);

        $this->assertSame([], AiOpsMetrics::budgetAlerts());
        $this->assertFalse(AiOpsMetrics::budgetStopsCalls());
    }

    /** التنبيه قبل الوقوع لا بعده: 80% افتراضاً. */
    public function test_a_warning_fires_before_the_cap_is_reached(): void
    {
        Setting::put('ai_budget', json_encode(['cap' => 100, 'warnAt' => 0.8, 'stop' => false]));
        $this->costedRun(85.0);

        $alerts = AiOpsMetrics::budgetAlerts();

        $this->assertSame('budget_warning', $alerts[0]['code']);
        $this->assertFalse(AiOpsMetrics::budgetStopsCalls(), 'التنبيه لا يوقف');
    }

    /**
     * **التجاوز وحده لا يوقف**: الإيقاف مُطفأ افتراضياً لأن وقف معالجة الذكاء كلّها
     * أثرٌ واسع لا يُفتَرض بالنيابة عن المكتب — نظير درس AI_SEPARATE_QUEUES.
     */
    public function test_exceeding_the_cap_alerts_but_does_not_stop_unless_enabled(): void
    {
        Setting::put('ai_budget', json_encode(['cap' => 10, 'warnAt' => 0.8, 'stop' => false]));
        $this->costedRun(25.0);

        $this->assertSame('budget_exceeded', AiOpsMetrics::budgetAlerts()[0]['code']);
        $this->assertFalse(AiOpsMetrics::budgetStopsCalls());

        Setting::put('ai_budget', json_encode(['cap' => 10, 'warnAt' => 0.8, 'stop' => true]));
        $this->assertTrue(AiOpsMetrics::budgetStopsCalls(), 'يوقف بعد تفعيله صراحةً');
    }

    /** والإيقاف يُميَّز عن عطل المزوّد: يُصلَح بقرارٍ في اللوحة لا بالانتظار. */
    public function test_a_stopped_call_reports_budget_not_a_provider_failure(): void
    {
        config(['services.gemini.key' => 'k']);
        Setting::put('ai_budget', json_encode(['cap' => 1, 'warnAt' => 0.8, 'stop' => true]));
        $this->costedRun(5.0);

        $result = app(AiGateway::class)->call(fn () => 'لا ينبغي أن يُنادى');

        $this->assertNull($result->text, 'لم يُرسَل نداء أصلاً');
        $this->assertSame(AiFailure::BUDGET_EXCEEDED, $result->failureCode);
    }

    /** الشهر الجاري لا نافذة اللوحة: ميزانيّة شهريّة تُقارَن بإنفاق أسبوع تُطمئن كذباً. */
    public function test_spending_is_measured_over_the_calendar_month(): void
    {
        $this->costedRun(30.0);
        $this->costedRun(500.0, now()->subMonths(2)->toDateTimeString());

        $this->assertSame(30.0, AiOpsMetrics::spentThisMonth());
    }

    /** و«لا نعرف» ليست «صفراً»: بلا نداءٍ مسعَّر لا يُبنى إيقافٌ على جهل. */
    public function test_unknown_spending_is_null_and_never_triggers_a_stop(): void
    {
        Setting::put('ai_budget', json_encode(['cap' => 1, 'warnAt' => 0.8, 'stop' => true]));

        $this->assertNull(AiOpsMetrics::spentThisMonth());
        $this->assertFalse(AiOpsMetrics::budgetStopsCalls());
        $this->assertSame([], AiOpsMetrics::budgetAlerts());
    }
}
