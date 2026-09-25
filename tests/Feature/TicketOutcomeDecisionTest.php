<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApprovesTicketSummary;
use Tests\TestCase;

/**
 * اختبارات معمارية دورة مآل التذكرة والقرار الصريح النهائي:
 * تحويل لقضية (CONVERTED_TO_CASE) أو إغلاق مسبب (CLOSED_JUSTIFIED) مع تجميد السجل.
 */
class TicketOutcomeDecisionTest extends TestCase
{
    use ApprovesTicketSummary;
    use RefreshDatabase;

    /** تذكرةٌ «بانتظار قرار المآل» — وملخّصها معتمد كما في الرحلة الحقيقيّة (شرط قرار المآل، ث٥). */
    private function readyForOutcomeTicket(User $client, ?User $lawyer = null): Ticket
    {
        return $this->approveTicketSummary(Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9900',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => TicketStatus::ReadyForOutcome->value,
            'tone' => 'b-amber',
        ]));
    }

    public function test_lawyer_converts_ready_for_outcome_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->readyForOutcomeTicket($client, $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'النزاع التجاري يتطلب إقامة دعوى قضائية أمام المحكمة التجارية.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'اعتماد الإدارة العليا لمسار القضية وإحالتها للفريق القضائي.',
            ])
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
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->readyForOutcomeTicket($client, $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Close->value,
                'reason' => 'تم تقديم الرأي والمشورة القانونية واكتفاء المستفيد بما ورد فيه.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Close->value,
                'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
                'reason' => 'تم تقديم الرأي والمشورة القانونية واكتفاء المستفيد بما ورد فيه.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed->value, $ticket->status);
        $this->assertSame(ClosureReasonCode::OpinionSatisfied->value, $ticket->closure_reason_code);
        $this->assertSame('تم تقديم الرأي والمشورة القانونية واكتفاء المستفيد بما ورد فيه.', $ticket->closure_notes);
        $this->assertSame($admin->id, $ticket->approved_by_id);
        $this->assertTrue($ticket->is_frozen);
        $this->assertNotNull($ticket->outcome_decision_at);
    }

    public function test_admin_can_close_ticket_with_statutory_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->readyForOutcomeTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Close->value,
                'closure_reason_code' => ClosureReasonCode::SettledAmicably->value,
                'reason' => 'تم التوصل إلى تسوية ودية بين الطرفين ولا حاجة لإجراء إضافي.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed->value, $ticket->status);
        $this->assertSame(ClosureReasonCode::SettledAmicably->value, $ticket->closure_reason_code);
        $this->assertTrue($ticket->is_frozen);
        $this->assertSame($admin->id, $ticket->approved_by_id);
    }

    public function test_frozen_ticket_cannot_be_converted_or_closed_again(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->readyForOutcomeTicket($client);

        // إغلاق التذكرة عبر مسار الحوكمة
        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Close->value,
                'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
                'reason' => 'تم تقديم الرأي والمشورة القانونية للعميل.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertTrue($ticket->is_frozen);

        // محاولة اعتماد مسار مرة أخرى على تذكرة مجمدة تفشل بالحارس برمز 422
        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'محاولة تحويل بعد التجميد النهائي للطلب.',
            ])
            ->assertStatus(422);
    }

    /** العميل ليس له صلاحية اعتماد المسار — الميدلوير يعيد التوجيه لا 403 */
    public function test_client_cannot_convert_or_close_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->readyForOutcomeTicket($client);

        $response = $this->actingAs($client)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'محاولة اعتماد غير مصرحة للعميل.',
            ]);
        $this->assertTrue(in_array($response->status(), [302, 403]));

        // التذكرة لم تتغير — لا تحويل ولا إغلاق
        $ticket->refresh();
        $this->assertSame(TicketStatus::ReadyForOutcome->value, $ticket->status);
        $this->assertFalse($ticket->is_frozen);
    }
}
