<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الاجتماع لا يُفتح لأيّ موظّف: كان `ChannelAccess` بلا صلاحيّةٍ للاجتماع، فكلّ موظّفٍ يأخذ من
 * `ZoomController::sdkSignature` توقيع **مضيف** ورمز ZAK لحساب المكتب، ويشترك في بثّ غرفته —
 * وشاشات الاجتماعات نفسها محروسة بـ«إرسال دعوات الاجتماعات».
 */
class MeetingStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    /** المصنع يمنح الموظّف كلّ الصلاحيّات — فالموظّف هنا بصلاحيّة شاشةٍ أخرى وحدها. */
    private function employeeWithout(): User
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([Permission::findOrCreate('إدارة التذاكر')]);

        return $employee;
    }

    public function test_employee_without_meeting_permission_cannot_see_a_meeting(): void
    {
        $employee = $this->employeeWithout();

        $this->assertFalse(ChannelAccess::staffCanSee($employee, new Meeting));
        $this->assertFalse(ChannelAccess::roomMember($employee, new Meeting));
    }

    public function test_employee_with_either_meeting_permission_can_see_a_meeting(): void
    {
        foreach (['إرسال دعوات الاجتماعات', 'إدارة الاجتماعات'] as $permission) {
            $employee = $this->employeeWithout();
            $employee->givePermissionTo(Permission::findOrCreate($permission));

            $this->assertTrue(ChannelAccess::staffCanSee($employee, new Meeting), $permission);
        }
    }

    public function test_zoom_signature_endpoint_refuses_an_employee_without_meeting_permission(): void
    {
        config(['services.zoom.sdk_key' => 'SDKKEY', 'services.zoom.sdk_secret' => 'SDKSECRET']);
        $employee = $this->employeeWithout();
        $meeting = Meeting::create([
            'user_id' => User::factory()->create()->id, 'ref' => 'MTG-SEC-1', 'title' => 'اجتماع عميل',
            'type' => 'عميل', 'when_label' => 'اليوم', 'status' => 'قادم', 'meet_id' => '987654321',
        ]);

        $this->actingAs($employee)
            ->postJson('/zoom/sdk-signature', ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertForbidden();
    }
}
