<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * حوكمة مسار الاستشارة القانونية (ADR-009: Single Source of Truth & Zero Bypass).
 *
 * أُزيل زرّ الالتفاف السريع ومسار convert-consult؛ وصار توجيه التذكرة لمسار الاستشارة
 * يمرّ حصراً عبر دورة الحوكمة المعتمدة:
 * اقتراح مسار مسبّب ➔ اعتماد الإدارة العليا المشروط بالتسبيب ➔ انتقال الدومين عبر ApproveOutcomeTrack.
 */
class EmployeeConvertToConsultTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** @return array{0: Ticket, 1: User, 2: User, 3: User} */
    private function ticketFixture(string $status = 'الرأي القانوني'): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-2026-7100',
            'type' => 'استشارة قانونية',
            'department' => 'القانون التجاري',
            'status' => $status,
        ]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        return [$ticket, $employee, $admin, $client];
    }

    public function test_employee_proposes_consultation_track_with_reason(): void
    {
        [$ticket, $employee] = $this->ticketFixture();

        $response = $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'المسألة تتطلب عقد جلسة استشارية متخصصة لدراسة بنود عقد الشراكة.',
            ]);

        $response->assertRedirect();
        $ticket->refresh();
        $this->assertSame(TicketOutcomeTrack::Consultation->value, $ticket->proposed_track);
        $this->assertNotNull($ticket->proposed_at);
        $this->assertSame($employee->id, $ticket->proposed_by_id);
    }

    public function test_admin_approves_consultation_track_and_transitions_ticket(): void
    {
        [$ticket, , $admin, $client] = $this->ticketFixture();

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'نوافق على الرأي ونوجّه بعقد جلسة استشارية مع المستشار المختص.',
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        // انتقال الحالة الرسمي في الدومين
        $this->assertSame(TicketStatus::AwaitingBooking->value, $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Consultation->value, $ticket->approved_track);
        $this->assertSame($admin->id, $ticket->approved_by_id);
        $this->assertNotNull($ticket->approved_track_at);

        // نشر قرار الإدارة والتسبيب في رسائل المحادثة للعميل
        $this->assertTrue($ticket->messages->contains(
            fn ($m) => $m->who === 'admin' && str_contains($m->body, 'قرار الإدارة العليا: توجيه بطلب استشارة قانونية')
        ));
    }

    public function test_rejects_track_approval_with_short_reason(): void
    {
        [$ticket, , $admin] = $this->ticketFixture();

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'قصير', // أقل من 10 أحرف
            ])
            ->assertSessionHasErrors('reason');

        $this->assertNotSame(TicketStatus::AwaitingBooking->value, $ticket->fresh()->status);
    }

    public function test_rejects_track_approval_on_frozen_ticket(): void
    {
        [$ticket, , $admin] = $this->ticketFixture();
        $ticket->update(['is_frozen' => true]);

        $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'محاولة اعتماد مسار على تذكرة مجمّدة مسبقاً.',
            ]);

        // الحارس يمنع التعديل على التذكرة المجمدة
        $this->assertNull($ticket->fresh()->approved_track);
    }

    public function test_non_admin_cannot_approve_outcome_track(): void
    {
        [$ticket, $employee] = $this->ticketFixture();

        $this->actingAs($employee)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'محاولة اعتماد غير مصرحة من موظف عادي.',
            ])
            ->assertRedirect('/employee/dashboard');

        $this->actingAs($employee)
            ->postJson(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Consultation->value,
                'reason' => 'محاولة اعتماد غير مصرحة من موظف عادي.',
            ])
            ->assertForbidden();
    }
}
