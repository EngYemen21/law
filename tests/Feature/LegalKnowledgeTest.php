<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\LegalClaims;
use App\Services\Ai\LegalKnowledge;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قاعدة المعرفة القانونيّة: الاستشهاد قابلٌ للتحقّق خادمياً أو لا يكون.
 *
 * كل المصادر هنا **مُصطنعة بالكامل** بأسماء أنظمة وهميّة — لا نصّ نظاميّ سعوديّ
 * حقيقيّ في الاختبارات ولا في الشيفرة. المحتوى الحقيقيّ يُغذّيه الفريق القانونيّ
 * ببياناته الحاكمة (المالك، السريان، الإصدار، الولاية، نطاق الاستعمال).
 */
class LegalKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function source(array $overrides = []): LegalSource
    {
        return LegalSource::create(array_merge([
            'ref' => 'LS-'.uniqid(),
            'system_name' => 'نظام تجريبيّ للاختبار',
            'article_no' => '12',
            'title' => 'التزامات المورّد',
            'text' => 'يلتزم المورّد بتسليم البضاعة في الموعد المتفق عليه وفق شروط العقد.',
            'jurisdiction' => 'السعودية',
            'domain' => 'تجاري',
            'version' => '1',
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'source_owner' => 'الفريق القانونيّ للمكتب',
            'legal_review_at' => '2026-01-01',
            'usage_scope' => 'مسودات داخليّة',
            'status' => LegalSource::STATUS_APPROVED,
        ], $overrides));
    }

    // ── الفلاتر الإلزاميّة: قبل النموذج لا بعده ──

    public function test_only_approved_sources_are_retrievable(): void
    {
        $this->source(['status' => LegalSource::STATUS_APPROVED, 'ref' => 'LS-OK']);
        $this->source(['status' => LegalSource::STATUS_DRAFT, 'ref' => 'LS-DRAFT']);
        $this->source(['status' => LegalSource::STATUS_SUSPENDED, 'ref' => 'LS-STOP']);

        $refs = LegalKnowledge::retrieve('تجاري')->pluck('ref')->all();

        $this->assertSame(['LS-OK'], $refs, 'المسودّة والموقوف لا يُستشهد بهما مهما طابقا الموضوع');
    }

    /** نصٌّ نُسخ قبل الواقعة لا يحكمها — التاريخ المرجعيّ تاريخ الواقعة لا اليوم. */
    public function test_a_repealed_source_is_excluded_at_the_event_date(): void
    {
        $this->source(['ref' => 'LS-LIVE', 'effective_from' => '2019-01-01', 'effective_to' => null]);
        $this->source(['ref' => 'LS-DEAD', 'effective_from' => '2015-01-01', 'effective_to' => '2018-12-31']);

        $refs = LegalKnowledge::retrieve('تجاري', asOf: new \DateTimeImmutable('2022-06-01'))->pluck('ref')->all();

        $this->assertSame(['LS-LIVE'], $refs);
    }

    public function test_a_source_not_yet_in_force_is_excluded(): void
    {
        $this->source(['ref' => 'LS-FUTURE', 'effective_from' => '2030-01-01']);

        $this->assertCount(0, LegalKnowledge::retrieve('تجاري', asOf: new \DateTimeImmutable('2026-01-01')));
    }

    public function test_other_jurisdictions_are_excluded(): void
    {
        $this->source(['ref' => 'LS-SA', 'jurisdiction' => 'السعودية']);
        $this->source(['ref' => 'LS-OTHER', 'jurisdiction' => 'الإمارات']);

        $this->assertSame(['LS-SA'], LegalKnowledge::retrieve('تجاري')->pluck('ref')->all());
    }

    public function test_domain_filters_out_unrelated_areas(): void
    {
        $this->source(['ref' => 'LS-COM', 'domain' => 'تجاري']);
        $this->source(['ref' => 'LS-LABOR', 'domain' => 'عمالي']);

        $this->assertSame(['LS-COM'], LegalKnowledge::retrieve('تجاري')->pluck('ref')->all());
    }

    // ── السند الكافي ──

    // ── الترجيح: ما ظهر حين امتلأت القاعدة فعلاً ──

    /**
     * أداة التعريف كانت تكسر المطابقة: `LIKE '%الشفعة%'` لا يطابق «لا شفعة في
     * الحالات الآتية». وقع ذلك على القاعدة الحقيقيّة: سؤالٌ عن الشفعة أعاد موادّ
     * حصّة الشريك في الشركة، لأن «الحصة» طابقت و«الشفعة» لم تطابق موادَّها.
     */
    public function test_a_defined_term_matches_its_undefined_form_in_the_text(): void
    {
        $hit = $this->source(['ref' => 'LS-SHUFA', 'domain' => null, 'title' => null,
            'text' => 'لا شفعة في الحالات الآتية: إذا كان انتقال الملك بغير البيع.']);
        $this->source(['ref' => 'LS-OTHER', 'domain' => null, 'title' => null,
            'text' => 'تتحدد حصة كل شريك بالحصة التي التزم بها في عقد الشركة.']);

        $refs = LegalKnowledge::retrieve('العقارات', 'الشفعة في بيع الحصة')->pluck('ref')->all();

        $this->assertSame($hit->ref, $refs[0] ?? null, 'المادّة الحاكمة تسبق ما طابق كلمةً شائعة');
    }

    /**
     * الوزن بطول الكلمة لا بعددها: الجذر الثلاثيّ يقع صدفةً داخل كلمة أخرى
     * («بيع» داخل «الطبيعية»)، فمصادفةٌ كهذه يجب ألّا تسبق مطابقةً حقيقيّة.
     */
    public function test_an_accidental_substring_match_loses_to_a_real_term(): void
    {
        $this->source(['ref' => 'LS-ACCIDENT', 'domain' => null, 'title' => null,
            'text' => 'تبدأ شخصية الإنسان الطبيعية بتمام ولادته حيّاً.']);
        $real = $this->source(['ref' => 'LS-REAL', 'domain' => null, 'title' => null,
            'text' => 'تثبت الشفعة بتمام البيع مع قيام السبب الموجب لها.']);

        $refs = LegalKnowledge::retrieve('العقارات', 'الشفعة في بيع العقار')->pluck('ref')->all();

        $this->assertSame($real->ref, $refs[0] ?? null);
    }

    /** والترتيب ثابت: التشغيل نفسه يعطي النتيجة نفسها، فتُقارَن المخرجات بين النسخ. */
    public function test_ranking_is_deterministic_across_runs(): void
    {
        foreach (range(1, 12) as $i) {
            $this->source(['ref' => "LS-D-{$i}", 'domain' => null, 'title' => null,
                'text' => 'يلتزم المورّد بتسليم البضاعة وفق شروط العقد.']);
        }

        $first = LegalKnowledge::retrieve('تجاري', 'تسليم البضاعة')->pluck('ref')->all();
        $second = LegalKnowledge::retrieve('تجاري', 'تسليم البضاعة')->pluck('ref')->all();

        $this->assertSame($first, $second);
    }

    /**
     * بلا تطابق: **صمتٌ** لا نظامٌ عامّ بلا صلة.
     *
     * كان الرجوع يعيد أيّ مصدر في المجال. سقطت مقدّمة ذلك حين امتلأت القاعدة: نظامٌ
     * عامّ (`domain = null`) يحكم كل المجالات، فكانت تُعاد منه موادُّ **الشُّفعة**
     * لاستعلامٍ عن حجزٍ تنفيذيّ — والموادّ نفسها لكل مجال. وتمريرها بوصفها «مصادر
     * معتمدة استشهد بها حصراً» أسوأ من الصمت.
     */
    public function test_no_keyword_match_yields_silence_not_an_unrelated_general_law(): void
    {
        $this->source(['ref' => 'LS-GENERAL', 'domain' => null, 'title' => null,
            'text' => 'الشفعة حق الشريك في أن يتملّك العقار المبيع بالثمن الذي بيع به.']);

        $found = LegalKnowledge::retrieve('التأمين', 'تعويض حادث مركبة');

        $this->assertTrue($found->isEmpty(), 'نظامٌ عامّ بلا صلة لا يُمرَّر كسند');
        $this->assertFalse(LegalKnowledge::hasSufficientAuthority($found));
    }

    /** أما المخصَّص للمجال صراحةً فيبقى مقبولاً بلا تطابق: صلتُه بالبناء لا بالكلمة. */
    public function test_a_domain_specific_source_still_backs_a_query_without_keyword_hits(): void
    {
        $this->source(['ref' => 'LS-INSURANCE', 'domain' => 'التأمين', 'title' => null,
            'text' => 'تسري أحكام وثيقة التأمين على ما اتفق عليه الطرفان.']);

        $found = LegalKnowledge::retrieve('التأمين', 'مطالبة لا تطابق شيئاً إطلاقاً');

        $this->assertSame(['LS-INSURANCE'], $found->pluck('ref')->all());
    }

    public function test_empty_corpus_means_insufficient_authority_not_invention(): void
    {
        $sources = LegalKnowledge::retrieve('تجاري');

        $this->assertFalse(LegalKnowledge::hasSufficientAuthority($sources));
        $this->assertSame('', LegalKnowledge::asContext($sources));

        $result = LegalClaims::insufficientAuthority();
        $this->assertSame(LegalClaims::INSUFFICIENT_AUTHORITY, $result['verdict']);
        $this->assertSame('', $result['draft'], 'لا مسودّة تُصاغ بلا سند');
    }

    public function test_context_carries_the_reference_id_for_each_passage(): void
    {
        $this->source(['ref' => 'LS-A', 'article_no' => '7', 'system_name' => 'نظام تجريبيّ أوّل']);

        $context = LegalKnowledge::asContext(LegalKnowledge::retrieve('تجاري'));

        $this->assertStringContainsString('[LS-A]', $context, 'المقطع يُمرَّر بمعرّفه ليُستشهد به');
        $this->assertStringContainsString('المادة 7', $context);
    }

    // ── جوهر الضمانة: النموذج لا يُصدَّق في رقم مادّة ──

    public function test_a_claim_citing_an_unknown_source_is_demoted_to_unsupported(): void
    {
        $this->source(['ref' => 'LS-REAL']);
        $existing = LegalKnowledge::existingRefs(['LS-REAL', 'LS-INVENTED']);

        $result = LegalClaims::validate([
            'draft' => 'مسودّة لائحة الدعوى.',
            'claims' => [
                ['text' => 'يلتزم المورّد بالتسليم في الموعد.', 'source_id' => 'LS-REAL', 'source_excerpt' => 'يلتزم المورّد…'],
                ['text' => 'يستحق المدّعي تعويضاً بنسبة 30% نظاماً.', 'source_id' => 'LS-INVENTED', 'source_excerpt' => 'نصّ متخيَّل'],
            ],
            'unsupported_claims' => [],
        ], $existing);

        $this->assertCount(1, $result['claims'], 'الادّعاء المستشهد بمصدر غير موجود لا يُعدّ مسنَداً');
        $this->assertSame('LS-REAL', $result['claims'][0]['source_id']);
        $this->assertContains('يستحق المدّعي تعويضاً بنسبة 30% نظاماً.', $result['unsupported_claims']);
    }

    /** ادّعاء واحد غير مدعوم يُسقط الحكم كلّه — لا وثيقة «أغلبها صحيح». */
    public function test_one_unsupported_claim_taints_the_whole_verdict(): void
    {
        $this->source(['ref' => 'LS-1']);
        $existing = LegalKnowledge::existingRefs(['LS-1']);

        $clean = LegalClaims::validate([
            'draft' => 'مسودّة.',
            'claims' => [['text' => 'ادّعاء مسنَد.', 'source_id' => 'LS-1', 'source_excerpt' => 'نصّ']],
            'unsupported_claims' => [],
        ], $existing);

        $tainted = LegalClaims::validate([
            'draft' => 'مسودّة.',
            'claims' => [['text' => 'ادّعاء مسنَد.', 'source_id' => 'LS-1', 'source_excerpt' => 'نصّ']],
            'unsupported_claims' => ['ادّعاء بلا سند.'],
        ], $existing);

        $this->assertSame(LegalClaims::SUPPORTED, $clean['verdict']);
        $this->assertTrue(LegalClaims::isCitable($clean));

        $this->assertSame(LegalClaims::UNSUPPORTED, $tainted['verdict']);
        $this->assertFalse(LegalClaims::isCitable($tainted), 'مسودّة فيها ادّعاء ملفَّق لا تُقدَّم');
    }

    public function test_claims_without_any_source_are_never_citable(): void
    {
        $result = LegalClaims::validate([
            'draft' => 'مسودّة مطوّلة بلا استشهاد واحد.',
            'claims' => [['text' => 'ادّعاء نظاميّ.', 'source_id' => '', 'source_excerpt' => '']],
            'unsupported_claims' => [],
        ], []);

        $this->assertSame([], $result['claims']);
        $this->assertSame(LegalClaims::UNSUPPORTED, $result['verdict']);
        $this->assertFalse(LegalClaims::isCitable($result));
    }

    public function test_a_draftless_output_is_rejected_outright(): void
    {
        $this->assertNull(LegalClaims::validate(['claims' => [], 'unsupported_claims' => []], []));
        $this->assertNull(LegalClaims::validate(null, []));
    }

    // ── الربط: يُضيف ولا يَحجب ──

    /**
     * قاعدة فارغة ⇒ المسودّة تُنتَج كما تُنتَج اليوم. لو حجبنا الصياغة على وجود
     * مصادر، لتوقّف كل إنتاج المسودات فور نشر الميزة — انحدارٌ في وظيفة عاملة.
     */
    public function test_an_empty_corpus_does_not_stop_pleading_drafts(): void
    {
        [$case] = $this->caseWithFakedProvider();

        $draft = app(LegalAiService::class)->draftPleading($case);

        $this->assertNotSame('', trim($draft), 'المسودّة تبقى تعمل بلا مصادر');
        $sent = $this->lastRequestText();
        $this->assertStringNotContainsString('مصادر نظاميّة معتمدة', $sent, 'لا ترويسة سند بلا سند');
    }

    /** وجود مصادر ⇒ تُمرَّر بمعرّفاتها مع منعٍ صريح عن الاستشهاد خارجها. */
    public function test_approved_sources_are_passed_to_the_model_with_their_refs(): void
    {
        $this->source(['ref' => 'LS-CITE', 'domain' => 'تجاري', 'system_name' => 'نظام تجريبيّ للاختبار']);
        [$case] = $this->caseWithFakedProvider(department: 'تجاري');

        app(LegalAiService::class)->draftPleading($case);

        $sent = $this->lastRequestText();
        $this->assertStringContainsString('مصادر نظاميّة معتمدة', $sent);
        $this->assertStringContainsString('[LS-CITE]', $sent, 'المقطع يصل بمعرّفه');
        $this->assertStringContainsString('استشهد بمعرّفاتها حصراً', $sent, 'المنع عن الاستشهاد خارج القائمة صريح');
    }

    /** المصدر غير المعتمد لا يُمرَّر أصلاً — الفلترة قبل النموذج لا بعده. */
    public function test_unapproved_sources_never_reach_the_model(): void
    {
        $this->source(['ref' => 'LS-DRAFT-ONLY', 'status' => LegalSource::STATUS_DRAFT, 'domain' => 'تجاري']);
        [$case] = $this->caseWithFakedProvider(department: 'تجاري');

        app(LegalAiService::class)->draftPleading($case);

        $this->assertStringNotContainsString('LS-DRAFT-ONLY', $this->lastRequestText());
    }

    /** @return array{0:LegalCase} */
    private function caseWithFakedProvider(string $department = 'تجاري'): array
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'مسودّة لائحة دعوى تجريبيّة.']]]]],
        ], 200)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => $department, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        return [$case];
    }

    private function lastRequestText(): string
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'لم يُرسَل طلب — الاختبار بلا معنى');

        return (string) json_encode($recorded[0][0]->data(), JSON_UNESCAPED_UNICODE);
    }

    /** المصدر غير المعتمد لا يصلح سنداً حتى لو استشهد به النموذج بمعرّفه الصحيح. */
    public function test_a_draft_source_cannot_back_a_claim(): void
    {
        $this->source(['ref' => 'LS-PENDING', 'status' => LegalSource::STATUS_DRAFT]);

        $this->assertSame([], LegalKnowledge::existingRefs(['LS-PENDING']));

        $result = LegalClaims::validate([
            'draft' => 'مسودّة.',
            'claims' => [['text' => 'ادّعاء.', 'source_id' => 'LS-PENDING', 'source_excerpt' => 'نصّ']],
            'unsupported_claims' => [],
        ], LegalKnowledge::existingRefs(['LS-PENDING']));

        $this->assertSame([], $result['claims']);
        $this->assertContains('ادّعاء.', $result['unsupported_claims']);
    }
}
