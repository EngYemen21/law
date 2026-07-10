<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدفعة 3 — توزيع/تحويل التذاكر، مهام الإدارة، إشعارات العملاء، الأرشيف.
 */
class AdminOpsTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $client): Ticket
    {
        return Ticket::create(['user_id' => $client->id, 'number' => 'SB-1', 'type' => 'تجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
    }

    public function test_admin_distributes_ticket_to_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->ticket(User::factory()->create(['role' => Role::Client]));

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), ['lawyer_id' => $lawyer->id])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $ticket->assigned_lawyer);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'note' && $m->role === 'توزيع'));
    }

    public function test_employee_transfers_ticket_between_lawyers(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $a = User::factory()->create(['role' => Role::Lawyer]);
        $b = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->ticket(User::factory()->create(['role' => Role::Client]));
        $ticket->update(['assigned_lawyer' => $a->name, 'assigned_lawyer_id' => $a->id]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), ['lawyer_id' => $b->id, 'reason' => 'إعادة توزيع'])->assertRedirect();

        $this->assertSame($b->id, $ticket->fresh()->assigned_lawyer_id);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'تحويل'));
    }

    public function test_admin_assigns_task_to_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'assigned_to' => $lawyer->id, 'title' => 'إعداد مذكرة', 'ref' => 'CASE-1', 'due' => 'غداً',
        ])->assertRedirect();

        $task = Task::firstOrFail();
        $this->assertSame($lawyer->id, $task->assigned_to);
        $this->assertSame('مفتوحة', $task->status);
    }

    public function test_admin_sends_client_notification(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->post(route('admin.clientnotifs.send'), [
            'client_id' => $client->id, 'body' => 'تذكير بموعد جلستك غداً.',
        ])->assertRedirect();

        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_admin_archive_shows_finished_consults(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-1', 'subject' => 'استشارة', 'type' => 'عام', 'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد', 'time' => '11ص', 'when_label' => 'الأحد · 11ص', 'status' => 'منتهية']);
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-2', 'subject' => 'استشارة', 'type' => 'عام', 'channel' => 'هاتفية',
            'lawyer' => 'أ. سارة القحطاني', 'day' => 'الاثنين', 'time' => '10ص', 'when_label' => 'الاثنين · 10ص', 'status' => 'جديدة']);

        $this->actingAs($admin)->get(route('admin.archive'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/archive')->has('rows', 1)); // المنتهية فقط
    }
}
