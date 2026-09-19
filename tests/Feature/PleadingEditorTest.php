<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\DraftCasePleadingJob;
use App\Models\AiRun;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **محرّر لائحة الدعوى للمحامي** (قرار المالك 2026-09-11).
 *
 * كانت اللائحة تصل العميل بصياغة النموذج حرفياً: لا مسار يحفظ نصّاً معدَّلاً، و«تعديل واعتماد»
 * في الصندوق تسميةٌ لا تحرير، ولا إعادة توليد. وحين يتعذّر المزوّد تعلق القضيّة: النصّ
 * الاحتياطيّ لا يُعتمد، ولا سبيل لكتابة غيره.
 *
 * القاعدة: **الحفظ مسودّةٌ محجوبة قابلة للتعديل، والاعتماد النهائيّ يُطلقها ويقفلها.**
 */
class PleadingEditorTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "لائحة دعوى تجارية\nالمدّعي: شركة الأفق\nالطلبات: إلزام المدّعى عليه بسداد المبلغ.";

    /** @return array{0: LegalCase, 1: User} */
    private function caseFor(string $pleadingStatus = 'pending_lawyer'): array
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني']);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-ED-'.uniqid(), 'type' => 'نزاع', 'tone' => 'b-blue',
            'status' => $pleadingStatus === 'approved' ? 'منظورة' : 'قيد التحضير', 'pleading_status' => $pleadingStatus,
        ]);

        return [$case, $lawyer];
    }

    private function aiDraft(LegalCase $case, AiSource $source, string $body = 'مسودّة الآلة'): void
    {
        AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => $source->value,
        ]);
        $case->messages()->create(['who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة', 'body' => $body, 'withheld_at' => now()]);
    }

    private function drafts(LegalCase $case)
    {
        return $case->messages()->where('role', 'مسودة اللائحة')->get();
    }

    public function test_saving_keeps_the_draft_withheld_and_editable(): void
    {
        [$case, $lawyer] = $this->caseFor();

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => self::TEXT])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => self::TEXT.' (معدّلة)'])->assertRedirect();

        $drafts = $this->drafts($case);
        $this->assertCount(1, $drafts, 'الحفظ الثاني يحرّر المسودّة نفسها لا يُنشئ أخرى');
        $this->assertNotNull($drafts[0]->withheld_at, 'محجوبة حتى الاعتماد النهائيّ');
        $this->assertSame('lawyer', $drafts[0]->who);
        $this->assertStringContainsString('(معدّلة)', $drafts[0]->body);
        $this->assertNotContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all());

        // والمحرّر يُفتح على النصّ المحفوظ
        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))
            ->assertInertia(fn ($p) => $p->where('pleadingDraft', self::TEXT.' (معدّلة)'));
    }

    public function test_a_lawyer_rewrite_unblocks_a_fallback_and_final_approval_releases_it(): void
    {
        [$case, $lawyer] = $this->caseFor();
        $this->aiDraft($case, AiSource::Fallback, 'تعذّر توليد المسودّة بالذكاء الاصطناعي.');

        // الاحتياطيّ لا يُعتمد
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);

        // المحامي يكتب اللائحة بيده ⇒ نصٌّ بشريّ يُعتمد
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => self::TEXT])->assertRedirect();
        $this->assertCount(1, $this->drafts($case), 'حُرّرت مسودّة الآلة في مكانها');
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();

        $case->refresh();
        $this->assertSame('approved', $case->pleading_status);
        $client = $case->messages()->visibleTo(false)->where('role', 'مسودة اللائحة')->value('body');
        $this->assertStringContainsString('إلزام المدّعى عليه', (string) $client, 'العميل يرى ما كتبه المحامي لا نصّ الآلة');
        $this->assertSame(AiReviewAction::Edit, AiRun::where('entity_ref', $case->number)->value('review_action'), 'نصٌّ محرَّر يُسجَّل «تعديلاً»');
    }

    public function test_final_approval_locks_saving_and_regeneration(): void
    {
        Queue::fake();
        [$case, $lawyer] = $this->caseFor('approved');
        $case->messages()->create(['who' => 'lawyer', 'name' => 'x', 'role' => 'مسودة اللائحة', 'body' => 'المعتمد']);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => self::TEXT])->assertStatus(422);
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.regenerate', $case))->assertStatus(422);

        $this->assertSame('المعتمد', $this->drafts($case)->first()->body, 'لا تعديل بعد الاعتماد النهائيّ');
        Queue::assertNothingPushed();
    }

    public function test_regeneration_dispatches_a_new_draft(): void
    {
        Queue::fake();
        [$case, $lawyer] = $this->caseFor();

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.regenerate', $case))->assertRedirect();

        Queue::assertPushed(DraftCasePleadingJob::class, fn ($job) => $job->case->is($case));
    }

    public function test_only_the_assigned_lawyer_edits_and_the_text_is_required(): void
    {
        [$case, $lawyer] = $this->caseFor();
        $stranger = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $this->actingAs($stranger)->post(route('lawyer.cases.pleading.save', $case), ['body' => self::TEXT])->assertForbidden();
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => ''])->assertSessionHasErrors('body');
        $this->assertCount(0, $this->drafts($case));
    }

    public function test_the_lawyer_screen_offers_the_three_actions(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/lawyer/case.tsx'));

        $this->assertStringContainsString('aria-label="نصّ لائحة الدعوى"', $ui);
        $this->assertStringContainsString('حفظ المسودّة', $ui);
        $this->assertStringContainsString('إعادة التوليد', $ui);
        $this->assertStringContainsString('الاعتماد النهائيّ للّائحة', $ui);
        // التعديل غير المحفوظ لا يُعتمد — الاعتماد يُطلق ما حُفظ
        $this->assertStringContainsString('disabled={pBusy || dirty || !!pleadingBlock}', $ui);
    }
}
