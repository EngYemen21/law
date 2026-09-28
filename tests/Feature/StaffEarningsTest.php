<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\PayoutKind;
use App\Enums\PayType;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\Finance\StaffEarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **مستحقّات الموظّف — الحساب الواحد** (`Finance\StaffEarnings`، قرار المالك 2026-09-28): الراتب من
 * بداية السجلّ، ونصيب الملفّ بقدر ما سدّده العميل قبل الضريبة، وأجر الجلسات المنتهية، والرصيد
 * مستحقٌّ ناقص المصروف الساري.
 */
class StaffEarningsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Setting::put('payroll_start', '2026-09-01');
        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل التدقيق']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lawyer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => Role::Lawyer, 'pay_type' => PayType::SalaryAndPercent->value, 'salary' => 8000, 'pay_pct' => 20,
            'join_date' => '2026-01-10',
        ], $attrs));
    }

    private function case(User $lawyer, int $fee, int $pct): LegalCase
    {
        return LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-SE-'.uniqid(), 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-cyan',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => $fee, 'lawyer_pct' => $pct, 'lawyer_fee' => (int) round($fee * $pct / 100),
        ]);
    }

    private function invoice(array $link, int $subtotal, ?string $paidAt, InvoiceStatus $status = InvoiceStatus::Paid): Invoice
    {
        return Invoice::create($link + [
            'user_id' => $this->client->id, 'number' => 'INV-SE-'.uniqid(), 'description' => 'أتعاب',
            'amount' => (int) round($subtotal * 1.15), 'subtotal' => $subtotal, 'vat_rate' => 15, 'vat_amount' => (int) round($subtotal * .15),
            'status' => $status->value, 'tone' => 'b-green', 'due_label' => '—',
            'paid' => $paidAt !== null, 'paid_at' => $paidAt, 'issued_at' => '2026-08-01',
        ]);
    }

    private function payout(User $u, PayoutKind $kind, int $amount, string $period, array $extra = []): StaffPayout
    {
        return StaffPayout::create($extra + ['user_id' => $u->id, 'kind' => $kind, 'amount' => $amount, 'period' => $period, 'paid_at' => '2026-10-01']);
    }

    public function test_case_share_is_earned_as_the_client_pays_before_vat_and_capped(): void
    {
        $lawyer = $this->lawyer();
        $case = $this->case($lawyer, 10000, 20);
        $this->invoice(['case_id' => $case->id, 'installment_no' => 1], 5000, '2026-10-05');
        $this->invoice(['case_id' => $case->id, 'installment_no' => 2], 5000, null, InvoiceStatus::Due);
        $this->invoice(['case_id' => $case->id], 9000, null, InvoiceStatus::Cancelled);

        $row = StaffEarnings::for($lawyer, '2026-10')['shares'][0];

        $this->assertSame(['case', 10000, 20, 2000, 5000, 1000, 1000], [$row['kind'], $row['fee'], $row['pct'], $row['share'], $row['collected'], $row['earned'], $row['expected']]);
        $this->assertSame(1000, $row['monthEarned']);
        $this->assertSame(0, StaffEarnings::for($lawyer, '2026-09')['shares'][0]['monthEarned'], 'سُدّد القسط في أكتوبر لا سبتمبر');

        // سدادٌ يفوق الأتعاب (فاتورة تكميليّة) لا يرفع النصيب فوق سقفه
        $this->invoice(['case_id' => $case->id], 20000, '2026-10-06');
        $this->assertSame(2000, StaffEarnings::for($lawyer, '2026-10')['shares'][0]['earned']);
    }

    public function test_what_was_earned_before_the_ledger_start_is_shown_but_not_owed(): void
    {
        $lawyer = $this->lawyer();
        $case = $this->case($lawyer, 10000, 20);
        $this->invoice(['case_id' => $case->id], 10000, '2026-08-20');

        $row = StaffEarnings::for($lawyer, '2026-10')['shares'][0];

        $this->assertSame([2000, 2000, 0, 0], [$row['earned'], $row['beforeLedger'], $row['earnedInLedger'], $row['balance']]);
    }

    public function test_percent_mode_execution_share_has_no_cap_and_follows_collection(): void
    {
        $lawyer = $this->lawyer();
        $exec = Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-SE-'.uniqid(), 'sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 50000,
            'status' => 'قيد التنفيذ', 'stage' => 8, 'tone' => 'b-cyan', 'assigned_lawyer_id' => $lawyer->id,
            'fee_mode' => 'percent', 'collection_fee_pct' => 10, 'lawyer_pct' => 30, 'lawyer_fee' => null,
        ]);
        $this->invoice(['exec_id' => $exec->id], 3000, '2026-09-10');

        $row = StaffEarnings::for($lawyer, '2026-09')['shares'][0];

        $this->assertSame(['exec', null, 900, null, 900], [$row['kind'], $row['share'], $row['earned'], $row['expected'], $row['monthEarned']]);
    }

    public function test_salary_accrues_monthly_from_the_later_of_ledger_start_and_joining(): void
    {
        $lawyer = $this->lawyer();
        $salary = StaffEarnings::for($lawyer, '2026-10')['salary'];
        $this->assertSame(['2026-10', '2026-09'], array_column($salary['months'], 'period'));

        $newcomer = User::factory()->create(['role' => Role::Employee, 'pay_type' => 'salary', 'salary' => 6000, 'join_date' => '2026-10-03']);
        $this->assertSame(['2026-10'], array_column(StaffEarnings::for($newcomer, '2026-10')['salary']['months'], 'period'));

        $sessionOnly = User::factory()->create(['role' => Role::Employee, 'pay_type' => 'session', 'session_fee' => 200]);
        $this->assertFalse(StaffEarnings::for($sessionOnly)['salary']['applies']);
    }

    public function test_balance_is_earned_minus_active_payouts_per_kind(): void
    {
        $lawyer = $this->lawyer();
        $case = $this->case($lawyer, 10000, 20);
        $this->invoice(['case_id' => $case->id], 5000, '2026-10-05');

        $this->payout($lawyer, PayoutKind::Salary, 8000, '2026-09');
        $this->payout($lawyer, PayoutKind::Salary, 8000, '2026-10', ['voided_at' => now(), 'void_reason' => 'قيد مكرّر']);
        $this->payout($lawyer, PayoutKind::CaseShare, 500, '2026-10', ['case_id' => $case->id]);

        $e = StaffEarnings::for($lawyer, '2026-10');

        $this->assertSame(['earned' => 16000, 'paid' => 8000, 'balance' => 8000], array_intersect_key($e['totals']['byKind']['salary'], array_flip(['earned', 'paid', 'balance'])));
        $this->assertSame(500, $e['totals']['byKind']['case_share']['balance']);
        $this->assertSame([500, 500], [$e['shares'][0]['paid'], $e['shares'][0]['balance']]);
        $this->assertSame(17000 - 8500, $e['totals']['balance']);
        $this->assertSame(8000 + 1000, $e['totals']['monthEarned']);
        $this->assertSame(500, $e['totals']['monthPaid'], 'القيد الملغى لا يُحسب مصروفاً');
        $this->assertTrue(collect($e['payouts'])->firstWhere('voided', true)['voidReason'] === 'قيد مكرّر', 'الملغى يبقى في السجلّ بسببه');
        $this->assertSame(['2026-10' => 8000, '2026-09' => 0], array_column($e['salary']['months'], 'remaining', 'period'));
    }

    public function test_session_pay_counts_only_ended_sessions_the_lawyer_held(): void
    {
        $lawyer = $this->lawyer(['pay_type' => PayType::Session->value, 'salary' => 0, 'pay_pct' => null, 'session_fee' => 300]);
        $held = function (string $at, ConsultStatus $status) use ($lawyer) {
            $appt = Appointment::create([
                'user_id' => $this->client->id, 'ext_id' => 'AP-SE-'.uniqid(), 'type' => 'استشارة مرئية', 'ico' => 'video',
                'lawyer' => $lawyer->name, 'lawyer_id' => $lawyer->id, 'day' => substr($at, 0, 10), 'time' => '10:00', 'starts_at' => $at,
                'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'past',
            ]);
            Consult::create([
                'user_id' => $this->client->id, 'ref' => 'CN-SE-'.uniqid(), 'subject' => 'استشارة', 'channel' => 'مرئية',
                'lawyer' => $lawyer->name, 'status' => $status->value, 'appointment_id' => $appt->id,
            ]);
        };
        $held('2026-10-02 10:00:00', ConsultStatus::Ended);
        $held('2026-08-01 10:00:00', ConsultStatus::Ended);        // قبل بداية السجلّ
        $held('2026-10-03 10:00:00', ConsultStatus::Cancelled);    // لم تُعقد

        $e = StaffEarnings::for($lawyer, '2026-10');

        $this->assertCount(2, $e['sessions']['rows']);
        $this->assertSame(300, $e['totals']['byKind']['session']['earned'], 'المنتهية ضمن السجلّ وحدها');
        $this->assertSame(300, $e['totals']['byKind']['session']['monthEarned']);
    }

    public function test_an_unparseable_month_falls_back_to_the_current_one(): void
    {
        $this->assertSame('2026-10', StaffEarnings::month('bogus'));
        $this->assertSame('2026-10', StaffEarnings::for($this->lawyer(), '2026-13')['month']);
    }
}
