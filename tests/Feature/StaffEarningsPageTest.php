<?php

namespace Tests\Feature;

use App\Enums\PayoutKind;
use App\Enums\Role;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\Finance\RevenueSnapshot;
use App\Support\Finance\StaffEarnings;
use App\Support\Finance\StaffStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **«مستحقاتي» للمحامي والموظّف** (`Staff\EarningsController`): صفحةٌ واحدة لمساري الدورين، ببيانات
 * المستخدم الحاليّ وحده؛ وكشف الشهر والتقرير الماليّ من الحساب نفسه (`Finance\StaffEarnings`).
 */
class StaffEarningsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_staff_role_sees_only_their_own_dues(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'pay_type' => 'salary', 'salary' => 9000]);
        $employee = User::factory()->create(['role' => Role::Employee, 'pay_type' => 'salary', 'salary' => 6000]);

        $this->actingAs($lawyer)->get('/lawyer/earnings?user_id='.$employee->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('earnings')->where('base', '/lawyer')->where('earnings.salary.monthly', 9000));
        $this->actingAs($employee)->get('/employee/earnings')->assertOk()
            ->assertInertia(fn ($p) => $p->where('base', '/employee')->where('earnings.salary.monthly', 6000));
        $this->actingAs($employee)->get('/employee/earnings?month=2026-09')
            ->assertInertia(fn ($p) => $p->where('earnings.month', '2026-09'));

        // لوحة دورٍ آخر مرفوضة (حارس بادئة الدور القائم)
        $this->actingAs($employee)->get('/lawyer/earnings')->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => Role::Client]))->get('/employee/earnings')->assertRedirect();
    }

    public function test_the_monthly_statement_is_a_pdf_built_from_the_same_numbers(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'pay_type' => 'salary', 'salary' => 9000, 'name' => 'أ. خالد']);
        StaffPayout::create(['user_id' => $lawyer->id, 'kind' => PayoutKind::Salary, 'amount' => 4000, 'period' => now()->format('Y-m'), 'paid_at' => today(), 'note' => 'دفعة أولى']);

        $this->actingAs($lawyer)->get('/lawyer/earnings/statement.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        $e = StaffEarnings::for($lawyer);
        $html = StaffStatement::html($lawyer, $e);
        $this->assertStringContainsString('كشف مستحقّات الموظّف', $html);
        $this->assertStringContainsString($e['monthLabel'], $html);
        $this->assertStringContainsString(number_format($e['totals']['balance']).' ر.س', $html);
        $this->assertStringContainsString('4,000 ر.س', $html);
    }

    public function test_the_revenue_report_counts_active_payouts_only(): void
    {
        $u = User::factory()->create(['role' => Role::Employee]);
        StaffPayout::create(['user_id' => $u->id, 'kind' => PayoutKind::Salary, 'amount' => 5000, 'period' => now()->format('Y-m'), 'paid_at' => today()]);
        StaffPayout::create(['user_id' => $u->id, 'kind' => PayoutKind::Salary, 'amount' => 700, 'period' => now()->format('Y-m'), 'paid_at' => today(), 'voided_at' => now(), 'void_reason' => 'خطأ']);

        $this->assertSame(5000, RevenueSnapshot::build()->staffPaidTotal);
    }

    public function test_the_sidebar_links_both_staff_roles_to_their_dues(): void
    {
        $nav = (string) file_get_contents(resource_path('js/lib/data.ts'));

        foreach (['/employee/earnings', '/lawyer/earnings'] as $route) {
            $this->assertStringContainsString("{ icon: 'card', label: 'مستحقاتي', route: '{$route}' }", $nav);
            $this->assertStringContainsString("'{$route}': ['مستحقاتي',", $nav);
        }
    }
}
