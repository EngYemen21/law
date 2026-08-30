<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiConfidence;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\AiDecision;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiPromptRegistry;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مجموعة التقييم — الطبقة الأولى: تحقّق آليّ من المخطّط والقيم والقواعد.
 *
 * الحالات في `tests/Fixtures/ai/*.json`: مُصطنعة بالكامل ومجهّلة (لا بيانات عملاء
 * ولا أسماء حقيقية)، ولكلٍّ منها مستوى صعوبة ونوع خطأ ونتيجة مرجعيّة.
 *
 * **حدّ هذه الطبقة صراحةً:** تقيس سلوك طبقاتنا (المحقِّق · المقياس · البوّابة) أمام
 * مخرجات نموذج مثبَّتة، لا جودة النموذج نفسه. الطبقتان الثانية (مقارنة مخرجات حيّة
 * بمراجع) والثالثة (مراجعة محامٍ لعينة عمياء) تحتاجان مزوّداً حيّاً وعملاً بشرياً،
 * وهما خارج ما تستطيع حزمة اختبارات إثباته.
 *
 * بوّابات العبور من الخطة: الفرز ≥90% · تحليل المستند ≥90% · العدائيّة **100%**.
 */
class AiEvaluationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/ai/{$name}.json");
        $this->assertFileExists($path, "ملفّ الحالات «{$name}» مفقود");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** يحوّل توقّع الحالة إلى قرار البوّابة المقابل. */
    private function decisionOf(string $task, ?array $valid, ?int $confidence): AiDecision
    {
        return AiPolicyGate::decide(
            taskType: $task,
            source: $valid === null ? AiSource::Fallback : AiSource::AiSuccess,
            confidence: $confidence,
            hasUsableOutput: true,
        );
    }

    // ── فرز التذكرة ──

    public function test_triage_fixtures_meet_the_transition_gate(): void
    {
        $set = $this->fixture('triage');
        $passed = 0;

        foreach ($set['cases'] as $case) {
            $valid = AiOutputValidator::ticketTriage($case['model_output']);
            $expect = $case['expect'];
            $id = $case['id'];

            $this->assertSame($expect['valid'], $valid !== null, "[{$id}] صلاحية المخرج");
            if (! $expect['valid']) {
                $passed++;

                continue;
            }

            foreach (['department', 'priority', 'intent'] as $field) {
                if (isset($expect[$field])) {
                    $this->assertSame($expect[$field], $valid[$field], "[{$id}] الحقل {$field}");
                }
            }

            $score = AiConfidence::forTicketTriage(
                department: $valid['department'],
                details: $case['input']['details'],
                clientChosenDepartment: $case['input']['client_department'],
                ticketType: $case['input']['ticket_type'],
            )['score'];

            if (isset($expect['confidence_min'])) {
                $this->assertGreaterThanOrEqual($expect['confidence_min'], $score, "[{$id}] حدّ الثقة الأدنى");
            }
            if (isset($expect['confidence_max'])) {
                $this->assertLessThanOrEqual($expect['confidence_max'], $score, "[{$id}] حدّ الثقة الأعلى");
            }

            $this->assertSame(
                $expect['decision'],
                $this->decisionOf($set['task'], $valid, $score)->value,
                "[{$id}] قرار البوّابة"
            );
            $passed++;
        }

        $rate = $passed / count($set['cases']);
        $this->assertGreaterThanOrEqual(0.90, $rate, 'بوّابة الفرز: ≥90%');
    }

    // ── تحليل الاستشارة ──

    public function test_consult_fixtures_meet_the_transition_gate(): void
    {
        $set = $this->fixture('consult');
        $passed = 0;

        foreach ($set['cases'] as $case) {
            $valid = AiOutputValidator::consultAnalysis($case['model_output'], $set['roster']);
            $expect = $case['expect'];
            $id = $case['id'];

            $this->assertSame($expect['valid'], $valid !== null, "[{$id}] صلاحية المخرج");
            if (! $expect['valid']) {
                $passed++;

                continue;
            }

            if (isset($expect['lawyer'])) {
                $this->assertSame($expect['lawyer'], $valid['lawyer'], "[{$id}] المحامي المقترح");
            }

            $score = AiConfidence::forConsult(
                class: $valid['class'],
                summary: $valid['summary'],
                lawyer: $valid['lawyer'],
                roster: $set['roster'],
                hasSubject: $case['input']['has_subject'],
            )['score'];

            if (isset($expect['confidence_min'])) {
                $this->assertGreaterThanOrEqual($expect['confidence_min'], $score, "[{$id}] حدّ الثقة الأدنى");
            }
            if (isset($expect['confidence_max'])) {
                $this->assertLessThanOrEqual($expect['confidence_max'], $score, "[{$id}] حدّ الثقة الأعلى");
            }

            $this->assertSame($expect['decision'], $this->decisionOf($set['task'], $valid, $score)->value, "[{$id}] القرار");
            $passed++;
        }

        $this->assertGreaterThanOrEqual(0.90, $passed / count($set['cases']), 'بوّابة الاستشارة: ≥90%');
    }

    // ── تحليل التنفيذ ──

    public function test_execution_fixtures_meet_the_transition_gate(): void
    {
        $set = $this->fixture('execution');
        $passed = 0;

        foreach ($set['cases'] as $case) {
            $valid = AiOutputValidator::executionAnalysis($case['model_output'], ['إجراء افتراضيّ']);
            $expect = $case['expect'];
            $id = $case['id'];

            $this->assertSame($expect['valid'], $valid !== null, "[{$id}] صلاحية المخرج");
            if (! $expect['valid']) {
                $passed++;

                continue;
            }

            $score = AiConfidence::forExecution(
                summary: $valid['summary'],
                documentsTotal: $case['input']['documents_total'],
                documentsReadable: $case['input']['documents_readable'],
                hasDefendant: $case['input']['has_defendant'],
                hasSanad: $case['input']['has_sanad'],
                hasAmount: $case['input']['has_amount'],
            )['score'];

            if (isset($expect['confidence_min'])) {
                $this->assertGreaterThanOrEqual($expect['confidence_min'], $score, "[{$id}] حدّ الثقة الأدنى");
            }
            if (isset($expect['confidence_max'])) {
                $this->assertLessThanOrEqual($expect['confidence_max'], $score, "[{$id}] حدّ الثقة الأعلى");
            }

            $this->assertSame($expect['decision'], $this->decisionOf($set['task'], $valid, $score)->value, "[{$id}] القرار");
            $passed++;
        }

        $this->assertGreaterThanOrEqual(0.90, $passed / count($set['cases']), 'بوّابة التنفيذ: ≥90%');
    }

    // ── العدائيّة: 100% أو لا شيء ──

    /**
     * الضمانة القابلة للإثبات: **محتوى المستخدم لا يصير تعليمةَ نظام أبداً**.
     * التعليمة تُبنى من السجلّ وحده، فمهما حُقن في رسالة أو مستند تبقى مطابقة
     * لبصمتها المجمَّدة — وهو ما يُفحص هنا على الطلب الخارج فعلاً لا في الذاكرة.
     */
    public function test_injection_never_alters_the_system_instruction(): void
    {
        $set = $this->fixture('adversarial');
        $frozen = AiPromptRegistry::ticketSummarySystem();

        foreach ($set['injection_cases'] as $case) {
            config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
            Cache::flush();
            Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"case_summary":"م","attachments_summary":"م","facts":"و","key_points":"ن"}']]]]],
            ], 200)]);

            $ticket = $this->ticketWithClientMessage($case['payload']);
            app(LegalAiService::class)->summarize($ticket);

            $body = json_decode((string) json_encode(Http::recorded()[0][0]->data()), true);
            $sentSystem = $body['system_instruction']['parts'][0]['text'] ?? '';
            $sentUser = (string) json_encode($body['contents'] ?? [], JSON_UNESCAPED_UNICODE);

            $this->assertSame($frozen, $sentSystem, "[{$case['id']}] الحقن غيّر تعليمة النظام");

            if ($case['must_strip_script'] ?? false) {
                $this->assertStringNotContainsString('<script', $sentUser, "[{$case['id']}] وسم script وصل النموذج");
                $this->assertStringNotContainsString('document.cookie', $sentUser, "[{$case['id']}] جسم script وصل النموذج");
            }
        }
    }

    public function test_no_client_identifier_leaks_in_any_adversarial_case(): void
    {
        $set = $this->fixture('adversarial');

        foreach ($set['leak_cases'] as $case) {
            $prepared = AiContextBuilder::prepare($case['payload']);

            foreach ($case['must_not_appear'] as $secret) {
                $this->assertStringNotContainsString($secret, $prepared, "[{$case['id']}] تسرّب: {$secret}");
            }
        }
    }

    // ── اكتمال المجموعة نفسها ──

    /** المجموعة تفقد قيمتها إن خلت من الحالات الصعبة أو العدائيّة. */
    public function test_the_fixture_set_covers_the_required_difficulty_spectrum(): void
    {
        foreach (['triage', 'consult', 'execution', 'document', 'summary', 'decisions', 'pleading'] as $name) {
            $cases = $this->fixture($name)['cases'];
            $difficulties = array_unique(array_column($cases, 'difficulty'));

            $this->assertContains('صعبة', $difficulties, "«{$name}» بلا حالة صعبة — مجموعة متساهلة لا تقيس شيئاً");
            $this->assertGreaterThanOrEqual(
                1,
                count(array_filter($cases, fn ($c) => ($c['error_type'] ?? null) !== null)),
                "«{$name}» بلا حالة خطأ"
            );
            foreach ($cases as $case) {
                $this->assertArrayHasKey('difficulty', $case, "[{$case['id']}] بلا مستوى صعوبة");
                $this->assertArrayHasKey('expect', $case, "[{$case['id']}] بلا نتيجة مرجعيّة");
            }
        }
    }

    private function ticketWithClientMessage(string $body): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-'.uniqid(),
            'type' => 'نزاع تجاري', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);
        $ticket->messages()->create([
            'who' => 'client', 'name' => 'العميل', 'role' => 'العميل',
            'body' => $body, 'time_label' => 'الآن',
        ]);

        return $ticket;
    }
}
