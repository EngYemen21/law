<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * حجزُ موعد الاستشارة بدقّة الدقيقة — وشاشةٌ لا تَعِد بما لا يقع.
 *
 * الخادم يقبل أيّ `H:i` صالحٍ غير ماضٍ، لا شبكةً نصف ساعيّة. ومنذ 2026-09-14 يحدّد
 * **الطاقم** الموعد بعد السداد: فمن تعذّر عليه محامٍ في الدقيقة المختارة يُقال له ذلك بصدق
 * ليختار وقتاً أو محامياً آخر — ولا يُحجز موعدٌ بلا مستشار ولا يُوعد العميل بشيء.
 */
class ConsultBookingCustomTimeTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** استشارةٌ مسدَّدةٌ واقفةٌ عند «بانتظار تحديد الموعد». */
    private function paidConsult(User $client): Consult
    {
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'TK-CT-'.uniqid(), 'type' => 'استشارة', 'subject' => 'نزاع تجاري',
        ]);

        return $this->requestPricedAndPaid($client, $ticket, 'video');
    }

    // ————— ١ · الدقيقة المخصّصة تُثبَّت كما اختارها الطاقم —————

    public function test_a_custom_minute_is_booked_exactly_and_assigned_when_someone_is_free(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->paidConsult($client);

        $day = now()->addDays(2)->toDateString();

        // 11:07 ليست من شبكة الفترات — والخادم لا يقيّد الوقت بها
        $this->adminPublishes($consult, ['date' => $day, 'time' => '11:07'])->assertOk();

        $consult->refresh();
        $this->assertSame('11:07', $consult->time, 'الموعد يُثبَّت بالدقيقة المختارة');
        $this->assertSame($day, $consult->day);
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id, 'ومحامٍ متفرّغ يُسنَد تلقائيّاً');
    }

    public function test_a_custom_minute_with_nobody_free_is_refused_and_promises_nothing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->paidConsult($client);
        $before = UserNotification::where('user_id', $client->id)->count();

        // لا محاميَ في المكتب إطلاقاً ⇒ يتعذّر الإسناد
        $this->adminPublishes($consult, ['date' => now()->addDays(2)->toDateString(), 'time' => '11:07'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lawyer_id');

        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status, 'لا موعدَ بلا مستشار');
        $this->assertNull($consult->starts_at);
        $this->assertSame(0, Appointment::count());
        $this->assertSame($before, UserNotification::where('user_id', $client->id)->count(), 'ولا يُوعَد العميل بشيء');
    }

    public function test_staff_can_schedule_a_custom_minute_too(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $consult = $this->paidConsult($client);

        $this->actingAs($this->schedulingEmployee())->postJson('/employee/schedule', [
            'client_id' => $client->id,
            'type' => 'video',
            'date' => now()->addDays(2)->format('Y-m-d'),
            'time' => '11:07',
            'ticket_no' => $consult->ticket->number,
        ])->assertSuccessful();

        // اقتراح الموظّف يحجز الخانة بالدقيقة نفسها — بانتظار اعتماد الإدارة
        $this->assertDatabaseHas('appointments', ['time' => '11:07', 'status' => 'بانتظار الاعتماد']);
    }

    // ————— ٢ · الشاشة تعرض المنتقي المشترك ولا تقفل التأكيد —————

    /**
     * **مُنتقي العميل القديم (`SpecialistPicker`) حُذف ولا يعود** (2026-09-26).
     *
     * منذ 2026-09-14 يحدّد الطاقم الموعد، فلم يبقَ مَن يرسم المُنتقي — وكان يحمل نسخةً رابعة من
     * اختصار اسم المحامي في المتصفّح («أ. محمد»)، خارج المصدر الواحد `LawyerName`. وكان هذا
     * الاختبار يفحص نصّ ملفٍّ ميّت. الدقيقة المخصّصة للطاقم تحرسها الاختبارات أدناه.
     */
    public function test_the_dead_client_picker_stays_deleted(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/components/SpecialistPicker.tsx'));
    }

    public function test_confirm_buttons_do_not_require_a_lawyer_that_is_never_sent(): void
    {
        foreach (['js/pages/ticketchat.tsx', 'js/components/babylon/BookingActions.tsx'] as $file) {
            $src = file_get_contents(resource_path($file));
            $this->assertStringNotContainsString(
                'disabled={!lawyerId || !time',
                $src,
                "{$file}: اشتراطُ محامٍ لا يُرسَل يقفل الدقيقة المخصّصة"
            );
        }
    }

    public function test_the_staff_modal_opens_custom_minutes_too(): void
    {
        $src = file_get_contents(resource_path('js/pages/employee/ticketchat.tsx'));

        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', $src)));
        $this->assertContains('allowCustom', $lines, 'الدقيقة المخصّصة ما زالت مغلقة في مودال الموظّف');
        // وحقل التاريخ منسَّق كنظائره
        $this->assertStringContainsString('className="input"'.chr(10).'            type="date"', $src);
    }

    /**
     * **وشاشة «جدولة المواعيد» عند الموظّف كذلك** (قرار المالك 2026-09-20).
     *
     * وهي الشاشة الأساسيّة لتحديد موعد الاستشارة بعد السداد، وكانت تمرّر `allowCustom={false}`
     * فتحصر الموظّف في الفترات المعروضة — والخادم يقبل أيّ دقيقة (الاختبار الأوّل أعلاه)، فيتعذّر
     * موعدٌ اتُّفق عليه مع العميل في 11:20.
     */
    public function test_the_staff_schedule_screen_opens_custom_minutes(): void
    {
        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', (string) file_get_contents(resource_path('js/pages/employee/schedule.tsx')))));

        $this->assertContains('allowCustom', $lines, 'الدقيقة المخصّصة مغلقة في شاشة جدولة المواعيد');
        $this->assertNotContains('allowCustom={false}', $lines, 'عاد إغلاق الدقيقة المخصّصة في شاشة جدولة المواعيد');
    }

    /**
     * **ونوافذ الاجتماعات الثلاث** (قرار المالك 2026-09-20): دعوةٌ جديدة، وإعادةُ إرسالٍ بموعد
     * جديد، وإعادةُ جدولة. الخوادم الثلاثة تقبل أيّ دقيقة (`MeetRequestController::store`
     * و`::resend`، و`MeetingController::reschedule` ⇐ `BookingMoment::rules`)، وكانت الأخيرتان
     * تحصران الطاقم في الفترات المعروضة.
     */
    public function test_the_meeting_dialogs_open_custom_minutes(): void
    {
        $src = (string) file_get_contents(resource_path('js/lib/meeting-ui.tsx'));
        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', $src)));

        $this->assertSame(3, count(array_filter($lines, fn (string $l) => $l === 'allowCustom')), 'نوافذ الاجتماعات الثلاث لا تفتح الدقيقة المخصّصة');
        $this->assertNotContains('allowCustom={false}', $lines, 'عاد إغلاق الدقيقة المخصّصة في نوافذ الاجتماعات');
    }
}
