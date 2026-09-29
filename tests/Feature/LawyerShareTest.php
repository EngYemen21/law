<?php

namespace Tests\Feature;

use App\Enums\PayType;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\ExecService;
use App\Support\Finance\LawyerShare;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **نصيب المحامي — قاعدةٌ واحدة للقضيّة والتنفيذ** (`Finance\LawyerShare`، قرار المالك 2026-09-28).
 *
 * نسبة ملفّ المحامي (`users.pay_pct`) افتراضُ كلّ ملفٍّ يُسند إليه، والإدارة تعدّلها لكلّ ملفّ عند
 * اعتماد أتعابه؛ والنسبة من الأتعاب للمحامي وحده في نموذج تسجيل الموظّفين.
 */
class LawyerShareTest extends TestCase
{
    use RefreshDatabase;

    private function lawyer(PayType $type = PayType::Percent, ?float $pct = 30): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'pay_type' => $type->value, 'pay_pct' => $pct]);
    }

    public function test_the_default_share_is_the_lawyers_own_rate_else_the_shared_default(): void
    {
        $this->assertSame(30, LawyerShare::defaultPctFor($this->lawyer()));
        $this->assertSame(25, LawyerShare::defaultPctFor($this->lawyer(PayType::SalaryAndPercent, 25)));
        // بلا نسبةٍ في ملفّه، أو نوع أجرٍ بلا نسبة، أو بلا محامٍ ⇒ الافتراض الموحّد
        $this->assertSame(LawyerShare::DEFAULT_PCT, LawyerShare::defaultPctFor($this->lawyer(PayType::Salary, 40)));
        $this->assertSame(LawyerShare::DEFAULT_PCT, LawyerShare::defaultPctFor($this->lawyer(PayType::Percent, null)));
        $this->assertSame(LawyerShare::DEFAULT_PCT, LawyerShare::defaultPctFor(null));
        $this->assertSame(LawyerShare::DEFAULT_PCT, LawyerShare::defaultPctFor(User::factory()->create(['role' => Role::Employee, 'pay_type' => 'pct', 'pay_pct' => 50])));
    }

    public function test_earned_follows_collection_and_never_exceeds_the_share(): void
    {
        $this->assertSame(2500, LawyerShare::of(12500, 20));
        $this->assertSame(1250, LawyerShare::earned(6250, 20, 2500), 'نصف المحصَّل ⇒ نصف النصيب');
        $this->assertSame(2500, LawyerShare::earned(99999, 20, 2500), 'لا يتجاوز النصيب الكلّيّ');
        $this->assertSame(1000, LawyerShare::earned(10000, 10), 'بلا سقفٍ في النموذج النسبيّ');
    }

    public function test_case_fee_defaults_to_the_assigned_lawyers_rate(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = $this->lawyer(PayType::Percent, 30);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-LS-'.uniqid(),
            'type' => 'تجاري', 'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get(route('admin.casefees'))
            ->assertInertia(fn ($p) => $p->where('cases.data.0.lawyerDefaultPct', 30));

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();
        $case->refresh();
        $this->assertSame(30, (int) $case->lawyer_pct);
        $this->assertSame(3000, (int) $case->lawyer_fee);
    }

    public function test_execution_share_is_set_by_the_admin_when_pricing_is_approved(): void
    {
        $lawyer = $this->lawyer(PayType::Percent, 30);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = ExecService::submit(User::factory()->create(['role' => Role::Client]), ['sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 50000]);
        ExecService::refer($exec, $admin);
        ExecService::assignLawyer($exec, $lawyer, $admin);
        ExecService::accept($exec, $lawyer);

        // تسعير المحامي لا يمسّ نصيبه — قرار الإدارة
        ExecService::saveFee($exec, 6000, '30 يوماً', 'fixed', null, $lawyer);
        $this->assertNull($exec->fresh()->lawyer_pct);

        ExecService::approveFee($exec->fresh(), null, $admin, 25);
        $exec->refresh();
        $this->assertSame(25, $exec->lawyer_pct);
        $this->assertSame(1500, $exec->lawyer_fee);
    }

    public function test_execution_share_defaults_to_the_lawyers_rate_and_percent_mode_has_no_fixed_share(): void
    {
        $lawyer = $this->lawyer(PayType::Percent, 30);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = ExecService::submit(User::factory()->create(['role' => Role::Client]), ['sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 50000]);
        ExecService::refer($exec, $admin);
        ExecService::assignLawyer($exec, $lawyer, $admin);
        ExecService::accept($exec, $lawyer);

        ExecService::setFee($exec, 0, '45 يوماً', 'percent', 10.0, $admin);
        $exec->refresh();
        $this->assertSame(30, $exec->lawyer_pct, 'الافتراض نسبة ملفّ المحامي');
        $this->assertNull($exec->lawyer_fee, 'النموذج النسبيّ بلا مبلغٍ مقدَّم — يُحسب من المحصَّل');
    }

    public function test_the_admin_exec_card_carries_the_share_fields(): void
    {
        $lawyer = $this->lawyer(PayType::Percent, 30);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = ExecService::submit(User::factory()->create(['role' => Role::Client]), ['sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 50000]);
        ExecService::assignLawyer($exec, $lawyer, $admin);

        $this->actingAs($admin)->get(route('admin.execs'))
            ->assertInertia(fn ($p) => $p->where('execs.0.lawyerDefaultPct', 30)->where('execs.0.lawyerPct', null));
    }

    public function test_percent_and_session_pay_are_for_lawyers_only(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $payload = fn (string $role, string $nid, string $mobile) => [
            'name' => 'موظّف', 'role' => $role, 'job_title' => 'مسمّى', 'email' => "{$nid}@salasel.test",
            'mobile' => $mobile, 'nid' => $nid, 'payType' => 'pct', 'pct' => 15, 'perms' => [],
        ];

        $this->actingAs($admin)->post(route('admin.staff.store'), $payload('employee', '1077665544', '0551112233'))
            ->assertSessionHasErrors('payType');
        // الجلسة استشارةٌ يعقدها المحامي — لا مصدر لأجرها عند الموظّف
        $this->actingAs($admin)->post(route('admin.staff.store'), ['payType' => 'session', 'session' => 200] + $payload('employee', '1077665546', '0551112235'))
            ->assertSessionHasErrors('payType');
        $this->actingAs($admin)->post(route('admin.staff.store'), $payload('lawyer', '1077665545', '0551112234'))
            ->assertSessionHasNoErrors();
        $this->assertSame(15, (int) User::where('national_id', '1077665545')->value('pay_pct'));

        $this->actingAs($admin)->get(route('admin.staff'))->assertInertia(fn ($p) => $p
            ->where('payTypes', fn ($types) => collect($types)->where('lawyerOnly', true)->pluck('id')->sort()->values()->all() === ['both', 'pct', 'session']));
    }
}
