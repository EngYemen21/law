<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ExecMessageBroadcast;
use App\Events\ExecStatusBroadcast;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * البثّ اللحظيّ في التنفيذ (Reverb) — يُطلَق فعليّاً عبر نقاط النهاية الموحّدة:
 * كل رسالة تبثّ ExecMessageBroadcast، وكل انتقال حالة يبثّ ExecStatusBroadcast،
 * على القناة الخاصّة exec.{id} (المفوَّضة عبر ChannelAccess — يغطّيها ChannelAuthTest).
 */
class ExecBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_message_broadcasts_live_on_exec_channel(): void
    {
        Event::fake([ExecMessageBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-B1', 'subject' => 'بثّ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($client)->post(route('exec-flow.messages.store', $exec), ['body' => 'مرحباً'])->assertNoContent();

        Event::assertDispatched(ExecMessageBroadcast::class, fn ($e) => (int) $e->message->execution_id === (int) $exec->id);
    }

    public function test_office_message_broadcasts_live(): void
    {
        Event::fake([ExecMessageBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-B2', 'subject' => 'بثّ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($admin)->post(route('exec-flow.messages.store', $exec), ['body' => 'ردّ المكتب'])->assertNoContent();

        Event::assertDispatched(ExecMessageBroadcast::class);
    }

    public function test_status_transition_broadcasts_live(): void
    {
        Event::fake([ExecStatusBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-B3', 'subject' => 'بثّ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'amount' => 50000]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'fee' => 5000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة'])->assertRedirect();

        Event::assertDispatched(ExecStatusBroadcast::class, fn ($e) => (int) $e->execution->id === (int) $exec->id);
    }
}
