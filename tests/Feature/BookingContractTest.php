<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ConsultBooked;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\ConsultAppointments;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **شبكة توثيق السلوك الحاليّ للحجز — بما فيه الخطأ.**
 *
 * كلّ دفعةٍ في خطّة تطوير الحجز تُغيّر مخرجاً مرئيّاً. وبلا شبكةٍ تُثبّت ما يقع اليوم
 * تصير «لا تكسر ما يعمل» دعوى لا تُتحقَّق: لا يُعرَف أيّ تغيّرٍ كان مقصوداً وأيّه انزلق.
 *
 * فهذا الملفّ **يوثّق ولا يحكم**. وكلّ تأكيدٍ يُثبّت عطلاً معروفاً موسومٌ بـ`@wrong`
 * وبالدفعة التي ستقلبه — فحين تسقط هذه الاختبارات في الدفعة المعنيّة يكون سقوطها
 * **هو الدليل** على أن الإصلاح وقع، لا إشارةَ انكسار.
 *
 * ومنذ 2026-09-14 يحدّد **الطاقم** الموعد بعد السداد (`ConsultAppointments`)، لا العميل.
 */
class BookingContractTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** استشارةٌ مدفوعة بانتظار تحديد الموعد — نقطة انطلاق حجز الطاقم. */
    private function payableConsult(User $client): Consult
    {
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-BC-'.uniqid(), 'status' => 'بانتظار حجز الاستشارة',
        ]);

        return $this->requestPricedAndPaid($client, $ticket, 'video');
    }

    private function lawyer(string $dept = 'القضايا التجارية'): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => $dept]);
    }

    // ══ المدخلات: ما يُقبل وما يُرفض اليوم ══

    /**
     * **قُلِب في الدفعة ١.** كان `regex:/^\d{2}:\d{2}$/` يقبل `99:99` و`25:00`، ثم
     * `Carbon::parse` غير المُحاط يرمي فيُرى **خطأ ٥٠٠**. صار `date_format:H:i`
     * يرفضه في طبقة التحقّق برسالةٍ مفهومة.
     */
    public function test_an_impossible_time_is_rejected_with_a_validation_message(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer();
        $consult = $this->payableConsult($client);
        $date = now()->addDays(2)->toDateString();

        foreach (['99:99', '25:00', '12:60', '7:00', ''] as $time) {
            $this->adminPublishes($consult, ['date' => $date, 'time' => $time])
                ->assertJsonValidationErrors('time');
        }

        $this->assertNull($consult->fresh()->starts_at, 'ولا يُحجز شيء');
    }

    /** والوقت السليم يمرّ — الحدّ الأدنى الذي يجب ألّا ينكسر أبداً. */
    public function test_a_valid_slot_is_accepted_and_creates_an_appointment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer();
        $consult = $this->payableConsult($client);
        $date = now()->addDays(2)->toDateString();

        $this->adminPublishes($consult, ['date' => $date, 'time' => '10:00'])->assertOk();

        $consult->refresh();
        $this->assertNotNull($consult->starts_at);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(60, (int) $consult->duration_min, 'المدّة ثابتة ٦٠ اليوم');
    }

    /** ولا حدّ أعلى للتاريخ في أيّ مسار — يُقبل عامٌ بعيد. */
    public function test_wrong_there_is_no_upper_bound_on_the_booking_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer();
        $consult = $this->payableConsult($client);

        // @wrong الدفعة ٥ تُضيف أفقاً. اليوم لا يمنعه إلّا ألّا يجد الخادم محامياً.
        $this->adminPublishes($consult, ['date' => '2099-01-01', 'time' => '10:00'])->assertOk();

        $this->assertSame('2099-01-01', $consult->fresh()->starts_at?->toDateString());
    }

    // ══ المحرّك: إجابتان لكلّ سؤال ══

    /**
     * **قُلِب (قرار المالك 2026-09-28): الحجز بدوام المكتب.** كان التوفّر ٢٤ شريحةً لكلّ محامٍ في كلّ
     * يوم. الآن ٠٩:٠٠–٢٢:٠٠ من الأحد إلى الخميس (إعدادا `consult_day_*` و`consult_work_days`).
     */
    public function test_availability_follows_office_hours_and_days(): void
    {
        $lawyer = $this->lawyer();
        $sunday = now()->startOfWeek(Carbon::SUNDAY);

        foreach ([1, 2, 3] as $weekday) {
            $slots = LawyerAvailability::slotsFor($lawyer->id, $sunday->copy()->addDays($weekday + 7)->toDateString());
            $this->assertCount(13, $slots, 'من ٠٩:٠٠ إلى آخر بدايةٍ ٢١:٠٠');
            $this->assertSame('09:00', $slots[0]['time']);
            $this->assertSame('21:00', $slots[12]['time']);
        }

        foreach ([5, 6] as $weekend) {
            $this->assertSame([], LawyerAvailability::slotsFor($lawyer->id, $sunday->copy()->addDays($weekend + 7)->toDateString()), 'الجمعة والسبت بلا شرائح');
        }
    }

    /**
     * **قُلِب في الدفعة ١ — المولّد صار واحداً.**
     *
     * كان `slotsFor` يحسب التداخل صحيحاً، و`rankedSpecialists` — الذي يغذّي **شبكة
     * الحجز** — يستعمل مولّداً ثانياً يطابق ساعة البداية على المواعيد وحدها. فاجتماعٌ
     * ٩٠د من ١٤:٠٠ يترك ١٥:٠٠ تبدو متاحة بينما المحرّك يحجبها.
     */
    public function test_the_client_grid_and_the_engine_give_the_same_answer(): void
    {
        $lawyer = $this->lawyer();
        $day = now()->addDays(2)->toDateString();

        Meeting::create([
            'user_id' => $lawyer->id, 'ref' => 'M-BC-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => $day.' · 14:00', 'type' => 'اجتماع', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $day.' 14:00:00', 'dur' => '90 دقيقة',
        ]);

        $engine = collect(LawyerAvailability::slotsFor($lawyer->id, $day))->keyBy('time');
        $ranked = collect(LawyerAvailability::rankedSpecialists('القضايا التجارية', null, $day))
            ->firstWhere('id', $lawyer->id);
        $grid = collect($ranked['slots'])->keyBy('time');

        $this->assertTrue($engine['14:00']['taken'], 'ساعة البداية محجوبة');
        $this->assertTrue($engine['15:00']['taken'], 'وامتدادها كذلك');

        // المقارنة الشاملة: أيّ افتراقٍ في أيّ ساعة يُسقط الحارس
        foreach ($engine as $time => $slot) {
            $this->assertSame(
                $slot['taken'],
                $grid[$time]['taken'],
                "الساعة {$time}: المحرّك وشبكة الحجز يجب أن يتّفقا"
            );
        }
    }

    /**
     * **الفحص الأوّليّ يمنع:** اجتماعٌ في الوقت نفسه يجعل `assignLawyer` لا يجد محامياً،
     * لأنّه يستعمل `isBusy()` العريض (أربعة جداول).
     */
    public function test_a_meeting_blocks_assignment_so_no_lawyer_is_offered(): void
    {
        $lawyer = $this->lawyer();
        $day = now()->addDays(2)->toDateString();
        $at = Carbon::parse($day.' 11:00');

        Meeting::create([
            'user_id' => $lawyer->id, 'ref' => 'M-GRD-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => $day.' · 11:00', 'type' => 'اجتماع', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $day.' 11:00:00', 'dur' => '60 دقيقة',
        ]);

        $this->assertTrue(LawyerAvailability::isBusy($lawyer->id, $at, 60), 'الفحص العريض يراه مشغولاً');
        $this->assertNull(
            LawyerAvailability::assignLawyer('القضايا التجارية', null, $at, 60),
            'فلا يُسنَد محامٍ — والطلب يُردّ ٤٢٢'
        );

        // وفي ساعةٍ أخرى يُسنَد — فالمنع مشروطٌ بالتعارض لا دائم
        $this->assertNotNull(
            LawyerAvailability::assignLawyer('القضايا التجارية', null, Carbon::parse($day.' 13:00'), 60)
        );
    }

    // ══ الطبقة الحرّة ══
    //
    // حارسان كانا هنا يُثبّتان قبولَ «الاثنين القادم» واختراعَ «اليوم · 10:00»، وقد
    // **قُلِبا في الدفعة ٣**: انتقلا إلى `BookingValidationContractTest` مصفوفةً.

    // ══ Zoom يتبع الموعد ══
    //
    // ثلاثة حراسٍ انتقلت إلى `BookingZoomSyncTest` حارساتٍ موجبة.

    // ══ حراسات الدفعة ١ ══

    /**
     * **الحارس الأثمن في الدفعة:** تراجُعُ المعاملة لا يُرسل بريد تأكيد.
     *
     * البريد والتقويم والإشعار أحداثٌ تُطلق **بعد التزام** انتقال النشر
     * (`Workflow` ⇐ `HandleAppointmentPublished`). فتراجُعُ المعاملة المحيطة لأيّ سبب
     * لا يُخبر العميل والمحامي بموعدٍ **لا وجود له**.
     */
    public function test_a_rolled_back_booking_sends_no_confirmation_mail(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.test']);
        $lawyer = $this->lawyer();
        $consult = $this->payableConsult($client);
        $day = now()->addDays(2)->toDateString();

        try {
            DB::transaction(function () use ($consult, $lawyer, $day) {
                ConsultAppointments::publish($consult->fresh(), $this->journeyAdmin(), [
                    'lawyer_id' => $lawyer->id, 'date' => $day, 'time' => '10:00',
                ]);

                throw new \RuntimeException('تعثّرٌ بعد الحجز');
            });
        } catch (\RuntimeException) {
            // متوقَّع
        }

        $this->assertNull($consult->fresh()->starts_at, 'الحجز تراجع');
        // بريد السداد يُصفّ في تهيئة الحالة قبل الحجز — والمقيس بريد تأكيد الموعد وحده
        Mail::assertNothingSent();
        Mail::assertNotQueued(ConsultBooked::class);
        $this->assertSame(0, Appointment::count());
    }

    /** والبريد **لا يُرسَل داخل الطلب** — يُصفّ بعد الالتزام. */
    public function test_the_confirmation_mail_is_deferred_not_sent_inline(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c2@example.test']);
        $this->lawyer();
        $consult = $this->payableConsult($client);

        $this->adminPublishes($consult, [
            'date' => now()->addDays(2)->toDateString(), 'time' => '10:00',
        ])->assertOk();

        // الحجز وقع فعلاً...
        $this->assertNotNull($consult->fresh()->starts_at);
        $this->assertSame(1, Appointment::count());

        // ...والبريد لم يُرسَل متزامناً داخل الطلب.
        Mail::assertNothingSent();
    }

    /**
     * **قُلِب في الدفعة ١** — الحارس النهائيّ صار يرى ما يراه الفحص الأوّليّ.
     *
     * كان `guardNoConflict` يقرأ `appointments` وحده بينما `isBusy` يقرأ أربعة
     * جداول، فهو خطّ الدفاع الأخير وأضيقُ ممّا سبقه.
     */
    public function test_the_final_guard_now_sees_every_busy_source(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $consult = $this->payableConsult($client);
        $day = now()->addDays(2)->toDateString();

        Meeting::create([
            'user_id' => $lawyer->id, 'ref' => 'M-FG-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => $day.' · 11:00', 'type' => 'اجتماع', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $day.' 11:00:00', 'dur' => '60 دقيقة',
        ]);

        // المحامي مُمرَّر صراحةً فلا فحصَ أوّليّ — يصل الحارس النهائيّ وحده
        $this->expectException(ValidationException::class);

        ConsultAppointments::publish($consult->fresh(), $this->journeyAdmin(), [
            'lawyer_id' => $lawyer->id, 'date' => $day, 'time' => '11:00',
        ]);
    }
}
