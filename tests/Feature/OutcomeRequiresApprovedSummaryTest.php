<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Domain\Journey\Transitions\Ticket\ProposeOutcomeTrack;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **ث٥: لا قرارَ مآلٍ بلا ملخّصٍ معتمد** (قرار المالك 2026-09-25).
 *
 * قِيس حيّاً: SB-2026-1018 مضت من «بانتظار مستندات» إلى مقترحٍ فاعتمادٍ فملفّ تنفيذ EXE-2026-5098
 * بلا ملخّص ولا محامٍ ولا رأي. الشرط الآن في الانتقالين (`OutcomeSummaryGate`) لا في المتحكّمات،
 * وللإدارة العليا وحدها مسارٌ سريع بسببٍ مكتوب — داخل الانتقالين نفسيهما لا بجوارهما.
 */
class OutcomeRequiresApprovedSummaryTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private const WAIVER = 'العميل مهدّدٌ بفوات مهلة التنفيذ غداً — قرارٌ عاجل قبل اكتمال الملخّص.';

    /** تذكرةٌ كـSB-2026-1018: بانتظار مستندات، بلا محامٍ ولا ملخّص. */
    private function bareTicket(): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-1018',
            'type' => 'تنفيذ سند لأمر',
            'department' => 'قسم التنفيذ',
            'status' => TicketStatus::AwaitingDocs->value,
            'tone' => 'b-amber',
        ]);
    }

    private function proposal(array $extra = []): array
    {
        return array_merge([
            'track' => TicketOutcomeTrack::Execution->value,
            'reason' => 'العميل يحوز سنداً لأمر مستحقّ الأداء — مسار تنفيذ.',
        ], $extra);
    }

    public function test_an_employee_cannot_propose_without_an_approved_summary(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->bareTicket();

        $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), $this->proposal())
            ->assertStatus(422);

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::AwaitingDocs->value, $fresh->status, 'مضى المقترح بلا ملخّص.');
        $this->assertNull($fresh->proposed_track);
    }

    public function test_a_lawyer_cannot_propose_on_a_summary_approved_only_by_the_lawyer(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->bareTicket();
        $ticket->update(['assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
        // المرحلة الأولى وحدها (اعتماد المحامي) — لا تكفي: القرار يُنشر للعميل ولم يُعتمد الملخّص للنشر
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'facts' => 'وقائع', 'status' => 'awaiting_admin',
            'lawyer_approved_at' => now(),
        ]);

        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.track.propose', $ticket), $this->proposal())
            ->assertStatus(422);

        $this->assertNull($ticket->fresh()->proposed_track);
    }

    /** الرسالة تقول ما الناقص تحديداً — لا «غير مسموح» عامّة. */
    public function test_the_refusal_names_what_is_missing(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->bareTicket();

        try {
            Workflow::run(new ProposeOutcomeTrack, $ticket, $employee, $this->proposal());
            $this->fail('مضى المقترح بلا ملخّص.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('يلزم ملخّصٌ معتمد', $e->getMessage());
            $this->assertStringContainsString('ولا ملخّص لهذه التذكرة بعد', $e->getMessage());
        }
    }

    public function test_an_approved_summary_opens_the_proposal(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->approveOpinionOf($this->bareTicket());

        $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), $this->proposal())
            ->assertRedirect();

        $this->assertSame(TicketStatus::AwaitingAdminOutcomeApproval->value, $ticket->fresh()->status);
    }

    /** الاعتماد المباشر بلا مقترح كان سيصير باباً خلفيّاً — الشرط نفسه عليه. */
    public function test_an_admin_cannot_approve_without_a_summary_or_a_reason(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal())
            ->assertStatus(422);

        $this->assertSame(TicketStatus::AwaitingDocs->value, $ticket->fresh()->status);
        $this->assertSame(0, Execution::where('ticket_id', $ticket->id)->count(), 'وُلد ملفّ تنفيذ بلا ملخّص.');
    }

    public function test_an_admin_waiver_that_is_too_short_is_refused(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => 'عاجل']))
            ->assertStatus(422);

        $this->assertSame(TicketStatus::AwaitingDocs->value, $ticket->fresh()->status);
    }

    /** المسار السريع: الإدارة تمضي بسببٍ مكتوب، والسبب في سجلّ الرحلة وفي التدقيق. */
    public function test_the_admin_fast_path_records_its_reason_in_the_journey_and_the_audit(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]))
            ->assertRedirect();

        $this->assertSame(TicketStatus::ConvertedToExecution->value, $ticket->fresh()->status);

        $row = JourneyTransition::where('entity_id', $ticket->id)
            ->where('transition', 'ticket.approve_outcome_track')->firstOrFail();
        $this->assertTrue($row->payload['summary_waived'] ?? false, 'لم يُقيَّد التجاوز في سجلّ الرحلة.');
        $this->assertSame(self::WAIVER, $row->payload[OutcomeSummaryGate::WAIVER] ?? null);

        $audit = AuditLog::where('action', 'تجاوز شرط الملخّص المعتمد')->first();
        $this->assertNotNull($audit, 'لم يُقيَّد التجاوز في سجلّ التدقيق.');
        $this->assertStringContainsString(self::WAIVER, (string) $audit->description);
    }

    /** والمقترح كذلك: الإدارة ترفعه بسببٍ مكتوب عبر الانتقال نفسه. */
    public function test_an_admin_may_propose_with_a_written_reason(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.propose', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]))
            ->assertRedirect();

        $this->assertSame(TicketStatus::AwaitingAdminOutcomeApproval->value, $ticket->fresh()->status);
        $row = JourneyTransition::where('entity_id', $ticket->id)
            ->where('transition', 'ticket.propose_outcome_track')->firstOrFail();
        $this->assertSame(self::WAIVER, $row->payload[OutcomeSummaryGate::WAIVER] ?? null);
    }

    /**
     * **سببٌ واحد لمن رفع المقترح واعتمده** (قرار المالك 2026-09-25): المدير الذي رفع المقترح
     * متجاوزاً بسببٍ مدوَّن لا يُطالَب به ثانيةً ليعتمد مقترحه — يُورَث من سجلّ الرحلة، ويُقيَّد
     * في سطر الاعتماد أيضاً فلا يضيع أثره.
     */
    public function test_the_admin_who_proposed_with_a_reason_approves_without_repeating_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.propose', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('ticket.trackGovernance.inheritedWaiver', self::WAIVER));

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal())
            ->assertRedirect();

        $this->assertSame(TicketOutcomeTrack::Execution->value, $ticket->fresh()->approved_track, 'طُولب المدير بالسبب ثانيةً.');
        $row = JourneyTransition::where('entity_id', $ticket->id)
            ->where('transition', 'ticket.approve_outcome_track')->firstOrFail();
        $this->assertSame(self::WAIVER, $row->payload[OutcomeSummaryGate::WAIVER] ?? null, 'الاعتماد بلا أثرٍ للتجاوز.');
    }

    /** **والتجاوز مسؤوليّةُ من يقرّره:** إن رفعه مديرٌ فاعتمده غيرُه، طُلب السبب من المعتمِد. */
    public function test_another_admin_must_write_their_own_reason_to_approve(): void
    {
        $proposer = User::factory()->create(['role' => Role::Admin]);
        $approver = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->bareTicket();

        $this->actingAs($proposer)
            ->post(route('admin.tickets.track.propose', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]))
            ->assertRedirect();

        $this->actingAs($approver)
            ->get(route('admin.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('ticket.trackGovernance.inheritedWaiver', null));

        $this->actingAs($approver)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal())
            ->assertStatus(422);
        $this->assertNull($ticket->fresh()->approved_track);
    }

    /**
     * **التجاوز امتيازٌ للإدارة العليا وحدها — في المحرّك لا في المتحكّم.** متحكّما الموظّف
     * والمحامي لا يمرّران الحقل أصلاً، لكنّ أيّ منادٍ للانتقال يُردّ (403).
     */
    public function test_a_non_admin_never_gets_the_fast_path_from_the_engine(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->bareTicket();

        try {
            Workflow::run(new ProposeOutcomeTrack, $ticket, $employee, $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]));
            $this->fail('مضى موظّفٌ بالمسار السريع.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($ticket->fresh()->proposed_track);
    }

    /** سببٌ أُرسل والملخّص معتمد لا يُسجَّل تجاوزاً: لم يُتجاوز شيء. */
    public function test_no_waiver_is_recorded_when_the_summary_is_approved(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->approveOpinionOf($this->bareTicket());

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), $this->proposal([OutcomeSummaryGate::WAIVER => self::WAIVER]))
            ->assertRedirect();

        $row = JourneyTransition::where('entity_id', $ticket->id)
            ->where('transition', 'ticket.approve_outcome_track')->firstOrFail();
        $this->assertNull($row->payload);
        $this->assertSame(0, AuditLog::where('action', 'تجاوز شرط الملخّص المعتمد')->count());
    }

    /** البطاقة تقرأ المانع من الخادم — من المصدر نفسه الذي يحرس الانتقال. */
    public function test_the_card_receives_the_blocker_from_the_server(): void
    {
        $ticket = $this->bareTicket();
        $this->assertStringContainsString('يلزم ملخّصٌ معتمد', (string) $ticket->trackGovernance()['outcomeBlocker']);

        $this->approveOpinionOf($ticket);
        $this->assertNull($ticket->fresh()->trackGovernance()['outcomeBlocker']);
    }
}
