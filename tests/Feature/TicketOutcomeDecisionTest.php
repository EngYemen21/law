<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات معمارية دورة مآل التذكرة والقرار الصريح النهائي:
 * تحويل لقضية (CONVERTED_TO_CASE) أو إغلاق مسبب (CLOSED_JUSTIFIED) مع تجميد السجل.
 */
class TicketOutcomeDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function readyForOutcomeTicket(User $client, ?User $lawyer = null): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9900',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => TicketStatus::ReadyForOutcome->value,
            'tone' => 'b-amber',
        ]);
    }

    public function test_lawyer_converts_ready_for_outcome_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $ticket = $this->readyForOutcomeTicket($client, $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.convert', $ticket))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ConvertedToCase->value, $ticket->status);
        $this->assertTrue($ticket->is_frozen);
        $this->assertNotNull($ticket->outcome_decision_at);

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($client->id, $case->user_id);
    }

    public function test_lawyer_closes_ticket_with_statutory_closure_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $ticket = $this->readyForOutcomeTicket($client, $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.close', $ticket), [
                'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
                'closure_notes' => 'تم تقديم الرأي والمشورة القانونية واكتفاء المستفيد بما ورد فيه.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed->value, $ticket->status);
        $this->assertSame(ClosureReasonCode::OpinionSatisfied->value, $ticket->closure_reason_code);
        $this->assertSame('تم تقديم الرأي والمشورة القانونية واكتفاء المستفيد بما ورد فيه.', $ticket->closure_notes);
        $this->assertSame($lawyer->id, $ticket->closed_by_id);
        $this->assertTrue($ticket->is_frozen);
        $this->assertNotNull($ticket->outcome_decision_at);
    }

    public function test_admin_can_close_ticket_with_statutory_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->readyForOutcomeTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.tickets.close', $ticket), [
                'closure_reason_code' => ClosureReasonCode::SettledAmicably->value,
                'closure_notes' => 'تم التوصل إلى تسوية ودية بين الطرفين.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed->value, $ticket->status);
        $this->assertSame(ClosureReasonCode::SettledAmicably->value, $ticket->closure_reason_code);
        $this->assertTrue($ticket->is_frozen);
        $this->assertSame($admin->id, $ticket->closed_by_id);
    }

    public function test_frozen_ticket_cannot_be_converted_or_closed_again(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->readyForOutcomeTicket($client, $lawyer);

        // إغلاق التذكرة
        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.close', $ticket), [
                'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertTrue($ticket->is_frozen);

        // محاولة إغلاق مرة أخرى تفشل
        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.close', $ticket), [
                'closure_reason_code' => ClosureReasonCode::SettledAmicably->value,
            ])
            ->assertSessionHasErrors('ticket');

        // محاولة تحويل التذكرة المغلقة تفشل
        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.convert', $ticket))
            ->assertSessionHasErrors('ticket');
    }

    /** العميل ليس له صلاحية «إدارة القضايا والأتعاب» — الميدلوير يعيد التوجيه لا 403 */
    public function test_client_cannot_convert_or_close_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->readyForOutcomeTicket($client);

        // الميدلوير permission: يعيد التوجيه (302) لا يُرجع 403 — العميل ليس لديه الدور أصلاً
        $convertResponse = $this->actingAs($client)
            ->post(route('lawyer.tickets.convert', $ticket));
        $this->assertTrue(in_array($convertResponse->status(), [302, 403]));

        $closeResponse = $this->actingAs($client)
            ->post(route('lawyer.tickets.close', $ticket));
        $this->assertTrue(in_array($closeResponse->status(), [302, 403]));

        // التذكرة لم تتغير — لا تحويل ولا إغلاق
        $ticket->refresh();
        $this->assertSame(TicketStatus::ReadyForOutcome->value, $ticket->status);
        $this->assertFalse($ticket->is_frozen);
    }
}
