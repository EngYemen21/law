<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **القضيّة المحوَّلة تُفتح داخل المحرّك** — سطرُ فتحٍ (`from_state = null`) بالفاعل، لا كتابةً مجهولة.
 */
class CaseConversionOpensThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_converting_a_ticket_records_the_case_opening(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-9170', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, 'status' => 'مكتملة', 'tone' => 'b-green',
        ]);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لتحويل التذكرة إلى ملف قضية رسمي.',
        ])->assertRedirect();

        $case = LegalCase::where('ticket_id', $ticket->id)->sole();
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);

        $row = JourneyTransition::where('entity_type', 'LegalCase')->where('entity_id', $case->id)->sole();
        $this->assertSame('case.open_from_outcome', $row->transition);
        $this->assertNull($row->from_state);
        $this->assertSame('بانتظار اعتماد الأتعاب', $row->to_state);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame($case->number, $row->entity_ref);
    }
}
