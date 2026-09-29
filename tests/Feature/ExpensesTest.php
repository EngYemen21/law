<?php

namespace Tests\Feature;

use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\PaymentVoucherDocument;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **المصروفات وسند الصرف** (المرحلة ب، قرار المالك 2026-09-29): الإدارة تسجّل فيُعتمد فوراً،
 * والموظّف بصلاحيّة «تسجيل المصروفات» يسجّل فينتظر الاعتماد؛ الاعتماد يمنح سند صرفٍ من دفترٍ
 * واحد مع صرف مستحقّات الموظّفين؛ والمعتمد وحده يُحسب، ويُلغى بسببٍ ولا يُحذف.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'name' => 'المدير الماليّ']);
    }

    private function clerk(bool $allowed = true): User
    {
        // المصنع يمنح الموظّف كلّ الصلاحيّات افتراضاً — تُقيَّد هنا لتُختبر الصلاحيّة نفسها
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'موظّف الحسابات']);
        $employee->syncPermissions($allowed ? [Permissions::RECORD_EXPENSES] : []);

        return $employee->fresh();
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return array_merge([
            'spent_on' => now()->toDateString(), 'category' => 'rent', 'description' => 'إيجار المكتب لشهر أكتوبر',
            'amount' => '11500.50', 'vat' => '1500.07', 'vendor' => 'شركة العقار', 'paid_from' => 'bank', 'reference' => 'TRX-77',
        ], $over);
    }

    private function notificationsOf(User $user): int
    {
        return DB::table('user_notifications')->where('user_id', $user->id)->count();
    }

    private function pv(int $n): string
    {
        return 'PV-'.now()->format('Y').'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function test_admin_records_an_expense_approved_with_a_voucher_in_halalas(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form([
            'document' => UploadedFile::fake()->create('rent.pdf', 50, 'application/pdf'),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $this->assertSame(ExpenseStatus::Approved, $expense->status);
        $this->assertSame($this->pv(1), $expense->voucher_no);
        $this->assertSame(1150050, $expense->amount_halalas, 'الكسر محفوظٌ بالهللة');
        $this->assertSame(150007, $expense->vat_halalas);
        $this->assertSame($admin->id, $expense->approved_by);
        $this->assertNotNull($expense->document_path);
        $this->assertTrue(AuditLog::where('action', 'تسجيل مصروف')->exists());
    }

    public function test_an_employee_needs_the_permission_and_their_expense_waits_for_approval(): void
    {
        $admin = $this->admin();

        $this->assertPageRefused($this->actingAs($this->clerk(false))->get(route('employee.expenses')));

        $clerk = $this->clerk();
        $this->actingAs($clerk)->get(route('employee.expenses'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/expenses')->has('categories')->has('paidFrom'));
        $this->actingAs($clerk)->post(route('employee.expenses.store'), $this->form())->assertRedirect()->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $this->assertSame(ExpenseStatus::Pending, $expense->status);
        $this->assertNull($expense->voucher_no, 'لا سند قبل الاعتماد');
        $this->assertSame(1, $this->notificationsOf($admin), 'الإدارة تُبلَغ بما ينتظرها');

        // يرى ما سجّله بحالته
        $this->actingAs($clerk)->get(route('employee.expenses'))
            ->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.status', 'بانتظار الاعتماد')->where('rows.data.0.can', []));
    }

    public function test_approve_reject_and_void_follow_the_status_and_keep_the_voucher(): void
    {
        $admin = $this->admin();
        $clerk = $this->clerk();
        $this->actingAs($clerk)->post(route('employee.expenses.store'), $this->form(['description' => 'أوّل']));
        $this->actingAs($clerk)->post(route('employee.expenses.store'), $this->form(['description' => 'ثانٍ']));
        [$first, $second] = Expense::orderBy('id')->get()->all();

        // الاعتماد يمنح السند ويُبلغ المسجِّل؛ ولا اعتماد مرّتين
        $this->actingAs($admin)->post(route('admin.expenses.approve', $first))->assertRedirect();
        $first->refresh();
        $this->assertSame(ExpenseStatus::Approved, $first->status);
        $this->assertSame($this->pv(1), $first->voucher_no);
        $this->assertSame(1, $this->notificationsOf($clerk));
        $this->actingAs($admin)->post(route('admin.expenses.approve', $first))->assertStatus(422);

        // الرفض بسببٍ إلزاميّ، ولا رفضَ لمعتمد
        $this->actingAs($admin)->post(route('admin.expenses.reject', $second), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.expenses.reject', $second), ['reason' => 'لا مستند مرفق'])->assertRedirect();
        $this->assertSame(ExpenseStatus::Rejected, $second->fresh()->status);
        $this->assertNull($second->fresh()->voucher_no);
        $this->actingAs($admin)->post(route('admin.expenses.reject', $first), ['reason' => 'متأخّر جدّاً'])->assertStatus(422);

        // الإلغاء للمعتمد وحده، والسند يبقى برقمه
        $this->actingAs($admin)->post(route('admin.expenses.void', $second), ['reason' => 'خطأ في القيد'])->assertStatus(422);
        $this->actingAs($admin)->post(route('admin.expenses.void', $first), ['reason' => 'قُيّد مرّتين'])->assertRedirect();
        $this->assertSame(ExpenseStatus::Voided, $first->fresh()->status);
        $this->assertSame($this->pv(1), $first->fresh()->voucher_no);
    }

    public function test_only_approved_expenses_count_and_pending_shows_regardless_of_period(): void
    {
        $admin = $this->admin();
        $clerk = $this->clerk();
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['amount' => '100.25', 'vat' => '0']));
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['amount' => '50', 'vat' => '5']));
        $this->actingAs($clerk)->post(route('employee.expenses.store'), $this->form(['amount' => '999', 'vat' => '0', 'spent_on' => now()->subMonths(3)->toDateString()]));
        $voided = Expense::where('amount_halalas', 5000)->sole();
        $this->actingAs($admin)->post(route('admin.expenses.void', $voided), ['reason' => 'قُيّد خطأً']);

        $month = ['from' => now()->startOfMonth(), 'to' => now()->endOfMonth()];
        $summary = FinanceBoard::expensesSummary($month);
        $this->assertSame(100.25, $summary['approved'], 'المعتمد وحده — لا المنتظر ولا الملغى');
        $this->assertSame(1, $summary['pending']);

        // «بانتظار الاعتماد» تعرض ما ينتظر ولو خارج الفترة
        $this->assertSame(1, FinanceBoard::expenses($month, 'pending', 'all')->total());
        $this->assertSame(2, FinanceBoard::expenses($month, 'all', 'all')->total());
    }

    public function test_expenses_and_staff_payouts_share_one_voucher_book(): void
    {
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->postJson(route('admin.staff.payouts.store', $lawyer), [
            'kind' => 'salary', 'amount' => 8000, 'period' => now()->format('Y-m'),
        ])->assertOk();
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form());

        $this->assertSame($this->pv(1), StaffPayout::sole()->voucher_no);
        $this->assertSame($this->pv(2), Expense::sole()->voucher_no, 'دفترٌ واحد متسلسل');
    }

    public function test_invalid_input_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['vat' => '20000']))->assertSessionHasErrors('vat');
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['spent_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('spent_on');
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['category' => 'salaries']))->assertSessionHasErrors('category');
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['amount' => '10.555']))->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    public function test_vouchers_and_documents_reach_the_right_people_only(): void
    {
        $admin = $this->admin();
        $clerk = $this->clerk();
        $otherClerk = $this->clerk();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'محامٍ']);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($clerk)->post(route('employee.expenses.store'), $this->form([
            'document' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf'),
        ]));
        $expense = Expense::sole();
        $this->actingAs($admin)->post(route('admin.expenses.approve', $expense));
        $this->actingAs($admin)->postJson(route('admin.staff.payouts.store', $lawyer), ['kind' => 'salary', 'amount' => 5000, 'period' => now()->format('Y-m')]);
        $payout = StaffPayout::sole();

        $pdf = $this->actingAs($admin)->get(route('admin.expenses.voucher', $expense));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->actingAs($admin)->get(route('admin.expenses.document', $expense))->assertOk();
        $this->actingAs($admin)->get(route('admin.staff.payouts.voucher', [$lawyer, $payout]))->assertOk();

        $this->actingAs($clerk)->get(route('employee.expenses.document', $expense))->assertOk();
        $this->assertPageRefused($this->actingAs($otherClerk)->get(route('employee.expenses.document', $expense)));

        $this->actingAs($lawyer)->get(route('lawyer.earnings.voucher', $payout))->assertOk();
        $this->actingAs($otherLawyer)->get(route('lawyer.earnings.voucher', $payout))->assertNotFound();
    }

    public function test_the_voucher_prints_the_payee_amount_in_words_and_status(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->form(['amount' => '1150', 'vat' => '150']));
        $expense = Expense::sole();

        $html = PaymentVoucherDocument::forExpense($expense);
        foreach (['سند صرف', $this->pv(1), 'شركة العقار', '1,150.00 ر.س', 'فقط ألف ومائة وخمسون ريال سعودي لا غير', '150.00 ر.س', 'إيجار', 'الحساب البنكيّ', 'TRX-77', 'المدير الماليّ'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->actingAs($admin)->post(route('admin.expenses.void', $expense), ['reason' => 'قُيّد مرّتين']);
        $this->assertStringContainsString('ملغى — قُيّد مرّتين', PaymentVoucherDocument::forExpense($expense->fresh()));
    }

    public function test_the_employee_menu_item_follows_the_permission(): void
    {
        $this->assertSame(Permissions::RECORD_EXPENSES, Permissions::viewMap()['/employee/expenses'] ?? null);
        $this->assertContains(Permissions::RECORD_EXPENSES, Permissions::ROLE_PERMISSIONS['employee']);
        $this->assertNotContains(Permissions::RECORD_EXPENSES, Permissions::PRESETS['خدمة عملاء'], 'سقفٌ لا منح');
    }
}
