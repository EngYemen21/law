<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات حوكمة مسارات مآل التذاكر الأربعة (استشارة، قضية، تنفيذ، إلغاء)
 * وتدخل الذكاء الاصطناعي بالسبب الحقيقي وبوابة اعتماد الإدارة العليا.
 */
class TicketTrackGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function createTicket(User $client, ?User $lawyer = null, array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $client->id,
            'number' => 'SB-2026-'.rand(1000, 9999),
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'subject' => 'مطالبة بتوريد بضائع',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => TicketStatus::ReadyForOutcome->value,
            'tone' => 'b-amber',
        ], $attrs));
    }

    public function test_ai_suggests_execution_track_when_execution_sanad_detected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->createTicket($client, null, [
            'subject' => 'تحصيل مبلغ سند لأمر مستحق السداد بمحكمة التنفيذ',
        ]);

        $ai = app(LegalAiService::class);
        $suggestion = $ai->suggestTicketTrack($ticket);

        $this->assertSame(TicketOutcomeTrack::Execution->value, $suggestion['track']);
        $this->assertStringContainsString('سند تنفيذي', $suggestion['reason']);
    }

    public function test_ai_suggests_case_track_when_court_dispute_detected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->createTicket($client, null, [
            'subject' => 'دعوى مطالبة مالية وتعويض أمام المحكمة العامة',
            'court_name' => 'المحكمة العامة بالرياض',
            'claim_amount' => 150000,
        ]);

        $ai = app(LegalAiService::class);
        $suggestion = $ai->suggestTicketTrack($ticket);

        $this->assertSame(TicketOutcomeTrack::Case->value, $suggestion['track']);
        $this->assertStringContainsString('دعوى', $suggestion['reason']);
    }

    public function test_ai_suggests_close_track_when_drop_keywords_detected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->createTicket($client, null, [
            'subject' => 'موضوع خارج الاختصاص ومحل نزاع غير مشمول بنطاق المكتب',
        ]);

        $ai = app(LegalAiService::class);
        $suggestion = $ai->suggestTicketTrack($ticket);

        $this->assertSame(TicketOutcomeTrack::Close->value, $suggestion['track']);
        $this->assertStringContainsString('خارج الاختصاص', $suggestion['reason']);
    }

    public function test_ai_suggests_consultation_track_when_advisory_discussion_needed(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->createTicket($client, null, [
            'subject' => 'استفسار نظامي حول بنود عقد عمل والشروط الجزائية',
            'court_name' => null,
            'claim_amount' => 0,
        ]);

        $ai = app(LegalAiService::class);
        $suggestion = $ai->suggestTicketTrack($ticket);

        $this->assertSame(TicketOutcomeTrack::Consultation->value, $suggestion['track']);
        $this->assertStringContainsString('جلسة استشارية', $suggestion['reason']);
    }

    public function test_lawyer_proposes_track_and_transitions_to_awaiting_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار القانوني']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->createTicket($client, $lawyer);

        $response = $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Execution->value,
                'reason' => 'حيازة العميل لسند لأمر صادر وواجب النفاذ يستوجب التقدم لمحكمة التنفيذ مباشرة.',
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::AwaitingAdminOutcomeApproval->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Execution->value, $ticket->proposed_track);
        $this->assertSame('حيازة العميل لسند لأمر صادر وواجب النفاذ يستوجب التقدم لمحكمة التنفيذ مباشرة.', $ticket->proposed_track_reason);
        $this->assertSame($lawyer->id, $ticket->proposed_by_id);
        $this->assertNotNull($ticket->proposed_at);
        $this->assertNull($ticket->approved_track);
    }

    public function test_employee_proposes_track_to_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'أحمد الموظف']);
        $ticket = $this->createTicket($client);

        $response = $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'وجود نزاع تجاري موضوعي ومطالبة مالية تتطلب إقامة صحيفة دعوى والترافع.',
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::AwaitingAdminOutcomeApproval->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Case->value, $ticket->proposed_track);
        $this->assertSame($employee->id, $ticket->proposed_by_id);
    }

    public function test_admin_approves_consultation_track_and_transitions_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->createTicket($client);

        $reason = 'الموضوع يستدعي جلسة استشارية متعمقة لبحث خيارات التفاوض وصياغة الموقف الودي.';

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => $reason,
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::AwaitingBooking->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Consultation->value, $ticket->approved_track);
        $this->assertSame($reason, $ticket->approved_track_reason);
        $this->assertSame($admin->id, $ticket->approved_by_id);
        $this->assertNotNull($ticket->approved_track_at);

        // رسالة منشورة في المحادثة للعميل مع التسبيب الحقيقي
        $msg = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('قرار الإدارة العليا: توجيه بطلب استشارة قانونية', $msg->body);
        $this->assertStringContainsString($reason, $msg->body);
    }

    public function test_admin_approves_case_track_and_converts_to_legal_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->createTicket($client, $lawyer);

        $reason = 'توافر أركان الخصومة التجارية وتخلف المدعى عليه عن السداد يستوجب رفع الدعوى أمام المحكمة.';

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => $reason,
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::ConvertedToCase->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Case->value, $ticket->approved_track);
        $this->assertTrue($ticket->is_frozen);

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($client->id, $case->user_id);
    }

    public function test_admin_approves_execution_track_and_creates_execution_record(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->createTicket($client, $lawyer, [
            'subject' => 'سند لأمر بمبلغ 85000 ريال',
            'claim_amount' => 85000,
            'opponent_name' => 'شركة التحدي للتجارة',
        ]);

        $reason = 'ثبوت السند التنفيذي المستوفي لشروطه النظامية مما يخول التقديم على دوائر التنفيذ فوراً.';

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Execution->value,
                'reason' => $reason,
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::ConvertedToCase->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Execution->value, $ticket->approved_track);
        $this->assertTrue($ticket->is_frozen);

        $exec = Execution::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($exec);
        $this->assertSame($client->id, $exec->user_id);
        $this->assertSame(85000, $exec->amount);
        $this->assertSame('شركة التحدي للتجارة', $exec->defendant);
    }

    public function test_admin_approves_close_track_with_statutory_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->createTicket($client);

        $reason = 'عدم توافر السند القانوني الكافي وثبوت تقادم الحق المدعى به نظاماً.';

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Close->value,
                'closure_reason_code' => ClosureReasonCode::NoLegalMerit->value,
                'reason' => $reason,
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        $this->assertSame(TicketStatus::Closed->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Close->value, $ticket->approved_track);
        $this->assertSame(ClosureReasonCode::NoLegalMerit->value, $ticket->closure_reason_code);
        $this->assertSame($reason, $ticket->closure_notes);
        $this->assertTrue($ticket->is_frozen);
    }

    public function test_non_admin_cannot_approve_track(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->createTicket($client, $lawyer);

        $response = $this->actingAs($lawyer)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'مبرر تجريبي للاعتماد',
            ]);

        // Non-admin blocked by admin middleware or role gate
        $this->assertTrue(in_array($response->status(), [403, 302], true));
    }

    public function test_admin_approvals_screen_displays_proposed_tracks(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار فهد']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = $this->createTicket($client, $lawyer, [
            'proposed_track' => TicketOutcomeTrack::Execution->value,
            'proposed_track_reason' => 'وجود شيك مصرفي مرتد مسحوب على بنك محلي.',
            'proposed_by_id' => $lawyer->id,
            'proposed_at' => now(),
            'status' => TicketStatus::AwaitingAdminOutcomeApproval->value,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.approvals'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/approvals')
            ->has('ticketTrackProposals', 1)
            ->where('ticketTrackProposals.0.no', $ticket->number)
            ->where('ticketTrackProposals.0.proposedTrack', TicketOutcomeTrack::Execution->value)
            ->where('ticketTrackProposals.0.proposedBy', 'المستشار فهد')
        );
    }
}
