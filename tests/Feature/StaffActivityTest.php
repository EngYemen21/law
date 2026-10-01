<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **نافذة «ملفّ النشاط»** — حلّت محلّ «الملف الوظيفي» المكرّر (قرار المالك 2026-10-01).
 *
 * الحِمل والمستحقّات من مصدريهما الواحدين، وآخر العمليّات وسجلّ الحساب من سجلّ التدقيق
 * لهذا الموظّف وحده.
 */
class StaffActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_a_lawyer_profile_carries_workload_earnings_and_both_histories(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-ACT-1', 'type' => 'نزاع', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id]);

        Audit::log(action: 'اعتماد ملخّص', description: 'اعتمد المحامي الملخّص.', user: $lawyer);
        Audit::log(action: 'إيقاف موظف', description: 'أُوقف الحساب.', auditable: $lawyer, user: $admin);
        // دخوله يُقيَّد على حسابه بيده — من عمليّاته لا من سجلّ حسابه
        Audit::log(action: 'تسجيل دخول', description: 'دخل.', auditable: $lawyer, user: $lawyer);
        // قيودٌ تخصّ محامياً آخر لا تظهر هنا
        Audit::log(action: 'عملية محامٍ آخر', description: '—', user: $other);
        Audit::log(action: 'تعديل حساب آخر', description: '—', auditable: $other, user: $admin);

        $json = $this->actingAs($admin)->getJson(route('admin.staff.activity', $lawyer))->assertOk()->json();

        $this->assertSame(1, $json['workload']['tickets']);
        $this->assertArrayHasKey('capacity', $json['workload']);
        $this->assertArrayHasKey('balance', $json['earnings']);
        $this->assertSame(['تسجيل دخول', 'اعتماد ملخّص'], array_column($json['actions'], 'action'));
        $this->assertSame(['إيقاف موظف'], array_column($json['account'], 'action'));
        $this->assertSame($admin->name, $json['account'][0]['by']);
    }

    public function test_an_admin_profile_has_history_but_no_workload_or_earnings(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $json = $this->actingAs($admin)->getJson(route('admin.staff.activity', $admin))->assertOk()->json();

        $this->assertNull($json['workload']);
        $this->assertNull($json['earnings']);
    }

    public function test_only_staff_managers_reach_it_and_never_for_a_client(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        foreach ([Role::Lawyer, Role::Employee, Role::Client] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson(route('admin.staff.activity', $lawyer))->assertForbidden();
        }

        $client = User::factory()->create(['role' => Role::Client]);
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->getJson(route('admin.staff.activity', $client))->assertNotFound();
    }

    /** النافذة القديمة المكرّرة لم تعد في الصفحة — ولا مصفوفة صلاحيّاتها. */
    public function test_the_old_dossier_is_gone_from_the_page(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/admin/staff.tsx'));
        $this->assertStringNotContainsString('الملف الوظيفي', $page);
        $this->assertStringNotContainsString('modalPermSearch', $page);
        $this->assertStringContainsString('<StaffActivityModal', $page);
    }

    public function test_each_list_is_capped_newest_first(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        foreach (range(1, 12) as $i) {
            Audit::log(action: "عملية {$i}", description: '—', user: $employee);
        }

        $actions = $this->actingAs($admin)->getJson(route('admin.staff.activity', $employee))->json('actions');

        $this->assertCount(10, $actions);
        $this->assertSame('عملية 12', $actions[0]['action']);
    }
}
