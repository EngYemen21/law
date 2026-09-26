<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة اجتماعات الموظف (الدفعة 2): كان الموظف يُشعَر «الاجتماع متاح في لوحتك»
 * بلا أي صفحة (طريق مسدود) — المسارات تعيد استخدام Staff\MeetingController.
 * بعد إزالة «الفرع»: الموظف يرى كل اجتماعات المكتب ويتصرّف عليها.
 */
class EmployeeMeetingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_sees_all_meetings_list_and_detail(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        Meeting::create(['ref' => 'M-8000', 'title' => 'اجتماع أول', 'when_label' => 'اليوم', 'status' => 'قادم']);
        Meeting::create(['ref' => 'M-8001', 'title' => 'اجتماع ثانٍ', 'when_label' => 'اليوم', 'status' => 'قادم']);
        Meeting::create(['ref' => 'M-8002', 'title' => 'اجتماع ثالث', 'when_label' => 'اليوم', 'status' => 'قادم']);

        // مكتب واحد بلا فروع: القائمة تضمّ كل الاجتماعات
        $this->actingAs($employee)->get('/employee/meetings')
            ->assertInertia(fn ($p) => $p->component('employee/meetings')->has('meetings', 3));

        $this->actingAs($employee)->get('/employee/meeting?id=M-8002')
            ->assertInertia(fn ($p) => $p->component('employee/meeting')->where('meeting.id', 'M-8002'));
    }

    public function test_employee_lifecycle_actions_work_on_any_meeting(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        // جاريان: الزرّ لا يُنهي إلّا اجتماعاً جارياً (قرار المالك 2026-09-26 — `EndMeeting::guard`)
        $meeting = Meeting::create(['ref' => 'M-8010', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'جارٍ']);
        $another = Meeting::create(['ref' => 'M-8011', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'جارٍ']);

        $this->actingAs($employee)->post(route('employee.meetings.end', $meeting), ['attend' => 80])->assertRedirect();
        $this->assertSame('منتهٍ', $meeting->fresh()->status);

        $this->actingAs($employee)->post(route('employee.meetings.end', $another), ['attend' => 80])->assertRedirect();
        $this->assertSame('منتهٍ', $another->fresh()->status);
    }

    public function test_employee_without_permission_is_redirected(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]); // بلا «إرسال دعوات الاجتماعات»

        $this->actingAs($employee)->get('/employee/meetings')->assertRedirect();
    }
}
