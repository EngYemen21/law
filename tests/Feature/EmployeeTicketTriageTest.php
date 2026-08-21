<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeTicketTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_employee_can_view_rich_ticket_triage_dashboard(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر', 'الرد على العملاء', 'تحويل التذاكر'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سعد المنصور', 'phone' => '0501112233']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار القانوني فهد']);

        $ticket1 = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-1001',
            'department' => 'قضايا الشركات',
            'type' => 'تأسيس شركات',
            'priority' => 'عالية',
            'status' => 'بانتظار مستندات',
            'tone' => 'b-amber',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $ticket2 = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-1002',
            'department' => 'العقارات والمقاولات',
            'type' => 'نزاع عقاري',
            'priority' => 'متوسطة',
            'status' => 'محالة للقسم القانوني',
            'tone' => 'b-cyan',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $response = $this->actingAs($employee)->get('/employee/tickets');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/tickets')
            ->has('tickets', 2)
            ->has('counts')
            ->where('counts.missingDocs', 1)
            ->where('counts.referred', 1)
            ->where('counts.urgent', 1)
            ->has('departments')
            ->has('lawyers')
        );
    }

    public function test_employee_can_view_ticket_chat_with_customer_context(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر', 'الرد على العملاء', 'تحويل التذاكر'])->get());

        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عبدالله خالد', 'phone' => '0559988776']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة أحمد']);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-1003',
            'department' => 'القضايا العمالية',
            'type' => 'مستحقات نهاية الخدمة',
            'priority' => 'عالية',
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-101',
            'title' => 'دعوى عمالية',
            'type' => 'عمالي',
            'court' => 'المحكمة العمالية بالرياض',
            'status' => 'جارية',
            'lawyer_id' => $lawyer->id,
        ]);

        $response = $this->actingAs($employee)->get("/employee/tickets/{$ticket->number}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/ticketchat')
            ->has('ticket')
            ->where('ticket.no', $ticket->number)
            ->has('clientStats')
            ->where('clientStats.totalTickets', 1)
            ->where('clientStats.totalCases', 1)
            ->has('messages')
            ->has('states')
            ->has('lawyers')
        );
    }
}
