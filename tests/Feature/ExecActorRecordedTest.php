<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\ExecFlow;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **مَن نفّذ الإجراء يُسجَّل في سجلّ الانتقالات** — تدقيق 2026-09-29: موزّع `exec-flow.act` كان يستدعي
 * أغلب دوالّ `ExecService` بلا الفاعل، فيُكتب `journey_transitions.actor_id` فارغاً ولا يعمل `deny()`
 * الخاصّ بالانتقال. الإغلاق والإسناد والتحصيل وحدها كانت تمرّره.
 */
class ExecActorRecordedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_najiz_steps_record_the_acting_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-ACT-1', 'subject' => 'تنفيذ', 'amount' => 50000,
            'stage' => 7, 'status' => ExecFlow::label(7), 'tone' => ExecFlow::tone(7), 'paid' => true, 'exec_no' => 'EXE-TN-ACT-1',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'fileNajiz', 'request_no' => 'NJ-1', 'filed_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.file_najiz')->sole();
        $this->assertSame($lawyer->id, $row->actor_id, 'الفاعل يُسجَّل');
    }

    public function test_admin_fee_approval_records_the_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-ACT-2', 'subject' => 'تنفيذ', 'amount' => 50000,
            'stage' => 4, 'status' => ExecFlow::label(4), 'tone' => ExecFlow::tone(4), 'fee' => 3000, 'duration' => '30 يوماً',
            'fee_mode' => 'fixed', 'decision' => 'مقبول', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'approveFee'])->assertSessionHasNoErrors();

        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.approve_fee')->sole();
        $this->assertSame($admin->id, $row->actor_id);
    }
}
