<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * التبويب الزمني الموحّد — تبويب واحد لكل لوحة بدل تبويبين.
 *
 * كان الزمن موزّعاً: العميل «المواعيد» + «التقويم» · الموظف «جدولة المواعيد» + «التقويم» ·
 * الإدارة **بلا أي تبويب زمني**. والأخطر أن شاشة الجدولة لا تعرض جلسات المحاكم ولا
 * الاجتماعات إطلاقاً، فكان الموظف يحجز موعداً فوق جلسة محكمة دون أن يرى تعارضاً.
 *
 * ما يحرسه هذا الملفّ: أن التوحيد لم يكسر منطق الحجز (لم يُمسّ أصلاً — الشاشة هي التي
 * تحرّكت لا المتحكّم)، وأن المسارات القديمة ما زالت تصل (روابط البريد والإشعارات).
 */
class UnifiedCalendarTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function employee(): User
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());

        return $employee;
    }

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto']);
    }

    /** المسارات القديمة تُحوَّل ولا تموت — بريد تأكيد الموعد وإشعارات سابقة تشير إليها. */
    public function test_the_old_routes_redirect_into_the_unified_tab(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($client)->get('/appointments')->assertRedirect(route('calendar'));

        $this->actingAs($this->employee())->get('/employee/schedule')->assertRedirect(route('employee.calendar'));
    }

    /** بطاقة الموعد PDF مسار مستقلّ لم يُمسّ — البريد يشير إليها مباشرة. */
    public function test_the_appointment_card_route_survives_the_merge(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $appt = Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-CARD-1', 'type' => 'استشارة حضورية',
            'ico' => 'office', 'lawyer' => 'المستشار', 'day' => now()->format('Y-m-d'), 'time' => '10:00',
            'starts_at' => now()->addDay()->setHour(10)->setMinute(0), 'duration_min' => 60,
            'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        $this->assertNotNull(route('appointments.card', $appt));
        $this->actingAs($client)->get(route('appointments.card.plain', $appt))->assertOk();
    }

    /** لوحة الموظف: منظرا التبويب الواحد يصلان معاً في حمولة واحدة. */
    public function test_employee_tab_carries_both_views(): void
    {
        $this->actingAs($this->employee())->get('/employee/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('employee/calendar')
                ->has('events')        // منظر الأحداث
                ->has('appointments')  // منظر المواعيد
                ->has('clients')       // ومعه الحجز
                ->has('lawyers'));
    }

    /** لوحة العميل: التقويم صار يحمل مواعيده أيضاً بعد طيّ تبويب «المواعيد». */
    public function test_client_tab_carries_appointments_too(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->get('/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('calendar')->has('events')->has('appointments'));
    }

    /** الإدارة تكسب تبويباً زمنياً لأوّل مرّة — كانت عمياء عن جدول المكتب تماماً. */
    public function test_admin_gains_a_calendar_tab(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get('/admin/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/calendar')->has('events')->has('appointments'));
    }

    /**
     * حارس الانحدار الأهمّ: منطق الحجز لم يتحرّك، وهذا يُثبته.
     * الشاشة انتقلت من /employee/schedule إلى تبويب التقويم، والنقطة نفسها ما زالت تحرس.
     */
    public function test_booking_still_refuses_a_past_slot(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($this->employee())->post('/employee/schedule', [
            'client_id' => $client->id,
            'type' => 'office',
            'date' => now()->format('Y-m-d'),
            'time' => '00:01',
        ])->assertSessionHasErrors('time');
    }

    /** ونقطة الفترات المتاحة التي يناديها المودال ما زالت حيّة بعد نقل الشاشة. */
    public function test_the_slots_endpoint_the_modal_calls_still_answers(): void
    {
        $lawyer = $this->lawyer();

        $this->actingAs($this->employee())
            ->getJson('/employee/schedule/slots?lawyer_id='.$lawyer->id.'&date='.now()->addDay()->format('Y-m-d'))
            ->assertOk()
            ->assertJsonStructure(['slots']);
    }
}
