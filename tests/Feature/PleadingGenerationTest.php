<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\CaseStatusBroadcast;
use App\Jobs\DraftCasePleadingJob;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * **توليد اللائحة يصل المحرّر نصّاً لا JSON خاماً.** (قيسَ في المتصفّح 2026-09-11)
 *
 * «إعادة التوليد» كانت تعمل — ثلاث نقرات أنتجت ثلاث مسودّات — لكن كلّاً منها JSON خامٌ
 * مبتور: `{"draft": "بسم الله…", … "source_excerpt": "…فللم` بلا إغلاق. سقف المخرج
 * (`max_tokens: 2048`) أصغر من لائحةٍ عربيّة بأسانيدها، فيُبتر المخرج ويفشل `json_decode`،
 * ويُحفظ النصّ الخام كما هو في المحرّر وفي المحادثة. وحقلُ `draft` نفسه كان كاملاً.
 *
 * ولم يكن المحرّر يعلم أنّ المسودّة جهزت: التوليد بالطابور، والمحجوبُ لا يُبثّ.
 */
class PleadingGenerationTest extends TestCase
{
    use RefreshDatabase;

    /** المخرج كما قيسَ فعلاً: JSON يُبتر داخل حقلٍ لاحق بعد اكتمال `draft`. */
    private const TRUNCATED = "{\n \"draft\": \"بسم الله الرحمن الرحيم\\n\\nإلى أصحاب الفضيلة قضاة الدائرة التجارية\\n\\nالطلبات: إلزام المدّعى عليه.\",\n \"claims\": [{\"text\": \"x\", \"source_id\": \"LS-CIVIL-635\", \"source_excerpt\": \"يجب في المهايأة المكانية تعيين محل انتفاع كل شريك، فإذا اختلف الشركاء في ذلك فللم";

    public function test_a_truncated_json_still_yields_the_complete_draft_text(): void
    {
        $salvaged = LegalAiService::salvageDraft(self::TRUNCATED);

        $this->assertNotNull($salvaged);
        $this->assertTrue($salvaged['complete'], 'حقل draft نفسه مكتمل — البتر وقع بعده');
        $this->assertStringStartsWith('بسم الله الرحمن الرحيم', $salvaged['draft']);
        $this->assertStringContainsString("\n\nإلى أصحاب الفضيلة", $salvaged['draft'], 'الأسطر تُفكّ لا تبقى \\n حرفية');
        $this->assertStringNotContainsString('"draft"', $salvaged['draft'], 'لا بقايا JSON في النصّ');
    }

    public function test_a_draft_cut_inside_itself_is_salvaged_as_incomplete(): void
    {
        $salvaged = LegalAiService::salvageDraft('{"draft": "بسم الله الرحمن الرحيم\\nالوقائع: في تاريخ');

        $this->assertNotNull($salvaged);
        $this->assertFalse($salvaged['complete']);
        $this->assertSame("بسم الله الرحمن الرحيم\nالوقائع: في تاريخ", $salvaged['draft']);
    }

    public function test_plain_text_is_not_mistaken_for_a_draft_field(): void
    {
        $this->assertNull(LegalAiService::salvageDraft('نصٌّ حرٌّ بلا بنية'));
        $this->assertNull(LegalAiService::salvageDraft(null));
    }

    public function test_the_pleading_gets_an_output_budget_that_fits_a_real_pleading(): void
    {
        $this->assertGreaterThanOrEqual(6000, LegalAiService::maxTokensFor('case.pleading'));
        // وبقيّة المهامّ على سقفها كما كانت — لا يُرفع إنفاقُ كلّ نداء
        $this->assertSame(2048, LegalAiService::maxTokensFor('chat.reply'));
        $this->assertSame(2048, LegalAiService::maxTokensFor(null));
    }

    public function test_the_parser_never_publishes_raw_json_as_a_pleading(): void
    {
        $src = (string) file_get_contents(app_path('Services/LegalAiService.php'));
        // التركيب في `composePleading` منذ صار `draftPleadingResult` غلافاً يُلحق تنبيهات الملفّ
        $start = strpos($src, 'private function composePleading');
        $body = substr($src, $start, strpos($src, 'private function renderPleading') - $start);

        $this->assertStringContainsString('self::salvageDraft($text)', $body);
        $this->assertStringContainsString('لم يُتحقَّق من أسانيد هذه المسودّة', $body, 'المخرج الذي لم يجتز التحقّق يُوسم في نصّه');
    }

    public function test_a_finished_draft_signals_the_office_screen_to_refresh(): void
    {
        Event::fake([CaseStatusBroadcast::class]);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-GEN-1',
            'type' => 'نزاع', 'status' => 'قيد التحضير', 'tone' => 'b-blue', 'pleading_status' => 'pending_lawyer',
        ]);

        $ai = Mockery::mock(LegalAiService::class);
        $ai->shouldReceive('draftPleadingResult')->andReturn([
            'draft' => 'نصّ المسودّة', 'meta' => [], 'source' => AiSource::ManualRequired, 'verdict' => null,
        ]);

        (new DraftCasePleadingJob($case))->handle($ai);

        Event::assertDispatched(CaseStatusBroadcast::class);
        $this->assertSame('نصّ المسودّة', trim(strip_tags((string) $case->messages()->where('role', 'مسودة اللائحة')->value('body'))));
    }

    public function test_the_editor_refreshes_without_overwriting_unsaved_work(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/lawyer/case.tsx'));

        $this->assertStringContainsString("router.reload({ only: ['pleadingDraft', 'pleadingBlock'] })", $ui);
        $this->assertStringContainsString('if (!dirty)', $ui, 'مسودّةٌ جديدة لا تمسح تعديلاً لم يُحفظ');
    }
}
