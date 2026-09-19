<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\AssignTicketJob;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **قفزة «محالة للقسم القانوني» عند الإسناد تمرّ بالمحرّك** (`ReferOnAssignment`).
 *
 * كان أربعة كتّاب ينسخون الشرط والحقول ويكتبون الحالة مباشرةً: الإسناد الأوّل عند الفتح،
 * والتوزيع الآليّ، والتصعيد للإدارة، والإسناد اليدويّ. الآن مصدرٌ واحد (`TicketAssignment::write`).
 * والسلوك المرئيّ كما كان: القفزة من «جديدة»/«قيد التحليل» وحدهما، والإسناد بلا قفزة فيما عداهما.
 */
class TicketAssignmentThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_agent.enabled' => false]);
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    private function ticket(string $status): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-AS-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => 'b-blue', 'last_message' => '—',
        ]);
    }

    public function test_manual_assignment_of_an_early_ticket_is_a_recorded_referral(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = $this->ticket('قيد التحليل');

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), ['lawyer_id' => $lawyer->id])
            ->assertSessionHas('flash', "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.");

        $ticket->refresh();
        $this->assertSame('محالة للقسم القانوني', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $ticket->assigned_lawyer);
        $this->assertSame('تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.', $ticket->last_message);

        $row = JourneyTransition::where('transition', 'ticket.referred_on_assignment')->sole();
        $this->assertSame('قيد التحليل', $row->from_state);
        $this->assertSame('محالة للقسم القانوني', $row->to_state);
        $this->assertSame($admin->id, $row->actor_id);
    }

    /** خارج الحالتين يُسنَد وحده كما كان — لا قفزة ولا رفض ولا قيد انتقال. */
    public function test_manual_assignment_later_in_the_journey_keeps_the_status(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = $this->ticket('الرأي القانوني');

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), ['lawyer_id' => $lawyer->id])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('الرأي القانوني', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame(0, JourneyTransition::count());
    }

    public function test_auto_distribution_job_records_a_system_referral(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto']);
        $ticket = $this->ticket('جديدة');

        (new AssignTicketJob($ticket->id, 'الإدارة'))->handle();

        $ticket->refresh();
        $this->assertSame('محالة للقسم القانوني', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $row = JourneyTransition::where('transition', 'ticket.referred_on_assignment')->sole();
        $this->assertSame('جديدة', $row->from_state);
        $this->assertNull($row->actor_id);
    }

    public function test_escalation_to_management_records_the_referral_under_the_senior_label(): void
    {
        $senior = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->ticket('قيد التحليل');

        (new EscalateUnassignedTicketJob($ticket->id))->handle(app(MailService::class));

        $ticket->refresh();
        $this->assertSame('محالة للقسم القانوني', $ticket->status);
        $this->assertSame($senior->id, $ticket->assigned_lawyer_id);
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $ticket->assigned_lawyer);
        $this->assertSame(1, JourneyTransition::where('transition', 'ticket.referred_on_assignment')->count());
    }

    /** الوكيل مفعّل ⇒ الحالة لمهمّة الفرز (ع٢٠): إسنادٌ بلا قفزة، كما كان. */
    public function test_with_the_triage_agent_enabled_assignment_does_not_move_the_status(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto']);
        $ticket = $this->ticket('قيد التحليل');

        (new AssignTicketJob($ticket->id, 'الإدارة'))->handle();

        $ticket->refresh();
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame(0, JourneyTransition::count());
    }
}
