<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PayoutKind;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\ProfitAndLoss;
use App\Support\Finance\ProfitAndLossDocument;
use App\Support\Finance\RevenueSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * **التقارير الماليّة** (المرحلة د): الربح = صافي المحصَّل − صافي المصروفات المعتمدة − صرف الموظّفين
 * غير الملغى؛ بلا ضريبة، مقارنةً بالفترة السابقة، والأشهر تجمع إلى الفترة، والفاتورة المدفوعة بلا
 * تاريخ سدادٍ لا تُخمَّن فتُعلَن بعددها.
 */
class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function paidInvoice(int $amount, int $vat, ?string $paidAt, array $link = []): Invoice
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Invoice::create($link + [
            'user_id' => $client->id, 'number' => 'INV-PL-'.uniqid(), 'description' => 'أتعاب', 'amount' => $amount,
            'vat_amount' => $vat, 'subtotal' => $amount - $vat, 'status' => 'مدفوعة', 'tone' => 'b-green',
            'due_label' => '—', 'paid' => true, 'paid_at' => $paidAt, 'issued_at' => $paidAt,
        ]);
    }

    private function expense(string $on, int $halalas, int $vat, ExpenseStatus $status, ExpenseCategory $category = ExpenseCategory::Rent): Expense
    {
        return Expense::create([
            'spent_on' => $on, 'category' => $category, 'description' => 'مصروف', 'amount_halalas' => $halalas,
            'vat_halalas' => $vat, 'paid_from' => 'bank', 'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    private function payout(string $on, int $riyals, bool $voided = false): StaffPayout
    {
        $staff = User::factory()->create(['role' => Role::Employee]);

        return StaffPayout::create([
            'user_id' => $staff->id, 'kind' => PayoutKind::Salary, 'amount' => $riyals, 'period' => substr($on, 0, 7),
            'paid_at' => $on, 'recorded_by' => $this->admin->id, 'voided_at' => $voided ? $on : null,
        ]);
    }

    private function seedSeptemberAndAugust(): void
    {
        // سبتمبر: إيراد 1150 (منها 150 ضريبة) ⇒ صافٍ 1000؛ مصروف معتمد 230 (منها 30) ⇒ 200؛ صرف 300
        $this->paidInvoice(1150, 150, '2026-09-10 10:00:00', ['case_id' => null]);
        $this->expense('2026-09-05', 23000, 3000, ExpenseStatus::Approved);
        $this->payout('2026-09-20', 300);
        // لا يُحسب: مصروف بانتظار الاعتماد، ومرفوض، وملغى؛ وصرفٌ ملغى
        $this->expense('2026-09-06', 99900, 0, ExpenseStatus::Pending);
        $this->expense('2026-09-06', 88800, 0, ExpenseStatus::Rejected);
        $this->expense('2026-09-06', 77700, 0, ExpenseStatus::Voided);
        $this->payout('2026-09-21', 5000, voided: true);
        // أغسطس (الفترة السابقة): إيراد صافٍ 500
        $this->paidInvoice(575, 75, '2026-08-20 10:00:00');
    }

    public function test_profit_is_net_revenue_minus_approved_expenses_minus_active_payouts(): void
    {
        $this->seedSeptemberAndAugust();

        $r = ProfitAndLoss::report(FinanceBoard::period('month'));

        $this->assertSame([
            'revenue' => 100000, 'revenueVat' => 15000, 'expenses' => 20000, 'expensesVat' => 3000, 'payouts' => 30000, 'profit' => 50000,
        ], $r['summary']['current']);
        $this->assertSame(50000, $r['summary']['previous']['revenue']);
        $this->assertSame(1, $r['pendingExpenses']);
        $this->assertSame([['key' => 'rent', 'label' => ExpenseCategory::Rent->label(), 'current' => 20000, 'previous' => 0]], $r['expensesByCategory']);
    }

    public function test_net_revenue_equals_the_finance_dashboard_subtotal(): void
    {
        $this->seedSeptemberAndAugust();
        $period = FinanceBoard::period('month');

        $collected = RevenueSnapshot::collectedBetween($period['from'], $period['to']);
        $r = ProfitAndLoss::report($period);

        $this->assertSame($collected['subtotal'] * 100, $r['summary']['current']['revenue'], 'مصدرٌ واحد للإيراد');
    }

    public function test_revenue_is_split_by_linked_file_kind(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PL-1', 'subject' => 'استشارة', 'channel' => 'مرئية',
            'lawyer' => '—', 'status' => 'مكتملة', 'session' => 'منتهية',
        ]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-PL-1', 'title' => 'دعوى', 'type' => 'تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);
        $this->paidInvoice(1000, 0, '2026-09-02 10:00:00', ['consult_id' => $consult->id]);
        $this->paidInvoice(2000, 0, '2026-09-03 10:00:00', ['case_id' => $case->id]);
        $this->paidInvoice(400, 0, '2026-09-04 10:00:00');

        $r = ProfitAndLoss::report(FinanceBoard::period('month'));

        $this->assertSame(
            ['consult' => 100000, 'case' => 200000, 'other' => 40000],
            array_column($r['revenueByKind'], 'current', 'key'),
        );
    }

    public function test_months_sum_to_the_period_and_are_clipped_to_it(): void
    {
        $this->seedSeptemberAndAugust();

        $r = ProfitAndLoss::report(FinanceBoard::period('custom', '2026-08-15', '2026-09-30'));

        $this->assertSame(['2026-08', '2026-09'], array_column($r['months'], 'month'));
        $this->assertSame($r['summary']['current']['profit'], array_sum(array_column($r['months'], 'profit')));
        $this->assertSame([50000, 50000], array_column($r['months'], 'profit'));
    }

    public function test_previous_period_matches_the_period_kind(): void
    {
        $prev = fn (string $key, ?string $from = null, ?string $to = null) => array_values(array_intersect_key(
            FinanceBoard::previousPeriod(FinanceBoard::period($key, $from, $to)),
            ['fromDate' => 0, 'toDate' => 0],
        ));

        $this->assertSame(['2026-08-01', '2026-08-31'], $prev('month'));
        $this->assertSame(['2026-04-01', '2026-06-30'], $prev('quarter'));
        $this->assertSame(['2025-01-01', '2025-12-31'], $prev('year'));
        $this->assertSame(['2026-08-22', '2026-08-31'], $prev('custom', '2026-09-01', '2026-09-10'), 'مدىً بطوله قبله مباشرة');
    }

    public function test_paid_invoice_without_payment_date_is_excluded_and_counted(): void
    {
        $this->paidInvoice(900, 0, null);

        $r = ProfitAndLoss::report(FinanceBoard::period('year'));

        $this->assertSame(0, $r['summary']['current']['revenue']);
        $this->assertSame(1, $r['undatedPaid']);
    }

    public function test_page_pdf_and_csv_are_admin_only_and_share_the_numbers(): void
    {
        $this->seedSeptemberAndAugust();

        $this->actingAs($this->admin)->get('/admin/financial-reports')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/financial-reports')
                ->where('report.period.key', 'month')
                ->where('report.summary.current.profit', 50000)
                ->has('periods', count(FinanceBoard::PERIODS)));

        $pdf = $this->actingAs($this->admin)->get('/admin/financial-reports/pdf?period=month');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $csv = $this->actingAs($this->admin)->get('/admin/financial-reports/csv?period=month');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $body, 'BOM ليفتح Excel العربيّة');
        $this->assertStringContainsString('"صافي الربح",500.00,500.00,0%', $body);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->assertPageRefused($this->actingAs($employee)->get('/admin/financial-reports'));
        $this->assertPageRefused($this->actingAs($employee)->get('/admin/financial-reports/csv'));
    }

    public function test_pdf_document_carries_tables_and_warnings(): void
    {
        $this->seedSeptemberAndAugust();
        $this->paidInvoice(100, 0, null);

        $html = ProfitAndLossDocument::html(ProfitAndLoss::report(FinanceBoard::period('month')));

        $this->assertStringContainsString('class="cf-tbl"', $html);
        $this->assertStringContainsString('1,000.00 ر.س', $html);
        $this->assertStringContainsString('1 فاتورة مدفوعة بلا تاريخ سداد', $html);
        $this->assertStringContainsString('1 مصروف بانتظار الاعتماد', $html);
    }
}
