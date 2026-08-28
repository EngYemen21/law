<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Meeting;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Support\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انشغال المحامي — مصدر واحد لكل المسارات.
 *
 * كان الانشغال يُحسب في موضعين بمصادر مختلفة:
 *   • LawyerAvailability::isBusy وslotsFor يقرآن **Appointment وحده**.
 *   • Staff\MeetRequestController::busy يقرأ Meeting + Consult + MeetRequest.
 * فكان الموظف يجدول موعداً لمحامٍ في نفس لحظة اجتماعه — والفترة تظهر «متاحة» في مودال
 * الحجز لأنه يقرأ المصدر الأضيق. المنطق كلّه في LawyerAvailability::busyIntervals الآن.
 */
class BusySourceUnifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function lawyerWithMeeting(\DateTimeInterface $at, int $minutes = 60): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        Meeting::create([
            'user_id' => null, 'ref' => 'M-BUSY-1', 'title' => 'اجتماع داخلي',
            'when_label' => 'x', 'status' => 'قادم', 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => $at, 'dur' => $minutes.' دقيقة',
        ]);

        return $lawyer;
    }

    /** ⚠️ الانحدار الأساسي: اجتماع كان **لا يُرى** من مسار جدولة المواعيد. */
    public function test_a_meeting_now_blocks_the_appointment_scheduler(): void
    {
        $at = now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);
        $lawyer = $this->lawyerWithMeeting($at);

        $this->assertTrue(
            LawyerAvailability::isBusy($lawyer->id, Carbon::parse($at), 60),
            'الاجتماع لم يُحجب الموعد — المصدران ما زالا منفصلين.'
        );
    }

    /** وموعد يحجب أيضاً (المصدر الأصلي لم يُفقد بالتوحيد). */
    public function test_an_appointment_still_blocks(): void
    {
        $at = now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        Appointment::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'lawyer_id' => $lawyer->id, 'ext_id' => 'AP-BUSY-1', 'type' => 'استشارة حضورية',
            'ico' => 'office', 'lawyer' => $lawyer->name, 'day' => $at->format('Y-m-d'),
            'time' => '10:00', 'starts_at' => $at, 'duration_min' => 60,
            'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        $this->assertTrue(LawyerAvailability::isBusy($lawyer->id, Carbon::parse($at), 60));
    }

    /** الفترة الحرّة تبقى حرّة — حارس ضدّ حجب مفرط بعد التوحيد. */
    public function test_a_free_slot_stays_free(): void
    {
        $at = now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);
        $lawyer = $this->lawyerWithMeeting($at);

        $this->assertFalse(
            LawyerAvailability::isBusy($lawyer->id, Carbon::parse($at)->setHour(9), 60),
            'حُجبت فترة حرّة — التوحيد أفرط في الحجب.'
        );
    }

    /**
     * مودال الحجز يعكس الحقيقة: اجتماع 90 دقيقة من 14:00 يحجب 14:00 **و15:00**.
     * كان slotsFor يعلّم الفترة محجوزة فقط إن **بدأ** موعد عندها بالضبط.
     */
    public function test_the_slot_picker_reflects_overlap_not_just_start(): void
    {
        $at = now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);
        $lawyer = $this->lawyerWithMeeting($at, 90);

        $slots = collect(LawyerAvailability::slotsFor($lawyer->id, $at->format('Y-m-d')))
            ->keyBy('time');

        $this->assertTrue($slots['14:00']['taken'] ?? false, 'فترة بداية الاجتماع تبدو متاحة.');
        $this->assertTrue($slots['15:00']['taken'] ?? false, 'امتداد الاجتماع لا يحجب الفترة التالية.');
    }

    /** ورسالة الرفض توجّه للإدارة العليا لإسناد محامٍ مختصّ آخر (قرار صاحب المنتج). */
    public function test_the_rejection_points_to_senior_management(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());
        $client = User::factory()->create(['role' => Role::Client]);

        $at = now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);
        $lawyer = $this->lawyerWithMeeting($at);

        $this->actingAs($employee)->post('/employee/schedule', [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id, 'type' => 'office',
            'date' => $at->format('Y-m-d'), 'time' => '14:00',
        ])->assertSessionHasErrors('time');

        $this->assertStringContainsString(
            'العليا', // «للإدارة» بلام واحدة فلا تطابق «الإدارة» حرفياً
            session('errors')->first('time'),
            'الرسالة لا توجّه للإدارة العليا.'
        );
    }
}
