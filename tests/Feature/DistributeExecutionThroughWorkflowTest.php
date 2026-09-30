<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **إسناد ملفّ التنفيذ من شاشة «التوزيع» يمرّ بمسار الإسناد الواحد** — تدقيق 2026-09-29: كان
 * `DistributeController::assignExecutionTo` يكتب المحامي مباشرةً بلا انتقالٍ في الرحلة ولا إشعارٍ للمحامي
 * ولا رسالةٍ في الملفّ، خلاف الإسناد من داخل الملفّ (`ExecService::assignLawyer`).
 */
class DistributeExecutionThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribute_assignment_records_transition_and_notifies_lawyer(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-DST-1', 'subject' => 'تنفيذ', 'amount' => 1000,
            'stage' => 3, 'status' => ExecFlow::label(3), 'tone' => ExecFlow::tone(3), 'decision' => 'مقبول',
        ]);

        $this->actingAs($admin)->post(route('admin.distribute.assign-execution', $exec), ['lawyer_id' => $lawyer->id])
            ->assertRedirect()->assertSessionHas('flash');

        $this->assertSame($lawyer->id, $exec->fresh()->assigned_lawyer_id);
        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.assign_lawyer')->sole();
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertTrue(UserNotification::where('user_id', $lawyer->id)->exists(), 'المحامي يُبلَّغ');
    }
}
