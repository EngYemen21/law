<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\AiJudge;
use App\Services\Ai\AiModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * توجيه النماذج ونموذج الحكم المستقلّ (P6).
 *
 * كلاهما كان مؤجَّلاً باعتراضٍ صحيح على **القيمة**: نموذجٌ واحد لكل مزوّد مُهيَّأ فلا
 * شيء يُوجَّه بينه. والبنية هي ما ينقص — فبُنيت بحيث لا يتغيّر سلوكٌ حتى يقرّر المكتب.
 */
class AiModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    // ── الموجّه ──

    /** بلا تجاوز: النموذج المهيَّأ نفسه — **لا تغيير سلوك عند النشر**. */
    public function test_without_an_override_the_configured_model_is_used(): void
    {
        config(['services.gemini.model' => 'gemini-2.5-flash']);

        $this->assertSame('gemini-2.5-flash', AiModelRouter::modelFor('gemini', 'ticket.triage'));
        $this->assertSame('gemini-2.5-flash', AiModelRouter::modelFor('gemini'));
        $this->assertFalse(AiModelRouter::isRouted('gemini', 'ticket.triage'));
    }

    /** وبتجاوزٍ للمهمّة: يُنادى الموجَّه إليه، ولا يمسّ ذلك بقيّة المهام. */
    public function test_a_task_override_routes_only_that_task(): void
    {
        config(['services.gemini.model' => 'gemini-2.5-flash']);
        Setting::put('ai_model_overrides', json_encode([
            'gemini' => ['case.pleading' => 'gemini-2.5-pro'],
        ]));

        $this->assertSame('gemini-2.5-pro', AiModelRouter::modelFor('gemini', 'case.pleading'));
        $this->assertSame('gemini-2.5-flash', AiModelRouter::modelFor('gemini', 'ticket.triage'));
        $this->assertTrue(AiModelRouter::isRouted('gemini', 'case.pleading'));
    }

    /** وتجاوزٌ فارغ ليس تجاوزاً — يقع على المهيَّأ ولا يُنادى نموذجٌ بلا اسم. */
    public function test_a_blank_override_falls_back_to_the_configured_model(): void
    {
        config(['services.glm.model' => 'glm-5.2']);
        Setting::put('ai_model_overrides', json_encode(['glm' => ['ticket.triage' => '   ']]));

        $this->assertSame('glm-5.2', AiModelRouter::modelFor('glm', 'ticket.triage'));
        $this->assertFalse(AiModelRouter::isRouted('glm', 'ticket.triage'));
    }

    /**
     * التوجيه يُعرض في اللوحة: توجيهٌ لا يُرى يُنتج مخرجات بنموذجٍ يظنّ القارئ أنه
     * غيره، فيُنسب تراجع الجودة إلى التعليمة وهو من النموذج.
     */
    public function test_routing_is_visible_per_task_and_provider(): void
    {
        Setting::put('ai_model_overrides', json_encode(['gemini' => ['case.pleading' => 'gemini-2.5-pro']]));

        $rows = array_column(AiModelRouter::rows(), null, 'task');

        $this->assertTrue($rows['case.pleading']['models']['gemini']['routed']);
        $this->assertSame('gemini-2.5-pro', $rows['case.pleading']['models']['gemini']['model']);
        $this->assertFalse($rows['ticket.triage']['models']['gemini']['routed']);
    }

    // ── الحَكَم المستقلّ ──

    /**
     * **لا يحكم النموذج على نفسه.** حكمُ النموذج على مخرجه يميل إلى قبوله، فيصير
     * القياس تزكية. وبلا مزوّد آخر يُعاد `null` — «تعذّر حكمٌ مستقلّ» لا حكمٌ ذاتيّ.
     */
    public function test_a_model_is_never_allowed_to_judge_its_own_output(): void
    {
        config(['services.gemini.key' => 'k', 'services.glm.key' => null]);

        $this->assertNull(AiJudge::independentProvider('gemini'), 'لا مزوّد آخر متاح');
        $this->assertNull(AiJudge::assess('مخرج ما', 'gemini'), 'فلا حكم');
    }

    /** ومع مزوّدٍ ثانٍ: يحكم هو لا المنتج. */
    public function test_the_other_provider_is_chosen_as_judge(): void
    {
        config(['services.gemini.key' => 'k', 'services.glm.key' => 'k2']);

        $this->assertSame('glm', AiJudge::independentProvider('gemini'));
        $this->assertSame('gemini', AiJudge::independentProvider('glm'));

        $verdict = AiJudge::assess('مخرج ما', 'gemini', fn () => ['acceptable' => false, 'reason' => 'أضاف واقعة']);

        $this->assertSame('glm', $verdict['judge']);
        $this->assertFalse($verdict['acceptable']);
        $this->assertSame('أضاف واقعة', $verdict['reason']);
    }

    /** وحكمٌ بلا بنية صالحة **ليس حكماً** — ولا يُفسَّر رفضاً. */
    public function test_an_unparsable_verdict_is_no_verdict_rather_than_a_rejection(): void
    {
        config(['services.gemini.key' => 'k', 'services.glm.key' => 'k2']);

        $this->assertNull(AiJudge::assess('مخرج', 'gemini', fn () => ['reason' => 'بلا حكم']));
        $this->assertNull(AiJudge::assess('مخرج', 'gemini', fn () => ['acceptable' => 'نعم']));
        $this->assertNull(AiJudge::assess('مخرج', 'gemini', fn () => null));
    }
}
