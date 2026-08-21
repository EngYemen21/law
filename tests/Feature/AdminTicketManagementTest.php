<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTicketManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function lawyer(string $name = 'المحامي سعد'): User
    {
        static $counter = 100;
        $counter++;

        return User::factory()->create([
            'role' => Role::Lawyer,
            'name' => $name,
            'national_id' => '223344'.$counter,
            'phone' => '056'.str_pad((string) $counter, 7, '0', STR_PAD_LEFT),
        ]);
    }

    private function client(string $name = 'محمد العميل'): User
    {
        static $counter = 200;
        $counter++;

        return User::factory()->create([
            'role' => Role::Client,
            'name' => $name,
            'national_id' => '112233'.$counter,
            'phone' => '055'.str_pad((string) $counter, 7, '0', STR_PAD_LEFT),
        ]);
    }

    public function test_admin_can_view_tickets_index(): void
    {
        $client = $this->client();
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-0001',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة في عقد توريد',
            'status' => 'جديدة',
            'tone' => 'b-blue',
        ]);

        $res = $this->actingAs($this->admin())->get(route('admin.tickets'));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('admin/tickets')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.no', 'SB-2026-0001')
        );
    }

    public function test_admin_can_search_tickets_by_number_and_subject_and_client(): void
    {
        $c1 = $this->client('عبدالله السالم');
        $c2 = $this->client('ماجد الحربي');

        Ticket::create([
            'user_id' => $c1->id,
            'number' => 'SB-2026-1111',
            'type' => 'استشارة عمالية',
            'subject' => 'خلاف في مكافأة نهاية الخدمة',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ]);

        Ticket::create([
            'user_id' => $c2->id,
            'number' => 'SB-2026-2222',
            'type' => 'استشارة عقارية',
            'subject' => 'نزاع في عقد إيجار',
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);

        // بحث برقم التذكرة
        $res = $this->actingAs($this->admin())->get(route('admin.tickets', ['q' => '1111']));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'SB-2026-1111'));

        // بحث باسم العميل
        $res2 = $this->actingAs($this->admin())->get(route('admin.tickets', ['q' => 'ماجد']));
        $res2->assertOk();
        $res2->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'SB-2026-2222'));
    }

    public function test_admin_can_filter_tickets_by_status_department_and_lawyer(): void
    {
        $client = $this->client();
        $lawyer1 = $this->lawyer('سعد الغامدي');
        $lawyer2 = $this->lawyer('فهد الشمري');

        Ticket::create([
            'user_id' => $client->id,
            'number' => 'TICK-COMM',
            'type' => 'تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer_id' => $lawyer1->id,
            'assigned_lawyer' => $lawyer1->name,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ]);

        Ticket::create([
            'user_id' => $client->id,
            'number' => 'TICK-LABOR',
            'type' => 'عمالي',
            'department' => 'القسم العمالي',
            'assigned_lawyer_id' => $lawyer2->id,
            'assigned_lawyer' => $lawyer2->name,
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);

        // فلترة بالقسم
        $res = $this->actingAs($this->admin())->get(route('admin.tickets', ['dept' => 'القسم التجاري']));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'TICK-COMM'));

        // فلترة بالمحامي
        $res2 = $this->actingAs($this->admin())->get(route('admin.tickets', ['lawyer_id' => $lawyer2->id]));
        $res2->assertOk();
        $res2->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'TICK-LABOR'));

        // فلترة بالحالة المكتملة
        $res3 = $this->actingAs($this->admin())->get(route('admin.tickets', ['status' => 'completed']));
        $res3->assertOk();
        $res3->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'TICK-LABOR'));
    }

    public function test_admin_can_filter_tickets_pending_admin_approval(): void
    {
        $client = $this->client();
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TICK-PENDING',
            'type' => 'استشارة',
            'status' => 'بانتظار الإدارة',
            'tone' => 'b-amber',
        ]);

        TicketSummary::create([
            'ticket_id' => $ticket->id,
            'result_status' => 'pending_admin',
            'summary' => 'ملخص الاستشارة بانتظار الاعتماد',
        ]);

        $res = $this->actingAs($this->admin())->get(route('admin.tickets', ['status' => 'pending_admin']));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.no', 'TICK-PENDING'));
    }
}
