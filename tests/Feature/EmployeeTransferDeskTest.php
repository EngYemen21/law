<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeTransferDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_employee_can_view_transfer_hub_with_workload_metrics(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'حمد القحطاني']);
        $lawyer1 = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. فهد السبيعي', 'status' => 'active']);
        $lawyer2 = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. نورة الشمري', 'status' => 'active']);

        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-301',
            'type' => 'تأسيس شركات',
            'department' => 'الشركات',
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
            'assigned_lawyer' => $lawyer1->name,
            'assigned_lawyer_id' => $lawyer1->id,
        ]);

        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-302',
            'type' => 'نزاع عقاري',
            'department' => 'العقارات',
            'status' => 'جديدة',
            'tone' => 'b-amber',
            'assigned_lawyer' => null,
            'assigned_lawyer_id' => null,
        ]);

        $response = $this->actingAs($employee)->get('/employee/transfer');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/transfer')
            ->has('tickets', 2)
            ->has('lawyers', 2)
            ->has('counts')
            ->where('counts.unassigned', 1)
            ->where('counts.assigned', 1)
            ->has('departments')
            ->has('recentTransfers')
        );
    }

    public function test_employee_can_execute_single_ticket_transfer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سالم الدوسري']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. تركي الفهد', 'status' => 'active']);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-303',
            'type' => 'قضايا عمالية',
            'department' => 'العمالي',
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
        ]);

        $response = $this->actingAs($employee)->post("/employee/transfer/{$ticket->number}", [
            'lawyer_id' => $lawyer->id,
            'reason' => 'إحالة حسب الاختصاص',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tickets', [
            'number' => 'SB-2026-303',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'role' => 'تحويل',
        ]);
    }

    public function test_employee_can_execute_bulk_ticket_transfer(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'فيصل الحربي']);
        $targetLawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سلطان العتيبي', 'status' => 'active']);

        $t1 = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-401',
            'type' => 'تجاري',
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
        ]);

        $t2 = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-402',
            'type' => 'عقاري',
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
        ]);

        $response = $this->actingAs($employee)->post('/employee/transfer/bulk', [
            'tickets' => ['SB-2026-401', 'SB-2026-402'],
            'lawyer_id' => $targetLawyer->id,
            'reason' => 'إعادة موازنة عبء العمل',
        ]);

        $response->assertRedirect();
        $this->assertSame($targetLawyer->id, $t1->fresh()->assigned_lawyer_id);
        $this->assertSame($targetLawyer->id, $t2->fresh()->assigned_lawyer_id);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $t1->id,
            'role' => 'تحويل',
        ]);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $t2->id,
            'role' => 'تحويل',
        ]);
    }
}
