<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * زر «إعادة التحليل الذكي» في صفحة ملخّص المحامي — يستدعي نقطة lawyer.summary.rerun الموجودة،
 * فتُعيد تشغيل توليد الملخّص (GenerateTicketSummaryJob) لملخّص غير معتمد، وتُمنع على المعتمد.
 */
class LawyerRerunSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function assignedLawyer(): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['اعتماد الملخصات'])->get());

        return $lawyer;
    }

    private function ticketWithSummary(User $lawyer, ?string $approvedAt = null): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-9500', 'type' => 'نزاع تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'بانتظار اعتماد المستشار',
            'tone' => 'b-amber', 'last_message' => '—', 'date_label' => 'الآن',
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'lawyer_id' => $lawyer->id,
            'case_summary' => 'ملخّص', 'attachments_summary' => 'مرفقات', 'facts' => 'وقائع', 'key_points' => 'نقاط',
            'status' => $approvedAt ? 'approved' : 'awaiting_lawyer', 'ai_generated' => true, 'approved_at' => $approvedAt,
        ]);

        return $ticket;
    }

    public function test_assigned_lawyer_can_rerun_unapproved_summary(): void
    {
        Queue::fake();
        $lawyer = $this->assignedLawyer();
        $ticket = $this->ticketWithSummary($lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.summary.rerun', $ticket))->assertRedirect();

        Queue::assertPushed(GenerateTicketSummaryJob::class,
            fn (GenerateTicketSummaryJob $job) => $job->ticket->id === $ticket->id && $job->force === true);
    }

    public function test_rerun_blocked_on_approved_summary(): void
    {
        Queue::fake();
        $lawyer = $this->assignedLawyer();
        $ticket = $this->ticketWithSummary($lawyer, approvedAt: now()->toDateTimeString());

        // النداء من الواجهة عبر Inertia ⇒ تحويل يحمل أخطاء الجلسة
        $this->actingAs($lawyer)->post(route('lawyer.summary.rerun', $ticket))->assertSessionHasErrors('summary');

        Queue::assertNotPushed(GenerateTicketSummaryJob::class);
    }

    public function test_unassigned_lawyer_is_forbidden(): void
    {
        Queue::fake();
        $owner = $this->assignedLawyer();
        $ticket = $this->ticketWithSummary($owner);
        $other = $this->assignedLawyer();

        $this->actingAs($other)->post(route('lawyer.summary.rerun', $ticket))->assertForbidden();
        Queue::assertNotPushed(GenerateTicketSummaryJob::class);
    }
}
