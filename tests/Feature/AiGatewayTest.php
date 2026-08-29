<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\User;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiPromptRegistry;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * البوّابة الموحَّدة وطبقة التحقّق — نقطة الاختناق الوحيدة لنداء النماذج.
 *
 * قبلها كان `run()` يعيد `?string` مجرّداً: لا يُعرف أيّ مزوّد أجاب ولا كم استغرق
 * ولا لماذا فشل، فتُخمَّن هذه البيانات عند تسجيلها في `ai_runs`.
 */
class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(array $payload): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]]],
            ], 200),
        ]);
    }

    private function execution(): Execution
    {
        return Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EX-2026-'.uniqid(), 'sanad' => 'حكم قضائي', 'subject' => 'مطالبة',
            'amount' => 50000, 'defendant' => 'شركة المدى', 'stage' => 1,
        ]);
    }

    // ── توفّر المزوّد: مصدر حكم واحد ──

    public function test_gateway_and_service_agree_on_availability(): void
    {
        config(['services.gemini.key' => '', 'services.glm.key' => '']);
        $this->assertFalse(AiGateway::hasAvailableProvider());
        $this->assertFalse(app(LegalAiService::class)->available());

        config(['services.gemini.key' => 'k']);
        Cache::flush();
        $this->assertTrue(AiGateway::hasAvailableProvider());
        $this->assertTrue(app(LegalAiService::class)->available());
    }

    public function test_cooled_down_provider_is_skipped(): void
    {
        config(['services.gemini.key' => 'k', 'services.glm.key' => '']);
        Cache::put('ai:cooldown:gemini', true, 600);

        $this->assertFalse(AiGateway::hasAvailableProvider());
    }

    // ── الحصيلة تحمل بيانات تتبّع حقيقيّة ──

    public function test_successful_call_records_real_provider_metadata(): void
    {
        $this->fakeGemini(['summary' => 'سند مستوفٍ.', 'missing' => [], 'procedures' => ['تقديم طلب']]);
        $exec = $this->execution();

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertSame(AiSource::AiSuccess, $run->source);
        $this->assertSame(config('services.gemini.model'), $run->model, 'النموذج من المزوّد الذي أجاب فعلاً');
        $this->assertSame(AiPromptRegistry::version('execution.analyze'), $run->prompt_version);
        $this->assertNotNull($run->trace_id);
        $this->assertNotNull($run->duration_ms, 'زمن النداء يُقاس لا يُترك فارغاً');
        $this->assertNull($run->failure_code);
    }

    public function test_missing_provider_is_recorded_as_provider_unavailable(): void
    {
        config(['services.gemini.key' => '', 'services.glm.key' => '']);
        $exec = $this->execution();

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertSame(AiSource::Fallback, $run->source);
        $this->assertSame(AiFailure::PROVIDER_UNAVAILABLE, $run->failure_code);
        $this->assertNull($run->model, 'لا نموذج: لم يُستدعَ أحد');
    }

    /** ردّ ليس JSON ⇒ احتياطيّ موسوم بسبب بنيويّ، لا 500 ولا بيانات وهميّة. */
    public function test_non_json_response_falls_back_with_structural_failure_code(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'عذراً، لا أستطيع.']]]]],
            ], 200),
        ]);
        $exec = $this->execution();

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertSame(AiSource::Fallback, $run->source);
        $this->assertSame(AiFailure::INVALID_JSON, $run->failure_code);
        $this->assertFalse((bool) $exec->fresh()->ai_done);
    }

    /** JSON صالح بلا `summary` ⇒ فشل بنيويّ لا قبول جزئيّ. */
    public function test_json_missing_required_field_is_rejected(): void
    {
        $this->fakeGemini(['missing' => ['مستند'], 'procedures' => ['إجراء']]);
        $exec = $this->execution();

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertSame(AiSource::Fallback, $run->source);
        $this->assertSame(AiFailure::INVALID_STRUCTURE, $run->failure_code);
    }

    // ── المحقِّق: قيمة خارج المسموح تُسقَط ولا تُستبدَل بتخمين ──

    public function test_validator_drops_out_of_range_values_without_guessing(): void
    {
        $triage = AiOutputValidator::ticketTriage([
            'department' => 'القسم التجاري',
            'priority' => 'حرجة جداً',   // خارج المسموح
            'intent' => 'مزحة',          // خارج المسموح
        ]);

        $this->assertSame('القسم التجاري', $triage['department']);
        $this->assertSame('عادية', $triage['priority']);
        $this->assertSame('عادي', $triage['intent']);
    }

    public function test_validator_rejects_a_lawyer_outside_the_real_roster(): void
    {
        $roster = ['أ. سارة القحطاني', 'أ. خالد المالكي'];

        $withUnknown = AiOutputValidator::consultAnalysis([
            'class' => 'استشارة تجارية', 'summary' => 'ملخّص', 'lawyer' => 'أ. فلان الفلاني',
        ], $roster);
        $this->assertSame('', $withUnknown['lawyer'], 'اسم خارج القائمة يُسقَط ولا يُستبدَل بأوّل محامٍ');

        $withKnown = AiOutputValidator::consultAnalysis([
            'class' => 'استشارة تجارية', 'summary' => 'ملخّص', 'lawyer' => 'أ. خالد المالكي',
        ], $roster);
        $this->assertSame('أ. خالد المالكي', $withKnown['lawyer']);
    }

    public function test_validator_returns_null_when_required_fields_are_missing(): void
    {
        $this->assertNull(AiOutputValidator::ticketTriage(['priority' => 'عالية']));
        $this->assertNull(AiOutputValidator::executionAnalysis(['missing' => []]));
        $this->assertNull(AiOutputValidator::consultAnalysis(['class' => 'استشارة'], []));
        $this->assertNull(AiOutputValidator::ticketTriage(null));
    }

    // ── سجلّ الإصدارات ──

    public function test_every_recorded_prompt_id_has_a_version(): void
    {
        foreach (array_keys(AiPromptRegistry::PROMPTS) as $id) {
            $this->assertNotNull(AiPromptRegistry::version($id), "الـPrompt {$id} بلا إصدار");
        }
        $this->assertNull(AiPromptRegistry::version('غير.مسجَّل'), 'معرّف غير مسجَّل لا يُختلق له إصدار');
    }
}
