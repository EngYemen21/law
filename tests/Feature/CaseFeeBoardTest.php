<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **تبويب «أتعاب القضايا»** (تطوير 2026-09-29) — تبويباتٌ بحالة الأتعاب وما ينتظر القرار أوّلاً، وأرقام الرأس
 * ومال كلّ قضيّة من تعريفات الفاتورة الواحدة، وفلتر المالية برقم القضيّة، وحكم «تُحدَّد أتعابها» الواحد.
 */
class CaseFeeBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'نورة القحطاني']);
    }

    private function legalCase(string $no, string $status, string $feeStatus, ?int $fee = null): LegalCase
    {
        return LegalCase::create(['user_id' => $this->client->id, 'number' => $no, 'type' => 'تجاري', 'status' => $status, 'fee_status' => $feeStatus, 'fee' => $fee]);
    }

    private function invoice(LegalCase $case, string $no, int $amount, InvoiceStatus $status, ?string $due = null): Invoice
    {
        return Invoice::create([
            'user_id' => $this->client->id, 'case_id' => $case->id, 'number' => $no, 'description' => 'أتعاب', 'amount' => $amount,
            'status' => $status->value, 'tone' => $status->tone(), 'due_label' => '—', 'due_at' => $due,
            'paid' => $status === InvoiceStatus::Paid, 'issued_at' => now()->subYear(),
        ]);
    }

    /** @return array{0: LegalCase, 1: LegalCase, 2: LegalCase} */
    private function board(): array
    {
        $plan = $this->legalCase('C-INS', CaseStatus::InPreparation->value, 'installments', 9000);
        $this->invoice($plan, 'INV-1', 3000, InvoiceStatus::Paid, today()->subMonth()->toDateString());
        $this->invoice($plan, 'INV-2', 3000, InvoiceStatus::Due, today()->subDays(3)->toDateString()); // متأخّرة
        $this->invoice($plan, 'INV-3', 3000, InvoiceStatus::Due, today()->addMonth()->toDateString());
        $this->invoice($plan, 'INV-X', 5000, InvoiceStatus::Cancelled);

        $pending = $this->legalCase('C-PEN', CaseStatus::AwaitingFeePayment->value, 'pending_payment', 20000);
        $this->invoice($pending, 'INV-4', 23000, InvoiceStatus::Due, today()->addDays(10)->toDateString());

        // الأقدم إنشاءً — ومع ذلك يتصدّر لأنّه ينتظر قرار الإدارة
        $awaiting = $this->legalCase('C-AWT', CaseStatus::AwaitingFeeApproval->value, 'none');
        LegalCase::whereKey($awaiting->id)->update(['id' => 1000]);

        return [$plan, $pending, $awaiting];
    }

    public function test_awaiting_decision_comes_first_with_counts_and_money_per_case(): void
    {
        $this->board();

        $this->actingAs($this->admin)->get(route('admin.casefees'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/casefees')
                ->where('tab', 'all')
                ->where('cases.data.0.no', 'C-AWT')
                ->where('cases.data.0.canSetFee', true)
                ->where('counts.awaiting', 1)
                ->where('counts.installments', 1)
                ->where('counts.pending_payment', 1)
                ->where('counts.all', 3)
                ->where('cases.data', fn ($rows) => ($plan = collect($rows)->firstWhere('no', 'C-INS'))['money']['invoiced'] === 9000
                    && $plan['money']['paid'] === 3000
                    && $plan['money']['remaining'] === 6000
                    && $plan['money']['overdue'] === true
                    && count($plan['money']['invoices']) === 4));
    }

    public function test_totals_use_the_invoice_definitions(): void
    {
        $this->board();

        // المفوتَر بلا الملغاة 9000+23000، والمحصَّل 3000، والقائم 6000+23000، والمتأخّر قسطٌ واحد
        $this->actingAs($this->admin)->get(route('admin.casefees'))
            ->assertInertia(fn ($p) => $p
                ->where('totals.invoiced', 32000)
                ->where('totals.collected', 3000)
                ->where('totals.outstanding', 29000)
                ->where('totals.overdue', 3000)
                ->where('totals.overdueCount', 1));
    }

    public function test_tab_and_search_filter_the_list(): void
    {
        $this->board();

        $this->actingAs($this->admin)->get(route('admin.casefees', ['tab' => 'pending_payment']))
            ->assertInertia(fn ($p) => $p->has('cases.data', 1)->where('cases.data.0.no', 'C-PEN'));

        $this->actingAs($this->admin)->get(route('admin.casefees', ['tab' => 'awaiting']))
            ->assertInertia(fn ($p) => $p->has('cases.data', 1)->where('cases.data.0.no', 'C-AWT'));

        $this->actingAs($this->admin)->get(route('admin.casefees', ['q' => 'C-INS']))
            ->assertInertia(fn ($p) => $p->has('cases.data', 1));

        $this->actingAs($this->admin)->get(route('admin.casefees', ['q' => 'القحطاني']))
            ->assertInertia(fn ($p) => $p->has('cases.data', 3));

        $this->actingAs($this->admin)->get(route('admin.casefees', ['tab' => 'unknown']))
            ->assertInertia(fn ($p) => $p->where('tab', 'all'));
    }

    public function test_finance_invoices_filter_by_case_across_all_periods(): void
    {
        $this->board();

        // صدرت قبل سنة — خارج فترة «هذا الشهر» الافتراضيّة، وتظهر مع فلتر القضيّة
        $this->actingAs($this->admin)->get(route('admin.finance', ['tab' => 'invoices', 'case' => 'C-INS']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('caseFilter', 'C-INS')
                ->where('invoices.data', fn ($rows) => collect($rows)->pluck('no')->sort()->values()->all() === ['INV-1', 'INV-2', 'INV-3', 'INV-X']));

        $this->actingAs($this->admin)->get(route('admin.finance', ['tab' => 'invoices']))
            ->assertInertia(fn ($p) => $p->where('caseFilter', null)->has('invoices.data', 0));
    }

    public function test_setting_a_fee_outside_its_state_is_refused_by_the_transition_before_any_invoice(): void
    {
        $case = $this->legalCase('C-PRE', CaseStatus::InPreparation->value, 'none');

        $this->actingAs($this->admin)->post(route('admin.cases.fee', $case), ['fee' => 5000])->assertStatus(422);

        $this->assertSame(0, Invoice::where('case_id', $case->id)->count());
        $this->assertSame('none', $case->fresh()->fee_status);
    }

    public function test_case_page_fee_action_follows_the_same_rule(): void
    {
        [$plan] = $this->board();

        $this->actingAs($this->admin)->get(route('admin.cases.show', LegalCase::find(1000)))
            ->assertInertia(fn ($p) => $p->where('case.feePending', true));
        $this->actingAs($this->admin)->get(route('admin.cases.show', $plan))
            ->assertInertia(fn ($p) => $p->where('case.feePending', false));
    }

    public function test_fee_controller_no_longer_compares_arabic_status_text(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Admin/CaseController.php'));

        $this->assertStringNotContainsString("=== 'بانتظار اعتماد الأتعاب'", $source);
    }
}
