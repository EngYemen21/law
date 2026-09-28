<?php

namespace Tests\Feature;

use App\Enums\PayoutKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\StaffPayout;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **سجلّ الصرف للموظّفين** (`Admin\StaffPayoutController`): الإدارة بصلاحيّة «إدارة الموظفين» تسجّل
 * وتُلغي بسبب، ولا تعديل ولا حذف؛ ونصيب الملفّ يُسجَّل على ملفٍّ مسنَدٍ إلى الموظّف نفسه.
 */
class StaffPayoutLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'pay_type' => 'both', 'salary' => 8000, 'pay_pct' => 20]);
    }

    private function case(User $lawyer): LegalCase
    {
        return LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-PL-'.uniqid(), 'type' => 'تجاري',
            'status' => 'منظورة', 'tone' => 'b-cyan', 'assigned_lawyer_id' => $lawyer->id, 'fee' => 10000, 'lawyer_pct' => 20, 'lawyer_fee' => 2000,
        ]);
    }

    public function test_admin_records_a_payout_and_the_balance_follows(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.store', $this->lawyer), [
            'kind' => 'salary', 'amount' => 8000, 'period' => now()->format('Y-m'), 'note' => 'تحويل بنكي',
        ])->assertOk()->assertJsonPath('message', 'سُجّل الصرف.')
            ->assertJsonPath('earnings.totals.byKind.salary.paid', 8000);

        $payout = StaffPayout::firstOrFail();
        $this->assertSame([PayoutKind::Salary, 8000, $this->admin->id], [$payout->kind, $payout->amount, $payout->recorded_by]);
        $this->assertTrue(AuditLog::where('action', 'تسجيل صرف لموظف')->exists());
    }

    public function test_a_share_payout_needs_a_file_assigned_to_that_lawyer(): void
    {
        $own = $this->case($this->lawyer);
        $other = $this->case(User::factory()->create(['role' => Role::Lawyer]));
        $post = fn (array $extra) => $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.store', $this->lawyer), $extra + [
            'kind' => 'case_share', 'amount' => 500, 'period' => now()->format('Y-m'),
        ]);

        $post([])->assertStatus(422);
        $post(['file_id' => $other->id])->assertStatus(422);
        $post(['file_id' => $own->id])->assertOk()->assertJsonPath('earnings.shares.0.paid', 500);
        $this->assertSame($own->id, StaffPayout::firstOrFail()->case_id);
    }

    public function test_paying_beyond_the_computed_due_warns_but_is_recorded(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.store', $this->lawyer), [
            'kind' => 'session', 'amount' => 900, 'period' => now()->format('Y-m'),
        ])->assertOk()->assertJsonPath('warning', fn ($w) => str_contains((string) $w, 'تجاوز'));
        $this->assertSame(1, StaffPayout::count());
    }

    public function test_a_payout_is_voided_with_a_reason_never_edited_or_deleted(): void
    {
        $payout = StaffPayout::create(['user_id' => $this->lawyer->id, 'kind' => PayoutKind::Salary, 'amount' => 8000, 'period' => now()->format('Y-m'), 'paid_at' => today()]);
        $void = fn (array $body) => $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.void', [$this->lawyer, $payout]), $body);

        $void([])->assertStatus(422);
        $void(['reason' => 'قيد مكرّر بالخطأ'])->assertOk()->assertJsonPath('earnings.totals.byKind.salary.paid', 0);
        $void(['reason' => 'مرّة أخرى'])->assertStatus(422);

        $payout->refresh();
        $this->assertSame([true, 'قيد مكرّر بالخطأ', $this->admin->id], [$payout->isVoided(), $payout->void_reason, $payout->voided_by]);
        $this->assertTrue(AuditLog::where('action', 'إلغاء قيد صرف')->exists());

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'payouts'));
        $this->assertEqualsCanonicalizing([['POST'], ['POST']], $routes->map(fn ($r) => array_values(array_diff($r->methods(), ['HEAD'])))->values()->all(), 'لا تعديل ولا حذف لقيد الصرف');
    }

    public function test_a_payout_of_another_staff_member_cannot_be_voided_through_this_one(): void
    {
        $payout = StaffPayout::create(['user_id' => $this->lawyer->id, 'kind' => PayoutKind::Salary, 'amount' => 100, 'period' => now()->format('Y-m'), 'paid_at' => today()]);
        $other = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.void', [$other, $payout]), ['reason' => 'محاولة خاطئة'])->assertNotFound();
        $this->assertFalse($payout->fresh()->isVoided());
    }

    public function test_only_staff_with_the_permission_reach_the_ledger_and_only_for_staff(): void
    {
        $body = ['kind' => 'salary', 'amount' => 100, 'period' => now()->format('Y-m')];

        $this->actingAs($this->lawyer)->postJson(route('admin.staff.payouts.store', $this->lawyer), $body)->assertForbidden();
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->getJson(route('admin.staff.earnings', $this->lawyer))->assertForbidden();

        $client = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($this->admin)->getJson(route('admin.staff.earnings', $client))->assertNotFound();
        $this->actingAs($this->admin)->getJson(route('admin.staff.earnings', $this->lawyer))->assertOk()
            ->assertJsonPath('earnings.payType', 'both')
            ->assertJsonCount(count(PayoutKind::cases()), 'kinds');
        $this->assertSame(0, StaffPayout::count());
    }

    public function test_a_former_lawyer_can_be_paid_the_share_collected_in_his_time(): void
    {
        $case = $this->case($this->lawyer);
        Invoice::create(['user_id' => $case->user_id, 'case_id' => $case->id, 'number' => 'INV-PL-'.uniqid(), 'description' => 'أتعاب',
            'amount' => 5750, 'subtotal' => 5000, 'vat_rate' => 15, 'vat_amount' => 750, 'status' => 'مدفوعة', 'tone' => 'b-green',
            'due_label' => '—', 'paid' => true, 'paid_at' => now(), 'share_user_id' => $this->lawyer->id]);
        $this->actingAs($this->admin)->post(route('admin.cases.lawyer', $case), ['lawyer_id' => User::factory()->create(['role' => Role::Lawyer])->id])->assertRedirect();

        $this->actingAs($this->admin)->postJson(route('admin.staff.payouts.store', $this->lawyer), [
            'kind' => 'case_share', 'amount' => 1000, 'period' => now()->format('Y-m'), 'file_id' => $case->id,
        ])->assertOk()->assertJsonPath('earnings.shares.0.balance', 0);
    }
}
