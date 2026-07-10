<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحات المعلومات الثلاث + تقويم العميل — عدّادات وأحداث حقيقية.
 */
class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_shows_real_counts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create(['user_id' => $client->id, 'number' => 'T1', 'type' => 'تجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
        Ticket::create(['user_id' => $client->id, 'number' => 'T2', 'type' => 'تجاري', 'status' => 'مكتملة', 'tone' => 'b-green']);
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP1', 'type' => 'استشارة', 'ico' => 'office', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد', 'time' => '10ص', 'branch' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV1', 'description' => 'أتعاب', 'amount' => 5000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('dashboard')
            ->where('counts.openTickets', 1)   // T2 مكتملة مستثناة
            ->where('counts.upAppts', 1)
            ->where('counts.dueInv', 1)
            ->where('lastTicket.no', 'T2'));
    }

    public function test_employee_dashboard_lists_active_tickets(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create(['user_id' => $client->id, 'number' => 'T1', 'type' => 'تجاري', 'status' => 'بانتظار مستندات', 'tone' => 'b-amber']);
        Ticket::create(['user_id' => $client->id, 'number' => 'T2', 'type' => 'عمالي', 'status' => 'مغلقة', 'tone' => 'b-grey']);

        $this->actingAs($employee)->get(route('employee.dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('employee/dashboard')
            ->where('counts.needAction', 1)
            ->where('counts.missingDocs', 1)
            ->has('tickets', 1));
    }

    public function test_admin_dashboard_shows_real_stats(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create(['user_id' => $client->id, 'number' => 'T1', 'type' => 'تجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV1', 'description' => 'أتعاب', 'amount' => 8000, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => 'اليوم', 'paid' => true]);
        Meeting::create(['user_id' => $client->id, 'ref' => 'MTG1', 'title' => 'اجتماع', 'type' => 'عميل', 'when_label' => 'الأحد', 'status' => 'قادم', 'approve' => 'بانتظار اعتماد الإدارة']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/dashboard')
            ->where('stats.clients', 1)
            ->where('stats.openTickets', 1)
            ->where('stats.revenue', 8000)
            ->where('stats.pendingMeetings', 1)
            ->has('activity'));
    }

    public function test_client_calendar_shows_events_with_gcal_links(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP1', 'type' => 'استشارة حضورية', 'ico' => 'office', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد 12 يوليو', 'time' => '11ص', 'branch' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('calendar')
            ->has('events', 1)
            ->where('events.0.gcal', fn ($url) => str_contains($url, 'calendar.google.com')));
    }
}
