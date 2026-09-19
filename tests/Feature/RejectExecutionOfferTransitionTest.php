<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Execution\RejectExecutionOffer;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **رفض عرض التنفيذ يتبع المرحلة لا نصّ الحالة.**
 *
 * 🔴 كان الانتقال يقبل «عرض الخدمة» وحده، والخدمة تحرس بالمرحلة 5 — فملفٌّ في المرحلة 5 بنصٍّ
 * آخر يُرفض رفضُه بخطأ 422 بينما يُقبل قبولُه. الإصلاح يوسّع `from()` بقائمة `ApproveExecutionFee`
 * ويعيد النصّ إلى «عرض الخدمة».
 */
class RejectExecutionOfferTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $extra = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => 'EXE-REJ-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => ExecutionStatus::ServiceOffer->value, 'tone' => 'b-amber', 'stage' => 5, 'amount' => 50000,
            'fee' => 6000, 'vat' => 900, 'fee_approved' => true,
        ], $extra));
    }

    public function test_a_stage_five_offer_with_a_stale_status_text_can_be_rejected(): void
    {
        $exec = $this->offer(['status' => ExecutionStatus::UnderStudy->value]);

        $this->actingAs($exec->user)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertRedirect();

        $exec->refresh();
        $this->assertSame('مرفوض', $exec->offer_status);
        $this->assertSame(ExecutionStatus::ServiceOffer->value, $exec->status, 'النصّ يتّسق مع المرحلة');
        $this->assertSame(5, (int) $exec->stage);
        $this->assertSame(1, JourneyTransition::where('transition', 'exec.reject_offer')->where('entity_id', $exec->id)->count());
    }

    public function test_a_regular_offer_is_rejected_as_before(): void
    {
        $exec = $this->offer();

        $this->actingAs($exec->user)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertRedirect();

        $this->assertSame('مرفوض', $exec->fresh()->offer_status);
    }

    /** المرحلة تبقى الحارس: ملفٌّ قيد التنفيذ لا عرض فيه يُرفض. */
    public function test_a_file_past_the_offer_stage_is_still_refused(): void
    {
        $exec = $this->offer(['stage' => 8, 'status' => ExecutionStatus::InProgress->value]);

        $this->actingAs($exec->user)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertSessionHasErrors('stage');

        $exec->refresh();
        $this->assertNull($exec->offer_status);
        $this->assertSame(0, JourneyTransition::where('transition', 'exec.reject_offer')->count());
    }

    /** التوسيع لا يفتح الانتقال على الحالات المتأخّرة ولو نودي مباشرة. */
    public function test_the_transition_itself_refuses_a_closed_file(): void
    {
        $exec = $this->offer(['stage' => 9, 'status' => ExecutionStatus::Closed->value]);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new RejectExecutionOffer, $exec);
    }
}
