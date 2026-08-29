<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شاشة صندوق المراجعة — تُتحقَّق بتأكيدات Inertia (المكوّن والـprops) لا بمتصفّح.
 *
 * ما يُفحص هنا هو **عقد الشاشة**: أي بيانات تصلها فعلاً، ومن يُسمح له بفتحها، وأي
 * قرار يُقبل وأيّه يُردّ. وهذا ما يمكن إثباته خادمياً؛ أما المظهر فلا يُثبته اختبار.
 */
class AiReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);

        return User::factory()->create(['role' => Role::Admin]);
    }

    private function aiRun(array $overrides = []): AiRun
    {
        return AiRun::create(array_merge([
            'task_type' => 'consult',
            'entity_ref' => 'CN-2026-1',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_NEEDS_REVIEW,
            'model' => 'gemini-2.5-flash',
            'prompt_version' => 'v1',
            'confidence' => 55,
            'confidence_signals' => ['lawyer_from_roster' => true],
            'trace_id' => (string) Str::uuid(),
        ], $overrides));
    }

    // ── العقد: ما يصل الشاشة ──

    public function test_the_screen_receives_the_pending_items_with_their_evidence(): void
    {
        $this->aiRun();

        $this->actingAs($this->admin())
            ->get(route('admin.ai-review'))
            ->assertInertia(fn ($page) => $page
                ->component('admin/ai-review')
                ->has('items', 1)
                ->where('items.0.entityRef', 'CN-2026-1')
                ->where('items.0.confidence', 55)
                ->where('items.0.model', 'gemini-2.5-flash')
                ->where('items.0.promptVersion', 'v1')
                // المراجع يرى أساس الدرجة لا الدرجة وحدها
                ->has('items.0.confidenceSignals')
                ->has('actions', count(AiReviewAction::cases()))
                ->has('reasons', count(AiReviewReason::cases()))
            );
    }

    /** «غير مقيسة» تصل كـ`null` — لا تُحوَّل صفراً في الطريق إلى الشاشة. */
    public function test_unmeasured_confidence_reaches_the_screen_as_null(): void
    {
        $this->aiRun(['confidence' => null, 'confidence_signals' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-review'))
            ->assertInertia(fn ($page) => $page->where('items.0.confidence', null));
    }

    public function test_the_screen_carries_operational_metrics_and_alerts(): void
    {
        $this->aiRun(['source' => AiSource::Fallback->value]);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-review'))
            ->assertInertia(fn ($page) => $page
                ->has('metrics.pending')
                ->has('metrics.ops')
                ->has('metrics.alerts')
                ->has('metrics.rejectionReasons')
            );
    }

    // ── الحراسة ──

    public function test_a_client_cannot_open_the_review_screen(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->get(route('admin.ai-review'))->assertRedirect();
        $this->actingAs($client)->getJson(route('admin.ai-review'))->assertForbidden();
    }

    public function test_an_employee_without_the_approval_permission_is_blocked(): void
    {
        $this->seed(PermissionSeeder::class);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]);

        $this->actingAs($employee)->getJson(route('employee.ai-review'))->assertForbidden();
    }

    public function test_an_employee_with_the_approval_permission_may_open_it(): void
    {
        $this->seed(PermissionSeeder::class);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([Permission::where('name', 'اعتماد الملخصات')->firstOrFail()]);

        // لوحة الإدارة مقصورة على الإدارة؛ للموظّف مساره الخاصّ بالشاشة نفسها
        $this->actingAs($employee)->get(route('employee.ai-review'))->assertOk();
    }

    // ── القرار ──

    public function test_recording_a_decision_stores_the_reviewer_and_closes_the_item(): void
    {
        $run = $this->aiRun();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.ai-review.decide', $run), ['action' => AiReviewAction::Edit->value, 'note' => 'عُدّلت الصياغة'])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(AiReviewAction::Edit, $run->review_action);
        $this->assertSame($admin->id, $run->reviewed_by);
        $this->assertNotNull($run->reviewed_at);

        // خرج من الصندوق
        $this->actingAs($admin)->get(route('admin.ai-review'))
            ->assertInertia(fn ($page) => $page->has('items', 0));
    }

    /** الرفض بلا سبب منظَّم يُردّ — وإلّا صار الرفض ملاحظةً ضائعة لا بيانات تقييم. */
    public function test_rejection_without_a_structured_reason_is_refused(): void
    {
        $run = $this->aiRun();

        $this->actingAs($this->admin())
            ->post(route('admin.ai-review.decide', $run), ['action' => AiReviewAction::Reject->value])
            ->assertSessionHasErrors('reason');

        $this->assertNull($run->fresh()->review_action, 'لا يُسجَّل قرار ناقص');
    }

    public function test_rejection_with_a_reason_is_recorded(): void
    {
        $run = $this->aiRun();

        $this->actingAs($this->admin())->post(route('admin.ai-review.decide', $run), [
            'action' => AiReviewAction::Reject->value,
            'reason' => AiReviewReason::FabricatedFact->value,
        ])->assertRedirect();

        $this->assertSame(AiReviewReason::FabricatedFact, $run->fresh()->review_reason);
    }

    public function test_escalation_without_an_assignee_is_refused(): void
    {
        $run = $this->aiRun();

        $this->actingAs($this->admin())
            ->post(route('admin.ai-review.decide', $run), ['action' => AiReviewAction::Escalate->value])
            ->assertSessionHasErrors('escalated_to');
    }

    public function test_an_unknown_action_is_rejected_by_validation(): void
    {
        $run = $this->aiRun();

        $this->actingAs($this->admin())
            ->post(route('admin.ai-review.decide', $run), ['action' => 'approve_everything'])
            ->assertSessionHasErrors('action');
    }
}
