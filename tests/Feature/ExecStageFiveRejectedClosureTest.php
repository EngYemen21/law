<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **مأزق تجمّد الملفات المرفوضة من العميل في المرحلة 5.**
 *
 * عند رفض العميل لعرض خدمة التنفيذ في المرحلة 5، يصبح `offer_status = 'مرفوض'`
 * ويبقى الملف في المرحلة 5. الإصلاح يُمكّن الإدارة من إنهاء الملف وأرشفته بتسبيب معتمد
 * دون أن يتجمّد الملف للأبد.
 */
class ExecStageFiveRejectedClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(Role $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات'])->get());

        return $user;
    }

    private function stageFiveExec(array $attrs = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-ST5-'.uniqid(),
            'subject' => 'تنفيذ سند لأمر',
            'sanad' => 'سند لأمر',
            'amount' => 50000,
            'status' => ExecutionStatus::ServiceOffer->value,
            'tone' => 'b-amber',
            'stage' => 5,
            'fee' => 5000,
            'vat' => 750,
            'fee_approved' => true,
            'offer_status' => 'مرفوض',
            'decision' => 'مقبول',
            'last_action' => 'رفض العميل عرض الخدمة',
        ], $attrs));
    }

    public function test_admin_can_close_and_archive_a_stage_five_rejected_offer(): void
    {
        $admin = $this->staff(Role::Admin);
        $exec = $this->stageFiveExec();

        $this->actingAs($admin)
            ->post(route('exec-flow.act', $exec), [
                'action' => 'close',
                'reason' => 'تنازل طالب التنفيذ',
            ])
            ->assertRedirect();

        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertTrue($exec->isClosed());
        $this->assertSame('مغلق', $exec->status);
        $this->assertSame('تنازل طالب التنفيذ', $exec->closed_reason);
        $this->assertStringContainsString('تنازل طالب التنفيذ', (string) $exec->last_action);
    }

    public function test_lawyer_cannot_close_a_stage_five_rejected_offer(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->stageFiveExec([
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)
            ->post(route('exec-flow.act', $exec), [
                'action' => 'close',
                'reason' => 'أخرى',
            ])
            ->assertForbidden();

        $this->assertSame(5, (int) $exec->fresh()->stage);
        $this->assertFalse($exec->fresh()->isClosed());
    }

    public function test_unrejected_stage_five_offer_cannot_be_closed(): void
    {
        $admin = $this->staff(Role::Admin);
        // العرض ما زال معلقاً ولم يرفضه العميل
        $exec = $this->stageFiveExec(['offer_status' => null]);

        $this->actingAs($admin)
            ->post(route('exec-flow.act', $exec), [
                'action' => 'close',
                'reason' => 'أخرى',
            ])
            ->assertSessionHasErrors('stage');

        $this->assertSame(5, (int) $exec->fresh()->stage);
        $this->assertFalse($exec->fresh()->isClosed());
    }

    public function test_full_lifecycle_from_client_rejection_to_admin_closure(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = $this->staff(Role::Admin);

        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-FLOW-'.uniqid(),
            'subject' => 'تنفيذ شيك',
            'sanad' => 'شيك',
            'amount' => 80000,
            'status' => ExecutionStatus::ServiceOffer->value,
            'tone' => 'b-amber',
            'stage' => 5,
            'fee' => 8000,
            'vat' => 1200,
            'fee_approved' => true,
        ]);

        // 1. العميل يرفض العرض
        $this->actingAs($client)
            ->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])
            ->assertRedirect();

        $exec->refresh();
        $this->assertSame(5, (int) $exec->stage);
        $this->assertSame('مرفوض', $exec->offer_status);

        // 2. الإدارة تقرر إنهاء الملف وأرشفته
        $this->actingAs($admin)
            ->post(route('exec-flow.act', $exec), [
                'action' => 'close',
                'reason' => 'أخرى',
            ])
            ->assertRedirect();

        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertTrue($exec->isClosed());
    }
}
