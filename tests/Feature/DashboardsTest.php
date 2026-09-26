<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Execution;
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
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP1', 'type' => 'استشارة', 'ico' => 'office', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد', 'time' => '10ص', 'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV1', 'description' => 'أتعاب', 'amount' => 5000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);
        // تنفيذ من النمط القديم (stage=null) + تنفيذ من تدفّق «المرحلة 2» الجديد (stage=1، نشط) — كلاهما يُحتسَب
        Execution::create(['user_id' => $client->id, 'number' => 'EXE1', 'subject' => 'تنفيذ', 'status' => 'جديد', 'tone' => 'b-blue']);
        Execution::create(['user_id' => $client->id, 'number' => 'EXE2', 'subject' => 'تنفيذ جديد', 'status' => 'تحليل ذكي', 'tone' => 'b-blue', 'stage' => 1]);
        // تنفيذ مغلق (stage=9) لا يُحتسَب ضمن «ملفات التنفيذ» النشطة
        Execution::create(['user_id' => $client->id, 'number' => 'EXE3', 'subject' => 'تنفيذ مغلق', 'status' => 'مغلق', 'tone' => 'b-grey', 'stage' => 9]);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('dashboard')
            ->where('counts.openTickets', 1)   // T2 مكتملة مستثناة
            ->where('counts.upAppts', 1)
            ->where('counts.dueInv', 1)
            ->where('counts.myExec', 2)
            ->has('upcomingAppts', 1)
            ->has('dueInvoices', 1)
            ->where('lastTicket.no', 'T2'));
    }

    public function test_client_dashboard_lists_are_scoped_and_filtered(): void
    {
        $me = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);

        // بيانات عميل آخر — يجب ألّا تظهر
        Appointment::create(['user_id' => $other->id, 'ext_id' => 'AP-X', 'type' => 'استشارة', 'ico' => 'office', 'lawyer' => 'أ. خالد', 'day' => 'الاثنين', 'time' => '9ص', 'place' => 'جدة', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);
        Invoice::create(['user_id' => $other->id, 'number' => 'INV-X', 'description' => 'أتعاب', 'amount' => 999, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false]);

        // موعدي: قادم (يظهر) وسابق (مستثنى)
        Appointment::create(['user_id' => $me->id, 'ext_id' => 'AP-UP', 'type' => 'استشارة', 'ico' => 'office', 'lawyer' => 'أ. سارة', 'day' => 'الأحد', 'time' => '10ص', 'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);
        Appointment::create(['user_id' => $me->id, 'ext_id' => 'AP-PAST', 'type' => 'استشارة', 'ico' => 'office', 'lawyer' => 'أ. سارة', 'day' => 'أمس', 'time' => '10ص', 'place' => 'الرياض', 'status' => 'منتهٍ', 'tone' => 'b-grey', 'when_kind' => 'past']);

        // فاتورتي: مستحقّة (تظهر) ومدفوعة (مستثناة)
        Invoice::create(['user_id' => $me->id, 'number' => 'INV-DUE', 'description' => 'أتعاب', 'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false]);
        Invoice::create(['user_id' => $me->id, 'number' => 'INV-PAID', 'description' => 'أتعاب', 'amount' => 700, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => 'أمس', 'paid' => true]);

        $this->actingAs($me)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('dashboard')
            ->where('counts.upAppts', 1)
            ->where('counts.dueInv', 1)
            ->has('upcomingAppts', 1)
            ->where('upcomingAppts.0.id', 'AP-UP')
            ->has('dueInvoices', 1)
            ->where('dueInvoices.0.no', 'INV-DUE'));
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
        // الاعتماد لا يُطلب إلا بعد انعقاد الاجتماع — العدّاد يحصر المنتهية
        Meeting::create(['user_id' => $client->id, 'ref' => 'MTG1', 'title' => 'اجتماع', 'type' => 'عميل', 'when_label' => 'الأحد', 'status' => 'منتهٍ', 'approve' => 'بانتظار اعتماد الإدارة']);
        Meeting::create(['user_id' => $client->id, 'ref' => 'MTG2', 'title' => 'اجتماع قادم', 'type' => 'عميل', 'when_label' => 'غد', 'status' => 'قادم', 'approve' => 'بانتظار اعتماد الإدارة']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/dashboard')
            // الحزمتان القديمتان `stats`/`activity` أُسقطتا — الأرقام من `AdminDashboardService` وحده
            ->where('overview.clientsCount', 1)
            ->where('overview.openTickets', 1)
            ->where('finance.totalCollected', 8000)
            ->where('radar', fn ($radar) => collect($radar)->firstWhere('id', 'pending-meetings')['count'] === 1)
            ->has('liveActivity')
            ->missing('stats')
            ->missing('activity'));
    }

    public function test_client_calendar_shows_events(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP1', 'type' => 'استشارة حضورية', 'ico' => 'office', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد 12 يوليو', 'time' => '11ص', 'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('calendar')
            ->has('events', 1)
            ->where('events.0.title', 'موعد: استشارة حضورية'));
    }
}
