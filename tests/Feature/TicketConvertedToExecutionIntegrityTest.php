<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketConvertedToExecutionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_converted_to_execution_sets_status_creates_record_and_notifies_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TCK-EXE-100',
            'type' => 'تجاري',
            'department' => 'القسم التجاري',
            'status' => TicketStatus::ReadyForOutcome->value,
            'tone' => 'b-amber',
            'subject' => 'سند لأمر واجب السداد بمبلغ 50000',
            'claim_amount' => 50000,
            'opponent_name' => 'شركة الرياض التجارية',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $reason = 'ثبوت السند التنفيذي المستوفي لكافة الأركان النظامية مما يستوجب قيد طلب تنفيذ لدى محكمة التنفيذ.';

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.track.approve', $ticket), [
                'track' => TicketOutcomeTrack::Execution->value,
                'reason' => $reason,
            ]);

        $response->assertRedirect();
        $ticket->refresh();

        // 1. حالة التذكرة الدقيقة والمجمدة
        $this->assertSame(TicketStatus::ConvertedToExecution->value, $ticket->status);
        $this->assertSame('محولة إلى تنفيذ', $ticket->status);
        $this->assertSame(TicketOutcomeTrack::Execution->value, $ticket->approved_track);
        $this->assertSame('b-amber', $ticket->tone);
        $this->assertTrue($ticket->is_frozen);
        $this->assertTrue(TicketStatus::ConvertedToExecution->isTerminal());
        $this->assertTrue(TicketStatus::ConvertedToExecution->isFinal());
        $this->assertSame('تم تحويل الطلب إلى ملف تنفيذ قضائي', TicketStatus::ConvertedToExecution->clientLabel());

        // 2. التحقق من إنشاء سجل التنفيذ وربطه
        $exec = Execution::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($exec);
        $this->assertSame($client->id, $exec->user_id);
        $this->assertSame(50000, $exec->amount);
        $this->assertSame('شركة الرياض التجارية', $exec->defendant);

        // 3. التحقق من بطاقة العميل toCard
        $card = $ticket->toCard();
        $this->assertTrue($card['isTerminal']);
        $this->assertTrue($card['isFrozen']);
        $this->assertTrue($card['hasExecution']);
        $this->assertSame($exec->number, $card['executionNumber']);
        $this->assertFalse($card['hasCase']);

        // 4. التحقق من بطاقة الموظف toEmployeeCard
        $empCard = $ticket->toEmployeeCard();
        $this->assertTrue($empCard['isTerminal']);
        $this->assertTrue($empCard['isFrozen']);
        $this->assertTrue($empCard['hasExecution']);
        $this->assertSame($exec->number, $empCard['executionNumber']);
        $this->assertFalse($empCard['canDecideOutcome']);

        // 5. التحقق من بطاقة قائمة الموظف عبر Employee\TicketController
        $emp = User::factory()->create(['role' => Role::Employee]);
        Permission::firstOrCreate(['name' => 'إدارة التذاكر', 'guard_name' => 'web']);
        $emp->syncPermissions(['إدارة التذاكر']);
        $empRes = $this->actingAs($emp)->get(route('employee.tickets'));
        $empRes->assertOk();
        $empTickets = collect($empRes->viewData('page')['props']['tickets']);
        $matchedEmpCard = $empTickets->firstWhere('no', $ticket->number);
        $this->assertNotNull($matchedEmpCard);
        $this->assertTrue($matchedEmpCard['converted']);
        $this->assertSame('execution', $matchedEmpCard['convertedType']);

        // 6. التحقق من قائمة وتفاصيل المحامي عبر Lawyer\TicketController
        $lawyerRes = $this->actingAs($lawyer)->get(route('lawyer.tickets'));
        $lawyerRes->assertOk();
        $lawyerTickets = collect($lawyerRes->viewData('page')['props']['tickets']);
        $matchedLawyerCard = $lawyerTickets->firstWhere('no', $ticket->number);
        $this->assertNotNull($matchedLawyerCard);
        $this->assertTrue($matchedLawyerCard['converted']);
        $this->assertSame('execution', $matchedLawyerCard['convertedType']);
        $this->assertSame($exec->number, $matchedLawyerCard['execRef']);

        $showRes = $this->actingAs($lawyer)->get(route('lawyer.tickets.show', $ticket));
        $showRes->assertOk();
        $showTicketProps = $showRes->viewData('page')['props'];
        $this->assertTrue($showTicketProps['converted']);
        $this->assertSame('execution', $showTicketProps['convertedType']);
        $this->assertSame($exec->number, $showTicketProps['ticket']['execRef']);

        // 7. التحقق من تقارير الإدارة وعدم تداخل مؤشرات التنفيذ مع القضايا
        $reportRes = $this->actingAs($admin)->get(route('admin.reports'));
        $reportRes->assertOk();
        $stats = $reportRes->viewData('page')['props']['stats'];
        $this->assertSame(1, $stats['convertedToExecution']);
        $this->assertSame(0, $stats['convertedToCase']); // لم تُحسب كقضية
        $this->assertSame(0, $stats['activeTickets']);    // استُثنيت من النشطة لأنها مجمدة
    }
}
