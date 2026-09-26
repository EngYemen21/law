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
 * نواقص اللوحات (الدفعة ب) — أرقام معروضة كانت خاطئة وسجلات كانت تختفي.
 */
class DashboardGapsTest extends TestCase
{
    use RefreshDatabase;

    // ── شارات شريط العميل كانت مشتقّة من بيانات وهمية: كل عميل يرى الأرقام نفسها ──

    public function test_client_nav_badges_are_per_user_and_real(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);

        Ticket::create(['user_id' => $client->id, 'number' => 'SB-B-1', 'type' => 'تجاري', 'status' => 'قيد المعالجة', 'tone' => 'b-blue']);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-B-2', 'type' => 'تجاري', 'status' => 'مكتملة', 'tone' => 'b-green']);
        Ticket::create(['user_id' => $other->id, 'number' => 'SB-B-3', 'type' => 'تجاري', 'status' => 'قيد المعالجة', 'tone' => 'b-blue']);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV-B-1', 'description' => 'أتعاب', 'amount' => 100, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);
        Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-B-1', 'type' => 'استشارة', 'ico' => 'video',
            'lawyer' => 'أ. سارة', 'day' => 'غد', 'time' => '10:00', 'starts_at' => now()->addDay(),
            'duration_min' => 45, 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('navBadges./tickets', 1)      // المفتوحة له وحده
                ->where('navBadges./invoices', 1)
                ->where('navBadges./calendar', 1));

        // عميل بلا سجلات يرى أصفاراً — كان يرى الأرقام الوهمية نفسها
        $this->actingAs($other)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('navBadges./invoices', 0));
    }

    public function test_staff_roles_get_no_client_badges(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->get(route('employee.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('navBadges', []));
    }

    // ── عدّاد «بانتظار إجراء» كان يعدّ ما ينتظر طرفاً آخر ──

    public function test_employee_need_action_excludes_tickets_awaiting_others(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        Ticket::create(['user_id' => $client->id, 'number' => 'SB-B-10', 'type' => 'تجاري', 'status' => 'بانتظار مستندات', 'tone' => 'b-amber']);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-B-11', 'type' => 'تجاري', 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber']);

        $this->actingAs($employee)->get(route('employee.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('tickets', 2)->where('counts.needAction', 1));
    }

    // ── «بانتظار الاعتماد» بلوحة الإدارة كان يعدّ الاجتماعات القادمة أيضاً ──

    public function test_admin_pending_meetings_counts_ended_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Meeting::create(['ref' => 'M-B-1', 'title' => 'منتهٍ', 'when_label' => 'أمس', 'status' => 'منتهٍ', 'approve' => 'بانتظار الاعتماد']);
        Meeting::create(['ref' => 'M-B-2', 'title' => 'قادم', 'when_label' => 'غد', 'status' => 'قادم', 'approve' => 'بانتظار الاعتماد']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('radar', fn ($radar) => collect($radar)->firstWhere('id', 'pending-meetings')['count'] === 1));
    }

    // ── تذاكر المحامي: whereHas('summary') كان يُخفي المسندة إليه قبل إنتاج ملخصها ──

    public function test_lawyer_sees_assigned_ticket_before_its_summary_exists(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-B-20', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'قيد المعالجة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.tickets'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('tickets', 1)->where('tickets.0.awaitingSummary', true));
    }

    // ── بطاقة المحاسبة: القيمة «متأخرة» والتسمية «غير مدفوعة» ──

    public function test_accounting_separates_overdue_from_unpaid(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // غير مدفوعة ولم يحن استحقاقها ⇒ ليست متأخرة
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-B-10', 'description' => 'أتعاب', 'amount' => 100,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال أسبوع', 'paid' => false, 'due_at' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($admin)->get(route('admin.finance'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('dashboard.overdueCount', 0)->where('dashboard.receivablesCount', 1));
    }
}
