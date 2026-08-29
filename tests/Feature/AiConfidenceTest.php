<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\User;
use App\Services\Ai\AiConfidence;
use App\Services\Ai\AiPromptRegistry;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الثقة مشتقّة خادمياً من إشارات موضوعيّة — لا من ادّعاء النموذج عن مخرجه.
 *
 * الخيار المرفوض كان أن يُصرّح النموذج بثقته ضمن الـJSON: تلك ثقةٌ يعلنها المُنتِج
 * عن إنتاجه، أي ادّعاء لا قياس — وهو صنف البيانات غير المحقّقة الذي أُزيل في P0/P1.
 */
class AiConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private const ROSTER = ['أ. سارة القحطاني', 'أ. خالد المالكي'];

    // ── القاعدة الحاكمة: null ≠ ثقة منخفضة ──

    public function test_fallback_has_no_confidence_at_all(): void
    {
        config(['services.gemini.key' => '', 'services.glm.key' => '']);
        $exec = Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EX-2026-'.uniqid(), 'sanad' => 'شيك', 'subject' => 'تحصيل',
            'amount' => 50000, 'defendant' => 'مؤسسة الرمال', 'stage' => 1,
        ]);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertNull($run->confidence, 'لا قياس حين لا تحليل — وليست ثقة صفريّة');
        $this->assertNull($run->confidence_signals);
    }

    public function test_successful_analysis_records_score_with_its_signals(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'summary' => str_repeat('سند تنفيذيّ مستوفٍ للشروط بعد فحص المرفقات والتأكد من صحتها. ', 4),
                'missing' => [],
                'procedures' => ['تقديم طلب تنفيذ إلكتروني'],
            ], JSON_UNESCAPED_UNICODE)]]]]],
        ], 200)]);

        $exec = Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EX-2026-'.uniqid(), 'sanad' => 'حكم قضائي', 'subject' => 'مطالبة',
            'amount' => 85000, 'defendant' => 'مؤسسة الرمال', 'stage' => 1,
        ]);

        ExecService::applyAnalysis($exec, app(LegalAiService::class)->analyzeExecution($exec));

        $run = AiRun::where('task_type', 'execution')->latest('id')->firstOrFail();
        $this->assertNotNull($run->confidence);
        $this->assertGreaterThanOrEqual(0, $run->confidence);
        $this->assertLessThanOrEqual(100, $run->confidence);

        // الدرجة بلا أساسها غير قابلة للتدقيق — الإشارات تُخزَّن معها
        $this->assertIsArray($run->confidence_signals);
        $this->assertArrayHasKey('documents_attached', $run->confidence_signals);
        $this->assertFalse($run->confidence_signals['documents_attached'], 'لا مستندات مرفقة في هذا الطلب');
        $this->assertTrue($run->confidence_signals['defendant_present']);
    }

    // ── التنفيذ: المستندات المقروءة فعلاً هي الإشارة الأثقل ──

    public function test_execution_score_rises_with_readable_documents(): void
    {
        $args = ['summary' => str_repeat('ملخّص قانونيّ مفصّل. ', 20), 'hasDefendant' => true, 'hasSanad' => true, 'hasAmount' => true];

        $none = AiConfidence::forExecution(...$args + ['documentsTotal' => 0, 'documentsReadable' => 0]);
        $half = AiConfidence::forExecution(...$args + ['documentsTotal' => 4, 'documentsReadable' => 2]);
        $all = AiConfidence::forExecution(...$args + ['documentsTotal' => 4, 'documentsReadable' => 4]);

        $this->assertLessThan($half['score'], $none['score']);
        $this->assertLessThan($all['score'], $half['score']);
        $this->assertSame(0.5, $half['signals']['readable_ratio']);
    }

    public function test_execution_with_nothing_readable_scores_low(): void
    {
        // أربعة مرفقات لم يُقرأ منها شيء: التحليل جرى على بيانات النموذج وحدها
        $result = AiConfidence::forExecution(
            summary: 'ملخّص قصير.', documentsTotal: 4, documentsReadable: 0,
            hasDefendant: false, hasSanad: false, hasAmount: false,
        );

        $this->assertLessThan(30, $result['score']);
        $this->assertSame(0.0, $result['signals']['readable_ratio']);
    }

    // ── الفرز: القسم من القاموس المعتمد ──

    public function test_triage_department_outside_the_catalogue_lowers_the_score(): void
    {
        $inside = AiConfidence::forTicketTriage('القضايا التجارية', str_repeat('تفاصيل النزاع. ', 20), '', 'نزاع تجاري');
        $outside = AiConfidence::forTicketTriage('قسم مخترَع', str_repeat('تفاصيل النزاع. ', 20), '', 'نزاع تجاري');

        $this->assertTrue($inside['signals']['department_in_catalogue']);
        $this->assertFalse($outside['signals']['department_in_catalogue']);
        $this->assertSame(35, $inside['score'] - $outside['score'], 'وزن الإشارة معلن لا خفيّ');
    }

    public function test_triage_thin_details_lower_the_score(): void
    {
        $rich = AiConfidence::forTicketTriage('التنفيذ', str_repeat('وصف مفصّل للطلب. ', 20), '', 'تنفيذ');
        $thin = AiConfidence::forTicketTriage('التنفيذ', 'مشكلة', '', 'تنفيذ');

        $this->assertTrue($rich['signals']['details_substantial']);
        $this->assertFalse($thin['signals']['details_substantial']);
        $this->assertLessThan($rich['score'], $thin['score']);
    }

    /** حين لا يختار العميل قسماً لا يُعاقَب الفرز على غياب الاتفاق. */
    public function test_triage_is_not_penalised_when_the_client_chose_nothing(): void
    {
        $details = str_repeat('وصف مفصّل. ', 20);
        $noChoice = AiConfidence::forTicketTriage('التنفيذ', $details, '', 'تنفيذ');
        $disagrees = AiConfidence::forTicketTriage('التنفيذ', $details, 'العقود والاتفاقيات', 'تنفيذ');

        $this->assertFalse($noChoice['signals']['client_chose_department']);
        $this->assertGreaterThan($disagrees['score'], $noChoice['score']);
    }

    // ── الاستشارة: المحامي من القائمة الحقيقيّة ──

    public function test_consult_lawyer_outside_the_roster_lowers_the_score(): void
    {
        $summary = 'الوقائع: نزاع. التكييف القانوني: عقديّ. الرأي: التفاوض. '.str_repeat('تفصيل. ', 40);

        $known = AiConfidence::forConsult('استشارة تجارية', $summary, 'أ. خالد المالكي', self::ROSTER, true);
        $unknown = AiConfidence::forConsult('استشارة تجارية', $summary, '', self::ROSTER, true);

        $this->assertTrue($known['signals']['lawyer_from_roster']);
        $this->assertFalse($unknown['signals']['lawyer_from_roster']);
        $this->assertSame(30, $known['score'] - $unknown['score']);
    }

    public function test_consult_score_reflects_the_required_sections(): void
    {
        $withAll = AiConfidence::forConsult('استشارة عمالية', 'الوقائع… التكييف… الرأي… '.str_repeat('ت. ', 80), 'أ. سارة القحطاني', self::ROSTER, true);
        $withNone = AiConfidence::forConsult('استشارة عمالية', str_repeat('نصّ بلا أقسام. ', 20), 'أ. سارة القحطاني', self::ROSTER, true);

        $this->assertSame(3, $withAll['signals']['summary_sections_found']);
        $this->assertSame(0, $withNone['signals']['summary_sections_found']);
        $this->assertGreaterThan($withNone['score'], $withAll['score']);
    }

    // ── حدود المقياس ──

    public function test_scores_stay_within_bounds(): void
    {
        $best = AiConfidence::forExecution(str_repeat('ملخّص. ', 50), 3, 3, true, true, true);
        $worst = AiConfidence::forExecution('', 0, 0, false, false, false);

        $this->assertSame(100, $best['score']);
        $this->assertSame(0, $worst['score']);
    }

    /** القاموس مصدره السجلّ لا نسخة ثانية في المقياس. */
    public function test_catalogue_signal_reads_from_the_registry(): void
    {
        $first = trim(explode('،', AiPromptRegistry::DEPARTMENTS)[0]);

        $this->assertTrue(AiConfidence::forTicketTriage($first, 'تفاصيل', '', 'نوع')['signals']['department_in_catalogue']);
    }
}
