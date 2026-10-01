<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\ChannelAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **الموظّف يتصرّف في اجتماعٍ له صلةٌ به وحده** (قرار المالك 2026-10-01).
 *
 * ثبت بالمتصفّح أنّ موظّفاً بصلاحيّة «إرسال دعوات الاجتماعات» وحدها ألغى اجتماعاً لمحامٍ آخر لا صلة له به.
 * الصلة: مشاركٌ أو مرسلُ دعوته؛ ومن مُنح «إدارة الاجتماعات» مشرفٌ على الكلّ. والاطّلاع باقٍ للجميع.
 */
class EmployeeMeetingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    private function employee(array $perms = ['إرسال دعوات الاجتماعات']): User
    {
        $u = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $u->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $u;
    }

    private function meeting(): Meeting
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        return Meeting::create([
            'ref' => 'M-SCOPE-'.$lawyer->id, 'title' => 'اجتماع محامٍ آخر', 'type' => 'اجتماع مع عميل', 'client_name' => 'عميل',
            'when_label' => 'x', 'starts_at' => now()->addDays(3), 'status' => MeetingStatus::Upcoming->value,
            'assigned_lawyer_id' => $lawyer->id,
        ]);
    }

    public function test_unrelated_employee_cannot_act_on_the_meeting(): void
    {
        $emp = $this->employee();
        $m = $this->meeting();

        $this->actingAs($emp)->post(route('employee.meetings.cancel', $m))->assertForbidden();
        $this->actingAs($emp)->post(route('employee.meetings.start', $m))->assertForbidden();
        $this->actingAs($emp)->post(route('employee.meetings.minutes', $m), ['minutes' => 'محاولة'])->assertForbidden();
        $this->actingAs($emp)->post(route('employee.meetings.reschedule', $m), [
            'day' => now()->addWeek()->toDateString(), 'time' => '11:00', 'reason' => 'client_request',
        ])->assertForbidden();

        $fresh = $m->fresh();
        $this->assertSame(MeetingStatus::Upcoming->value, $fresh->status);
        $this->assertNull($fresh->minutes);
    }

    public function test_unrelated_employee_still_sees_it_read_only_and_cannot_enter(): void
    {
        $emp = $this->employee();
        $m = $this->meeting();

        $this->actingAs($emp)->get(route('employee.meeting', ['id' => $m->ref]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('meeting.canAct', false)->where('meeting.canEnter', false));
        $this->actingAs($emp)->get(route('employee.meetings'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('meetings.0.canAct', false));

        $this->assertFalse(ChannelAccess::roomMember($emp, $m), 'لا غرفة ولا توقيع Zoom لموظّفٍ بلا صلة');
        $this->assertTrue(ChannelAccess::ownerOrStaff($emp, $m), 'وبثّ الحالة للاطّلاع باقٍ');
        $this->actingAs($emp)->postJson(route('zoom.signature'), ['ref' => $m->ref, 'kind' => 'meeting'])->assertForbidden();
    }

    public function test_participant_employee_can_act(): void
    {
        $emp = $this->employee();
        $m = $this->meeting();
        $m->participantUsers()->sync([$emp->id]);

        $this->assertTrue(ChannelAccess::roomMember($emp, $m));
        $this->actingAs($emp)->post(route('employee.meetings.cancel', $m))->assertRedirect();
        $this->assertSame(MeetingStatus::Cancelled->value, $m->fresh()->status);
    }

    public function test_invitation_sender_can_act(): void
    {
        $emp = $this->employee();
        $m = $this->meeting();
        MeetRequest::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'meeting_id' => $m->id, 'ref' => 'MR-SCOPE',
            'service' => 's', 'type' => 'استشارة مرئية', 'day' => now()->addDays(3)->toDateString(), 'time' => '10:00',
            'sent_by' => $emp->name, 'sent_by_id' => $emp->id, 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($emp)->post(route('employee.meetings.minutes', $m), ['minutes' => 'محضر من مرسل الدعوة.'])->assertRedirect();
        $this->assertSame('محضر من مرسل الدعوة.', $m->fresh()->minutes);
        $this->actingAs($emp)->get(route('employee.meetings'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('meetings.0.canAct', true)->where('meetings.0.canEnter', true));
    }

    public function test_employee_with_manage_meetings_supervises_all(): void
    {
        $emp = $this->employee(['إرسال دعوات الاجتماعات', 'إدارة الاجتماعات']);
        $m = $this->meeting();

        $this->actingAs($emp)->post(route('employee.meetings.cancel', $m))->assertRedirect();
        $this->assertSame(MeetingStatus::Cancelled->value, $m->fresh()->status);
    }

    public function test_admin_is_unaffected(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $m = $this->meeting();

        $this->actingAs($admin)->get(route('admin.meeting', ['id' => $m->ref]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('meeting.canAct', true)->where('meeting.canEnter', true));
        $this->actingAs($admin)->post(route('admin.meetings.cancel', $m))->assertRedirect();
    }
}
