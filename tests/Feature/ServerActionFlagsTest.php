<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الزرّ يظهر حين يقبل الخادم فعله** — كلّ علمٍ هنا مشتقٌّ من حارس المسار نفسه، فلا يعرض
 * زرٌّ ما يردّه الخادم ٤٢٢ (خطّة «إزالة التعارض بين الواجهات» ١-ج).
 */
class ServerActionFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function consult(array $attrs = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-FLG-'.uniqid(),
            'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => ConsultStatus::ReferredToLawyer->value, 'session' => SessionState::Waiting->value,
            'tone' => 'b-blue', 'lawyer' => 'مستشار', 'starts_at' => now()->addHours(3),
        ], $attrs));
    }

    public function test_the_summary_button_follows_the_approval_guard(): void
    {
        $cancelled = $this->consult(['status' => ConsultStatus::Cancelled->value, 'summary' => 'ملخّص']);
        $ended = $this->consult(['status' => ConsultStatus::Ended->value, 'session' => SessionState::Ended->value, 'summary' => 'ملخّص']);

        $this->assertFalse($cancelled->toCard()['canApproveSummary'], 'ملغاةٌ لم تنعقد جلستها عُرض لها زرّ الاعتماد.');
        $this->assertTrue($ended->toCard()['canApproveSummary']);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post("/admin/consults/{$cancelled->id}/summary/approve");
        $this->assertNull($cancelled->fresh()->summary_approved_at, 'الخادم اعتمد ما يخفيه الزرّ.');
    }

    public function test_no_show_is_offered_only_while_the_session_waits_past_its_time(): void
    {
        $missed = $this->consult(['starts_at' => now()->subHours(3)]);
        $notHeld = $this->consult(['starts_at' => now()->subHours(3), 'session' => SessionState::NotHeld->value, 'status' => ConsultStatus::NoShow->value]);
        $upcoming = $this->consult();

        $this->assertTrue($missed->toCard()['canMarkNoShow']);
        $this->assertFalse($notHeld->toCard()['canMarkNoShow'], 'وُسمت «لم تُعقد» وما زال الزرّ يُعرض.');
        $this->assertFalse($upcoming->toCard()['canMarkNoShow']);
    }

    private function ticketWithSummary(array $ticketAttrs = [], array $summaryAttrs = []): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create(array_merge([
            'user_id' => $client->id, 'number' => 'SB-FLG-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber', 'last_message' => '—', 'date_label' => 'الآن',
        ], $ticketAttrs));
        TicketSummary::create(array_merge([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص', 'attachments_summary' => 'مرفقات',
            'facts' => 'وقائع', 'key_points' => 'نقاط', 'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ], $summaryAttrs));

        return $ticket->fresh();
    }

    /** قاعدةٌ واحدة لإعادة التحليل (قرار المالك): لا بعد اعتماد المستشار، ولا لتذكرةٍ مجمَّدة. */
    public function test_one_rerun_rule_for_every_route_and_button(): void
    {
        $open = $this->ticketWithSummary();
        $frozen = $this->ticketWithSummary(['is_frozen' => true]);
        $lawyerApproved = $this->ticketWithSummary([], ['lawyer_approved_at' => now()]);

        $this->assertNull($open->summaryRerunBlocker());
        $this->assertNotNull($frozen->summaryRerunBlocker());
        $this->assertNotNull($lawyerApproved->summaryRerunBlocker());

        $employee = User::factory()->create(['role' => Role::Employee]);
        // مسار الموظّف كان يقبلها على المجمَّدة وعلى ما اعتمده المستشار
        $this->actingAs($employee)->post("/employee/tickets/{$frozen->number}/rerun")->assertSessionHasErrors('summary');
        $this->actingAs($employee)->post("/employee/tickets/{$lawyerApproved->number}/rerun")->assertSessionHasErrors('summary');

        $this->actingAs($employee)->get("/employee/tickets/{$frozen->number}")
            ->assertInertia(fn ($p) => $p->where('ticket.canRerunSummary', false));
        $this->actingAs($employee)->get("/employee/tickets/{$open->number}")
            ->assertInertia(fn ($p) => $p->where('ticket.canRerunSummary', true));
    }
}
