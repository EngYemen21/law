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
 * تمنع التسرّب العابر للفروع الذي كان يصرّح لأي موظف/محامٍ.
 */
class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /** كائن سجل بسيط يحمل حقول العزل (user_id/assigned_lawyer_id/branch). */
    private function record(int $ownerId, ?int $lawyerId, ?string $branch): object
    {
        return (object) ['user_id' => $ownerId, 'assigned_lawyer_id' => $lawyerId, 'branch' => $branch];
    }

    public function test_owner_or_staff_scopes_to_owner_assigned_and_branch(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الدمام']);
        $empRiyadh = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $empDammam = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الدمام']);
        $admin = User::factory()->create(['role' => Role::Admin, 'branch' => 'فرع الدمام']);
        $otherClient = User::factory()->create(['role' => Role::Client]);

        $rec = $this->record($client->id, $lawyerA->id, 'فرع الرياض');

        $this->assertTrue(ChannelAccess::ownerOrStaff($client, $rec));       // المالك
        $this->assertFalse(ChannelAccess::ownerOrStaff($otherClient, $rec)); // عميل آخر
        $this->assertTrue(ChannelAccess::ownerOrStaff($lawyerA, $rec));      // المحامي المسند
        $this->assertFalse(ChannelAccess::ownerOrStaff($lawyerB, $rec));     // محامٍ غير مسند
        $this->assertTrue(ChannelAccess::ownerOrStaff($empRiyadh, $rec));    // موظف نفس الفرع
        $this->assertFalse(ChannelAccess::ownerOrStaff($empDammam, $rec));   // موظف فرع آخر
        $this->assertTrue(ChannelAccess::ownerOrStaff($admin, $rec));        // الإدارة
    }

    public function test_staff_can_see_excludes_client_and_unscoped(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $empOther = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الدمام']);

        $rec = $this->record($client->id, $lawyer->id, 'فرع الرياض');

        // قناة الملاحظات الداخلية: العميل ممنوع حتى لو كان المالك
        $this->assertFalse(ChannelAccess::staffCanSee($client, $rec));
        $this->assertTrue(ChannelAccess::staffCanSee($lawyer, $rec));
        $this->assertFalse(ChannelAccess::staffCanSee($empOther, $rec));
    }

    public function test_presence_member_gates_double_reply_notice_to_staff_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $empRiyadh = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض', 'name' => 'منيرة الحربي']);
        $empDammam = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الدمام']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $rec = $this->record($client->id, $lawyerA->id, 'فرع الرياض');

        // العميل لا ينضم لقناة الحضور مطلقاً — التنبيه شأن داخلي (ولو كان مالك التذكرة)
        $this->assertNull(ChannelAccess::presenceMember($client, $rec));
        // موظف فرع آخر ومحامٍ غير مسند — ممنوعان (نفس عزل الملاحظات الداخلية)
        $this->assertNull(ChannelAccess::presenceMember($empDammam, $rec));
        $this->assertNull(ChannelAccess::presenceMember($lawyerB, $rec));

        // موظف نفس الفرع يُقبل، وتُعاد بياناته للعرض في اللافتة
        $member = ChannelAccess::presenceMember($empRiyadh, $rec);
        $this->assertSame(
            ['id' => $empRiyadh->id, 'name' => 'منيرة الحربي', 'role' => $empRiyadh->role->label()],
            $member
        );

        // المحامي المسند والإدارة يُقبلان أيضاً
        $this->assertNotNull(ChannelAccess::presenceMember($lawyerA, $rec));
        $this->assertNotNull(ChannelAccess::presenceMember($admin, $rec));
    }

    public function test_null_branch_record_is_shared_pool_for_employees(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // سجل بلا فرع = المجمّع المشترك قبل الإسناد — يراه الموظف كما في حراس HTTP
        // (guardMeeting/guardConsult)؛ كان البثّ أشدّ فيرى الموظف السجلّ بالقائمة ولا يصله تحديثه اللحظي
        $rec = $this->record($client->id, null, null);
        $this->assertTrue(ChannelAccess::staffCanSee($employee, $rec));
        $this->assertTrue(ChannelAccess::staffCanSee($admin, $rec));
        $this->assertTrue(ChannelAccess::ownerOrStaff($client, $rec));
    }
}
