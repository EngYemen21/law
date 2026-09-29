<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقارير وإيرادات الإدارة — تجميعات حقيقية بدل الأرقام الثابتة.
 */
class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_show_real_aggregates(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // 3 تذاكر: واحدة مكتملة (نسبة إغلاق) + قسمان
        Ticket::create(['user_id' => $client->id, 'number' => 'T1', 'type' => 'تجاري', 'department' => 'القسم التجاري', 'status' => 'مكتملة', 'tone' => 'b-green']);
        Ticket::create(['user_id' => $client->id, 'number' => 'T2', 'type' => 'تجاري', 'department' => 'القسم التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
        Ticket::create(['user_id' => $client->id, 'number' => 'T3', 'type' => 'عمالي', 'department' => 'القسم العمالي', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);

        $this->actingAs($admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/reports')
                ->where('stats.totalTickets', 3)
                ->where('stats.closureRate', 33) // 1 من 3
                ->has('byDept', 2));
    }

    /**
     * «إجمالي الاستشارات والتذاكر» كان يعرض عدد التذاكر وحده (الشاشة والـPDF). الآن رقمان مستقلّان
     * من اللقطة الواحدة، والاستشارة الملغاة لا تُعدّ.
     */
    public function test_tickets_and_consults_are_counted_separately(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        Ticket::create(['user_id' => $client->id, 'number' => 'T1', 'type' => 'تجاري', 'department' => 'القسم التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
        foreach ([ConsultStatus::AwaitingPricing, ConsultStatus::Ended, ConsultStatus::Cancelled] as $i => $status) {
            Consult::create(['user_id' => $client->id, 'ref' => "CN-{$i}", 'subject' => 'استشارة', 'type' => 'عام', 'channel' => 'مرئية', 'lawyer' => '—', 'status' => $status->value]);
        }

        $this->actingAs($admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('stats.totalTickets', 1)->where('stats.totalConsults', 2));

        $pdf = (string) file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringNotContainsString('إجمالي الاستشارات والتذاكر', $pdf);
        $this->assertStringContainsString("\$s['totalConsults']", $pdf);
    }

    public function test_revenue_shows_real_invoice_and_consult_totals(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        Invoice::create(['user_id' => $client->id, 'number' => 'INV-1', 'description' => 'أتعاب', 'amount' => 11500, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => 'اليوم', 'paid' => true]);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV-2', 'description' => 'أتعاب', 'amount' => 5000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);
        // استشارة مدفوعة (paid_at) تُحتسب في الإيراد؛ مسعّرة بلا سداد لا تُحتسب
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-1', 'subject' => 'استشارة', 'type' => 'عام', 'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني', 'day' => 'الاثنين', 'time' => '11:30 ص', 'when_label' => 'الاثنين · 11:30 ص',
            'status' => 'منتهية', 'price' => 450, 'vat' => 68, 'total' => 518, 'paid_at' => now()]);
        Consult::create(['user_id' => $client->id, 'ref' => 'CN-2', 'subject' => 'استشارة', 'type' => 'عام', 'channel' => 'هاتفية',
            'lawyer' => 'أ. خالد', 'status' => 'بانتظار السداد', 'price' => 350, 'vat' => 53, 'total' => 403, 'priced_at' => now()]);

        // `collected` صار `totalIncome` و`bookingRevenue` صار `consultIncome`: الدخل هو الفاتورة
        // المدفوعة وحدها، فالاستشارة المسدَّدة بلا فاتورة تُعَدّ ولا تُحسب مالاً.
        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/revenue')
                ->where('issued', 16500)
                ->where('totalIncome', 11500)
                ->where('due', 5000)
                ->where('bookings', 1)
                ->where('consultIncome', 0)
                ->where('unbilledPaidConsults', 1));
    }
}
