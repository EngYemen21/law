<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من مراحل ما بعد الحجز: الجلسة (محضر) → اعتماد المستشار للنتيجة → اعتماد الإدارة → النتيجة للعميل.
 */
class SessionResultFlowTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedTicket(User $client, ?User $lawyer = null): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-8888',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'موعد مؤكد',
            'tone' => 'b-green',
        ]);
        $ticket->summary()->create([
            'case_summary' => 'ملخص القضية',
            'attachments_summary' => 'مستند واحد',
            'facts' => "• واقعة أولى.\n• واقعة ثانية.",
            'key_points' => "• توصية أولى.\n• توصية ثانية.",
            'status' => 'approved',
            'result_status' => 'none',
        ]);

        return $ticket;
    }

    public function test_employee_conducts_session_then_waits_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->confirmedTicket($client);

        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $ticket->refresh();
        $this->assertSame('بانتظار اعتماد النتيجة', $ticket->status);
        $this->assertSame('pending_lawyer', $ticket->summary->result_status);
        $this->assertNotEmpty($ticket->summary->result);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'محضر الجلسة'));

        // الموظف لا يتقدّم أثناء مراجعة المستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد النتيجة', $ticket->fresh()->status);
    }

    public function test_lawyer_raises_result_to_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->confirmedTicket($client, $lawyer);
        $ticket->summary->update(['result_status' => 'pending_lawyer', 'result' => 'نتيجة الجلسة']);

        $this->actingAs($lawyer)->post(route('lawyer.result.approve', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame('بانتظار اعتماد الإدارة', $ticket->status);
        $this->assertSame('pending_admin', $ticket->summary->result_status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'lawyer' && $m->role === 'اعتماد'));
    }

    public function test_admin_final_approval_delivers_result_to_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->confirmedTicket($client);
        $ticket->summary->update(['result_status' => 'pending_admin', 'result' => 'نتيجة الجلسة']);

        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame('مكتملة', $ticket->status);
        $this->assertSame('approved', $ticket->summary->result_status);

        // بطاقة النتيجة + اعتماد الإدارة في المحادثة، وإشعار للعميل
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'النتيجة'));
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'admin'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // العميل يرى بطاقة النتيجة
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->contains(fn ($x) => $x['role'] === 'النتيجة')));
    }

    public function test_admin_cannot_approve_before_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->confirmedTicket(User::factory()->create(['role' => Role::Client]));
        // result_status still 'none' → admin approval rejected
        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertNotFound();
    }
}
