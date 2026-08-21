<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قاعدة تفويض قنوات البثّ (ChannelAccess) التي تستخدمها كل القنوات في routes/channels.php.
 * تُختبر مباشرةً (سائق البثّ في الاختبارات = null لا ينفّذ دوال القنوات عبر HTTP).
 *
 * بعد إزالة كيان «الفرع»: الموظف والإدارة يريان كل سجلات المكتب، والمحامي معزول
 * بالإسناد وحده، والعميل ممنوع من القنوات الداخلية ولو كان مالك السجل.
 */
class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /** كائن سجل بسيط يحمل حقلي العزل الباقيين (user_id/assigned_lawyer_id). */
    private function record(int $ownerId, ?int $lawyerId): object
    {
        return (object) ['user_id' => $ownerId, 'assigned_lawyer_id' => $lawyerId];
    }

    public function test_owner_or_staff_scopes_to_owner_and_assigned_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $otherClient = User::factory()->create(['role' => Role::Client]);

        $rec = $this->record($client->id, $lawyerA->id);

        $this->assertTrue(ChannelAccess::ownerOrStaff($client, $rec));       // المالك
        $this->assertFalse(ChannelAccess::ownerOrStaff($otherClient, $rec)); // عميل آخر
        $this->assertTrue(ChannelAccess::ownerOrStaff($lawyerA, $rec));      // المحامي المسند
        $this->assertFalse(ChannelAccess::ownerOrStaff($lawyerB, $rec));     // محامٍ غير مسند
        $this->assertTrue(ChannelAccess::ownerOrStaff($employee, $rec));     // موظف المكتب
        $this->assertTrue(ChannelAccess::ownerOrStaff($admin, $rec));        // الإدارة
    }

    public function test_staff_can_see_excludes_client_and_unassigned_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $rec = $this->record($client->id, $lawyer->id);

        // قناة الملاحظات الداخلية: العميل ممنوع حتى لو كان المالك
        $this->assertFalse(ChannelAccess::staffCanSee($client, $rec));
        $this->assertTrue(ChannelAccess::staffCanSee($lawyer, $rec));
        $this->assertFalse(ChannelAccess::staffCanSee($otherLawyer, $rec));
        $this->assertTrue(ChannelAccess::staffCanSee($employee, $rec));
    }

    public function test_lawyer_cannot_subscribe_to_unassigned_record(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $assigned = User::factory()->create(['role' => Role::Lawyer]);
        $stranger = User::factory()->create(['role' => Role::Lawyer]);

        $rec = $this->record($client->id, $assigned->id);

        $this->assertNull(ChannelAccess::presenceMember($stranger, $rec));
        $this->assertFalse(ChannelAccess::ownerOrStaff($stranger, $rec));
        // سجلّ بلا إسناد: لا يلتقطه أيّ محامٍ (assigned_lawyer_id = 0 ≠ id)
        $this->assertFalse(ChannelAccess::staffCanSee($stranger, $this->record($client->id, null)));
    }

    public function test_presence_member_gates_double_reply_notice_to_staff_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'منيرة الحربي']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $rec = $this->record($client->id, $lawyerA->id);

        // العميل لا ينضم لقناة الحضور مطلقاً — التنبيه شأن داخلي (ولو كان مالك التذكرة)
        $this->assertNull(ChannelAccess::presenceMember($client, $rec));
        // محامٍ غير مسند ممنوع (نفس عزل الملاحظات الداخلية)
        $this->assertNull(ChannelAccess::presenceMember($lawyerB, $rec));

        // موظف المكتب يُقبل، وتُعاد بياناته للعرض في اللافتة
        $this->assertSame(
            ['id' => $employee->id, 'name' => 'منيرة الحربي', 'role' => $employee->role->label()],
            ChannelAccess::presenceMember($employee, $rec)
        );

        // المحامي المسند والإدارة يُقبلان أيضاً
        $this->assertNotNull(ChannelAccess::presenceMember($lawyerA, $rec));
        $this->assertNotNull(ChannelAccess::presenceMember($admin, $rec));
    }

    public function test_employee_may_subscribe_to_any_record(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // سجلّ لعميل غريب مُسند لمحامٍ آخر — الموظف والإدارة يريانه (مكتب واحد بلا فروع)
        $rec = $this->record($client->id, User::factory()->create(['role' => Role::Lawyer])->id);
        $this->assertTrue(ChannelAccess::staffCanSee($employee, $rec));
        $this->assertTrue(ChannelAccess::staffCanSee($admin, $rec));
        $this->assertTrue(ChannelAccess::ownerOrStaff($client, $rec));
    }
}
