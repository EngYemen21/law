<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **اعتماد ملخّص التذكرة ونتيجتها، وفتحها، تمرّ بالمحرّك.**
 *
 * كان المستشار والإدارة يكتبان حالة الملخّص وحالة التذكرة مباشرةً، والعميل يُنشئ التذكرة
 * بحالتها الأولى خارج السجلّ. السلوك المرئيّ محفوظٌ في `LawyerSummaryFlowTest`
 * و`SessionResultFlowTest`؛ وهنا ما أضافه المحرّك: كلُّ خطوةٍ سطرٌ في سجلّ الرحلة بفاعلها،
 * والتذكرة التي تقدّمت لا ترتدّ ولا يُكتب لها انتقالٌ لم يقع.
 */
class TicketSummaryApprovalThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function ticket(string $status, array $summary = []): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-SA-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => 'b-amber', 'last_message' => '—',
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
        TicketSummary::create($summary + [
            'ticket_id' => $ticket->id, 'lawyer_id' => $this->lawyer->id, 'status' => 'awaiting_lawyer',
            'case_summary' => 'نزاع توريد', 'facts' => 'تأخّر المورّد', 'key_points' => '• إنذار رسمي', 'ai_generated' => true,
        ]);

        return $ticket;
    }

    private function row(string $name): JourneyTransition
    {
        return JourneyTransition::where('transition', $name)->sole();
    }

    public function test_the_two_approvals_are_recorded_steps_of_summary_and_ticket(): void
    {
        $ticket = $this->ticket('بانتظار اعتماد المستشار');

        $this->actingAs($this->lawyer)->post(route('lawyer.summary.approve', $ticket), ['key_points' => '• إنذار رسمي معدَّل'])
            ->assertRedirect(route('lawyer.summaries'));

        $ticket->refresh();
        $this->assertSame('awaiting_admin', $ticket->summary->status);
        $this->assertSame('• إنذار رسمي معدَّل', $ticket->summary->key_points);
        $this->assertNotNull($ticket->summary->edited_at);
        $this->assertSame($this->lawyer->id, $ticket->summary->lawyer_approved_by);
        $this->assertSame('بانتظار اعتماد الإدارة للملخّص', $ticket->status);
        $this->assertSame(['edited' => true], $this->row('ticket_summary.lawyer_approved')->payload);
        $this->assertSame($this->lawyer->id, $this->row('ticket.awaiting_admin_summary_approval')->actor_id);

        $this->actingAs($this->admin)->post(route('admin.summary.approve', $ticket))->assertRedirect(route('admin.summaries'));

        $ticket->refresh();
        $this->assertSame('approved', $ticket->summary->status);
        $this->assertNotNull($ticket->summary->approved_at);
        $this->assertSame($this->lawyer->id, $ticket->summary->lawyer_approved_by, 'ختم المستشار باقٍ');
        $this->assertSame('الرأي القانوني', $ticket->status);
        $this->assertSame('awaiting_admin', $this->row('ticket_summary.approved')->from_state);
        $this->assertSame('بانتظار اعتماد الإدارة للملخّص', $this->row('ticket.legal_opinion_published')->from_state);
    }

    /** تذكرةٌ تقدّمت (موعدٌ قائم) لا ترتدّ باعتماد ملخّصها — ولا يُقيَّد لها انتقال (ج٩). */
    public function test_a_ticket_past_the_opinion_stage_keeps_its_status(): void
    {
        $ticket = $this->ticket('موعد مؤكد');

        $this->actingAs($this->admin)->post(route('admin.summary.approve', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame('approved', $ticket->summary->status);
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertSame(0, JourneyTransition::where('entity_type', 'Ticket')->count());
    }

    /** نتيجةٌ قديمة «بانتظار الإدارة» تُعتمد من سجلّ الاعتمادات — والخطوة مسجَّلة بفاعلها. */
    public function test_a_legacy_pending_admin_result_is_approved_through_the_engine(): void
    {
        $ticket = $this->ticket('موعد مؤكد', ['status' => 'approved', 'result_status' => 'pending_admin', 'result' => 'نتيجة الجلسة']);

        $this->actingAs($this->admin)->post(route('admin.tickets.result', $ticket))->assertRedirect();

        $this->assertSame('approved', $ticket->summary->fresh()->result_status);
        $this->assertSame('نتيجة الجلسة', $ticket->summary->fresh()->result, 'نصّ النتيجة لا يُمسّ');
        $this->assertSame($this->admin->id, $this->row('ticket_summary.admin_approved_pending_result')->actor_id);
    }

    public function test_opening_a_ticket_records_its_birth_under_the_client(): void
    {
        $this->actingAs($this->client)->post(route('tickets.store'), ['type' => 'نزاع تجاري', 'details' => 'تأخّر المورّد'])
            ->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $row = JourneyTransition::where('transition', 'ticket.opened')->sole();
        $this->assertSame($ticket->id, $row->entity_id);
        $this->assertSame($ticket->number, $row->entity_ref);
        $this->assertNull($row->from_state);
        $this->assertSame('قيد التحليل', $row->to_state);
        $this->assertSame($this->client->id, $row->actor_id);
    }
}
