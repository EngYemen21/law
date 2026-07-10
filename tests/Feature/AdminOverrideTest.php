<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإدارة العليا لها صلاحيات مطلقة: تراجع/تعدّل/تعتمد ملخص ملف أي محامٍ
 * دون أن يقيّدها حارس إسناد المحامي (guardAssigned مستثنٍ للإدارة).
 */
class AdminOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function referredTicket(User $lawyer): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-ADM-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        $ticket->summary()->create([
            'lawyer_id' => $lawyer->id,
            'case_summary' => 'ملخص القضية', 'attachments_summary' => 'مرفق',
            'facts' => '• واقعة', 'key_points' => '• نقطة',
            'status' => 'awaiting_lawyer', 'approved_at' => null,
        ]);

        return $ticket;
    }

    public function test_admin_can_approve_file_summary_of_any_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->referredTicket($lawyer);

        // لا 403 رغم أن الملخص مسند لمحامٍ آخر
        $this->actingAs($admin)->post(route('admin.summary.approve', $ticket), ['key_points' => 'الرأي المعتمد'])
            ->assertRedirect(route('admin.summaries'));

        $this->assertSame('approved', $ticket->summary->fresh()->status);
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
    }

    public function test_admin_can_view_and_edit_file_summary(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->referredTicket($lawyer);

        $this->actingAs($admin)->get(route('admin.summary', $ticket))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/summary')->where('base', '/admin'));

        $this->actingAs($admin)->post(route('admin.summary.update', $ticket), ['case_summary' => 'ملخص محدّث من الإدارة'])
            ->assertRedirect();
        $this->assertSame('ملخص محدّث من الإدارة', $ticket->summary->fresh()->case_summary);
    }

    public function test_unassigned_lawyer_still_blocked_from_others_summary(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->referredTicket($other);

        // محامٍ غير مسند لا يزال ممنوعاً (العزل قائم لغير الإدارة)
        $this->actingAs($mine)->post(route('lawyer.summary.approve', $ticket))->assertForbidden();
    }
}
