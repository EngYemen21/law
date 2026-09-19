<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لا يُعتمد نصٌّ احتياطيّ لائحةً، ولا يُتجاوَز رفضُ الصندوق بزرّ.**
 *
 * كشف تتبّعُ مسار المسودّة (2026-09-11): حين يتعذّر مزوّد الذكاء يُكتب «تعذّر توليد
 * المسودّة…» بدور «مسودة اللائحة» نفسه. ومع اعتمادٍ واحدٍ يُطلق النصّ، صار اعتمادُ ذلك
 * النصّ يرسله للعميل بوصفه لائحة الدعوى ويرفع الدعوى به.
 */
class CasePleadingGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: LegalCase, 1: User, 2: AiRun} */
    private function caseWithRun(AiSource $source, ?AiReviewAction $decision = null): array
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'assigned_lawyer_id' => $lawyer->id, 'number' => 'CASE-PG-'.uniqid(), 'type' => 'نزاع',
            'status' => 'قيد التحضير', 'tone' => 'b-blue', 'pleading_status' => 'pending_lawyer',
        ]);
        $run = AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => $source->value,
            'review_action' => $decision?->value,
        ]);
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة',
            'body' => 'مسودّة لائحة دعوى — تعذّر توليد المسودّة بالذكاء الاصطناعي.', 'withheld_at' => now(),
        ]);

        return [$case, $lawyer, $run];
    }

    public function test_a_fallback_draft_cannot_be_approved_from_the_file(): void
    {
        [$case, $lawyer] = $this->caseWithRun(AiSource::Fallback);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertNotContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all(), 'النصّ الاحتياطيّ لا يصل العميل');

        // والشاشة تقول السبب بدل الزرّ
        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))
            ->assertInertia(fn ($p) => $p->where('pleadingBlock', fn ($r) => str_contains((string) $r, 'احتياطيّ')));
    }

    public function test_accepting_a_fallback_in_the_inbox_releases_nothing(): void
    {
        [$case, $lawyer, $run] = $this->caseWithRun(AiSource::Fallback);

        AiReviewOutcome::apply($run, AiReviewAction::Accept, $lawyer);

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertNotContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all());
    }

    public function test_a_draft_rejected_in_the_inbox_cannot_be_approved_from_the_file(): void
    {
        [$case, $lawyer] = $this->caseWithRun(AiSource::AiSuccess, AiReviewAction::Reject);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);
        $this->assertSame('قيد التحضير', $case->fresh()->status);
    }

    public function test_a_real_draft_is_still_approvable(): void
    {
        [$case, $lawyer] = $this->caseWithRun(AiSource::AiSuccess);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();
        $this->assertSame('approved', $case->fresh()->pleading_status);
    }
}
