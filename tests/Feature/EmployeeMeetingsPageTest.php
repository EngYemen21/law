<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة اجتماعات الموظف (الدفعة 2): كان الموظف يُشعَر «الاجتماع متاح في لوحتك»
 * بلا أي صفحة (طريق مسدود) — المسارات الجديدة تعيد استخدام Staff\MeetingController بعزل الفرع.
 */
class EmployeeMeetingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_sees_branch_scoped_meetings_list_and_detail(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        Meeting::create(['ref' => 'M-8000', 'title' => 'اجتماع فرعنا', 'when_label' => 'اليوم', 'status' => 'قادم', 'branch' => 'فرع الرياض']);
        Meeting::create(['ref' => 'M-8001', 'title' => 'اجتماع مشترك بلا فرع', 'when_label' => 'اليوم', 'status' => 'قادم']);
        Meeting::create(['ref' => 'M-8002', 'title' => 'اجتماع فرع آخر', 'when_label' => 'اليوم', 'status' => 'قادم', 'branch' => 'فرع جدة']);

        // القائمة: فرعه + بلا فرع (المجمّع المشترك) دون فرع غيره
        $this->actingAs($employee)->get('/employee/meetings')
            ->assertInertia(fn ($p) => $p->component('employee/meetings')->has('meetings', 2));

        // التفاصيل: اجتماع فرعه يفتح، واجتماع الفرع الآخر 403 (IDOR)
        $this->actingAs($employee)->get('/employee/meeting?id=M-8000')
            ->assertInertia(fn ($p) => $p->component('employee/meeting')->where('meeting.id', 'M-8000'));
        $this->actingAs($employee)->get('/employee/meeting?id=M-8002')->assertForbidden();
    }

    public function test_employee_lifecycle_actions_work_within_branch(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $meeting = Meeting::create(['ref' => 'M-8010', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'قادم', 'branch' => 'فرع الرياض']);
        $foreign = Meeting::create(['ref' => 'M-8011', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'قادم', 'branch' => 'فرع جدة']);

        $this->actingAs($employee)->post(route('employee.meetings.end', $meeting), ['attend' => 80])->assertRedirect();
        $this->assertSame('منتهٍ', $meeting->fresh()->status);

        $this->actingAs($employee)->post(route('employee.meetings.end', $foreign), ['attend' => 80])->assertForbidden();
        $this->assertSame('قادم', $foreign->fresh()->status);
    }

    public function test_employee_without_permission_is_redirected(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $employee->syncPermissions([]); // بلا «إرسال دعوات الاجتماعات»

        $this->actingAs($employee)->get('/employee/meetings')->assertRedirect();
    }
}
