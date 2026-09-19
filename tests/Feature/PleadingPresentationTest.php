<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalCase;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewOutcome;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لائحةٌ تُقرأ، لا رموزٌ داخليّة.** (قيسَ في المتصفّح 2026-09-11)
 *
 * بعد إصلاح البتر صار التوليد ينجح — لكن النصّ حمل «(LS-CIVIL-281)» في متنه، وملحقاً
 * «— سند الادّعاءات (مُطابَق بقاعدة المصادر) —» يكرّر كلّ مادّة بمعرّفها، ونجوم Markdown
 * حرفيّة، و«القضية رقم CASE-2026-5213» — رقم ملفّ المكتب لا رقم الدعوى.
 */
class PleadingPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $ref, ?string $article = 'الحادية والثمانون بعد المائتين'): LegalSource
    {
        return LegalSource::create([
            'ref' => $ref, 'system_name' => 'نظام المعاملات المدنية', 'article_no' => $article,
            'title' => 'المقاصة', 'text' => 'للمدين المقاصة…', 'jurisdiction' => 'السعودية', 'version' => 'م/191',
            'effective_from' => '2023-12-16', 'source_owner' => 'أم القرى', 'legal_review_at' => '2026-01-01',
            'usage_scope' => 'مسودات داخلية', 'status' => LegalSource::STATUS_APPROVED ?? 'معتمد',
        ]);
    }

    public function test_internal_source_ids_become_readable_citations(): void
    {
        $map = LegalAiService::citationMap([$this->source('LS-CIVIL-281')]);
        $text = LegalAiService::humanizeDraft(
            "1. **بخصوص المقاصة:** يحق للمدين المقاصة. (LS-CIVIL-281)\n2. ادّعاءٌ آخر [LS-CIVIL-9999]\n## الطلبات",
            $map
        );

        $this->assertStringNotContainsString('LS-', $text, 'لا معرّف داخليّ في النصّ');
        $this->assertStringContainsString('(المادة الحادية والثمانون بعد المائتين من نظام المعاملات المدنية)', $text);
        $this->assertStringContainsString('(سندٌ غير مُتحقَّق)', $text, 'معرّفٌ لا يقابله مصدرٌ معتمد يُعلَن');
        $this->assertStringNotContainsString('**', $text, 'لا نجوم Markdown');
        $this->assertStringNotContainsString('##', $text);
        $this->assertStringContainsString('بخصوص المقاصة:', $text, 'النصّ نفسه يبقى');
    }

    public function test_the_prompt_asks_for_a_plain_pleading_without_ids(): void
    {
        $prompt = AiPromptRegistry::casePleadingSystem();

        $this->assertStringContainsString('تبدأ بعنوان «لائحة دعوى»', $prompt);
        $this->assertStringContainsString('يُترك رقم الدعوى (يُستكمل)', $prompt);
        $this->assertStringContainsString('بلا أيّ رموز تنسيق', $prompt);
        $this->assertStringContainsString('لا تكتب معرّفات المصادر', $prompt);
        // وحواجز عدم الاختلاق باقية
        $this->assertStringContainsString('ولا تخترع مواد نظامية بأرقام غير مؤكّدة', $prompt);
    }

    public function test_the_model_is_not_told_the_office_file_number_as_the_case_number(): void
    {
        $src = (string) file_get_contents(app_path('Services/LegalAiService.php'));

        $this->assertStringNotContainsString('$context = "رقم القضية: {$case->number}', $src);
    }

    /** @return array{0: LegalCase, 1: User, 2: AiRun} */
    private function caseWithDraft(string $body): array
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-PR-'.uniqid(), 'type' => 'نزاع', 'status' => 'قيد التحضير', 'tone' => 'b-blue',
            'pleading_status' => 'pending_lawyer',
        ]);
        $run = AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => AiSource::ManualRequired->value,
        ]);
        $case->messages()->create(['who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة', 'body' => $body, 'withheld_at' => now()]);

        return [$case, $lawyer, $run];
    }

    public function test_a_draft_carrying_an_internal_warning_is_not_approvable(): void
    {
        [$case, $lawyer, $run] = $this->caseWithDraft("لائحة دعوى…\n\n⚠️ ادّعاءات بلا سندٍ مُتحقَّق — لا تُقدَّم للمحكمة قبل توثيقها");

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);
        AiReviewOutcome::apply($run, AiReviewAction::Accept, $lawyer);

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status, 'لا من الزرّ ولا من الصندوق');
        $this->assertNotContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all(), 'التنبيه لا يصل العميل');

        // والمحامي يعالج ويحذف التنبيه ثم يحفظ ⇒ يُعتمد
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => 'لائحة دعوى موثَّقة الأسانيد بعد المراجعة.'])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();
        $this->assertSame('approved', $case->fresh()->pleading_status);
    }

    public function test_a_lawyer_who_keeps_the_warning_is_still_blocked(): void
    {
        [$case, $lawyer] = $this->caseWithDraft('نصّ');

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => "لائحة دعوى كاملة النصّ.\n⚠️ تنبيه لم يُعالَج"])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);
    }
}
