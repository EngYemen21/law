<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
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
 * ولا يُحذف منها شيء إلّا بعد أن يحلّ محلّه حارسٌ يُثبت السلوك الصحيح.
 */
class BookingContractTest extends TestCase
{
    use RefreshDatabase;

    /** استشارةٌ مدفوعة بانتظار اختيار الموعد — نقطة انطلاق مسار العميل. */
    private function payableConsult(User $client): Consult
    {
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-BC-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية', 'subject' => 'مطالبة',
            'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber',
        ]);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    private function lawyer(string $dept = 'القضايا التجارية'): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => $dept]);
    }

    // ══ المدخلات: ما يُقبل وما يُرفض اليوم ══

    /**
     * **قُلِب في الدفعة ١.** كان `regex:/^\d{2}:\d{2}$/` يقبل `99:99` و`25:00`، ثم
     * `Carbon::parse` غير المُحاط يرمي فيرى العميل **خطأ ٥٠٠**. صار `date_format:H:i`
     * يرفضه في طبقة التحقّق برسالةٍ يفهمها.
     */
    public function test_an_impossible_time_is_rejected_with_a_validation_message(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer();
        $consult = $this->payableConsult($client);
        $date = now()->addDays(2)->toDateString();

        foreach (['99:99', '25:00', '12:60', '7:00', ''] as $time) {
            $this->actingAs($client)
                ->post(route('consults.schedule', $consult), ['date' => $date, 'time' => $time])
                ->assertSessionHasErrors('time');
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

        $this->actingAs($client)
            ->post(route('consults.schedule', $consult), ['date' => $date, 'time' => '10:00'])
            ->assertRedirect();

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
        $this->actingAs($client)
            ->post(route('consults.schedule', $consult), ['date' => '2099-01-01', 'time' => '10:00'])
            ->assertRedirect();

        $this->assertSame('2099-01-01', $consult->fresh()->starts_at?->toDateString());
    }

    // ══ المحرّك: إجابتان لكلّ سؤال ══

    /** التوفّر اليوم **٢٤ شريحة** لكلّ محامٍ في كلّ يوم — بلا دوامٍ إطلاقاً. */
    public function test_wrong_every_lawyer_is_available_24_hours_every_day(): void
    {
        $lawyer = $this->lawyer();

        // @wrong الدفعة ٤ تُقيّده بدوام المكتب وساعات المحامي
        foreach ([2, 3, 4] as $daysAhead) {
            $slots = LawyerAvailability::slotsFor($lawyer->id, now()->addDays($daysAhead)->toDateString());
            $this->assertCount(24, $slots, 'كلّ ساعات اليوم شرائح');
            $this->assertSame('00:00', $slots[0]['time']);
            $this->assertSame('23:00', $slots[23]['time']);
        }
    }

    /** وساعات دوام المحامي مُخزَّنة ولا أثر لها. */
    public function test_wrong_configured_working_hours_have_no_effect(): void
    {
        $lawyer = $this->lawyer();
        $lawyer->forceFill(['work_start' => '09:00', 'work_end' => '17:00'])->save();

        // @wrong الدفعة ٤ تجعلها ٨ شرائح
        $slots = LawyerAvailability::slotsFor($lawyer->fresh()->id, now()->addDays(2)->toDateString());

        $this->assertCount(24, $slots, 'العمودان يُكتبان ولا يقرؤهما المحرّك');
    }

    /**
     * **قُلِب في الدفعة ١ — المولّد صار واحداً.**
     *
     * كان `slotsFor` يحسب التداخل صحيحاً، و`rankedSpecialists` — الذي يغذّي **شبكة
     * العميل** — يستعمل مولّداً ثانياً يطابق ساعة البداية على المواعيد وحدها. فاجتماعٌ
     * ٩٠د من ١٤:٠٠ يترك ١٥:٠٠ تبدو متاحة للعميل بينما المحرّك يحجبها.
     *
     * **وهذا الحارس هو الأثمن في الدفعة:** يقارن المخرجين مباشرةً، فيمنع افتراقهما
     * ثانيةً مهما أُضيف من مصادر انشغال.
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
                "الساعة {$time}: المحرّك وشبكة العميل يجب أن يتّفقا"
            );
        }
    }

    /**
     * **الفحص الأوّليّ يمنع، والحارس النهائيّ لا يرى.**
     *
     * صحّح هذا الاختبارُ اعتقاداً خاطئاً عندي: ظننتُ أن اجتماعاً في الوقت نفسه لا
     * يمنع حجز العميل. وهو **يمنعه** — لكن عبر `assignLawyer` الذي يستعمل
     * `isBusy()` العريض (أربعة جداول)، فلا يجد محامياً متاحاً.
     *
     * ويُقاس على `assignLawyer` مباشرةً لا عبر الطلب: الرفض يقع داخل
     * `DB::transaction`، وتراجُعُه المتداخل مع معاملة `RefreshDatabase` يُفسد
     * الاختبار بخطأٍ لا علاقة له بما نقيس.
     *
     * وضِيقُ `guardNoConflict` (يقرأ `appointments` وحده) مُثبَتٌ بقراءة الشيفرة،
     * وحارسُه السلوكيّ يأتي في الدفعة ١ حين يتوحّد المصدران.
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
    // **قُلِبا في الدفعة ٣**: انتقلا إلى `BookingValidationContractTest` مصفوفةً
    // (سطحٌ × حمولة) تُثبت أن كل سطحٍ يرفض المدخل غير المفهوم — فلا يبقى صفٌّ
    // بـ`starts_at = null` تُسكِته التذكيرات.

    // ══ Zoom يتبع الموعد ══
    //
    // ثلاثة حراسٍ كانت هنا تُثبّت أعطال Zoom الثلاثة (`@wrong`)، وقد **قُلِبت في
    // الدفعة ٢**: انتقلت إلى `BookingZoomSyncTest` حارساتٍ موجبة تُثبت أن كل مسارٍ
    // يُحرّك موعداً يُخبر Zoom بوقته ومدّته، وأن الإلغاء يحذف اجتماعه.

    // ══ حراسات الدفعة ١ ══

    /**
     * **الحارس الأثمن في الدفعة:** تراجُعُ المعاملة لا يُرسل بريد تأكيد.
     *
     * كان `ConsultBooking` يُرسل البريد ويُزامن التقويم **داخل** `DB::transaction`
     * التي يفتحها `ConsultController::schedule`. فتراجُعُها لأيّ سبب — تعارضٌ، أو
     * فشل تحديث التذكرة، أو انتهاء مهلة قفل — يعني أن العميل والمحامي تلقّيا
     * تأكيد موعدٍ **لا وجود له** في قاعدة البيانات.
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
                ConsultBooking::schedule($consult->fresh(), [
                    'lawyer_id' => $lawyer->id,
                    'starts_at' => $day.' 10:00:00',
                    'duration' => 60,
                    'day' => $day,
                    'time' => '10:00',
                ]);

                throw new \RuntimeException('تعثّرٌ بعد الحجز');
            });
        } catch (\RuntimeException) {
            // متوقَّع
        }

        $this->assertNull($consult->fresh()->starts_at, 'الحجز تراجع');
        Mail::assertNothingSent();
        $this->assertSame(0, Appointment::count());
    }

    /**
     * والبريد **مؤجَّل** لا ملغى — يُسجَّل ولا يُرسَل ما دامت المعاملة مفتوحة.
     *
     * **قيدٌ مُعلَن:** لا يمكن إثبات وصوله بعد الالتزام في هذه الحزمة.
     * `RefreshDatabase` تفتح معاملةً جذراً لا تُلتزم أبداً، و`DatabaseTransactionsManager`
     * ينفّذ نداءات `afterCommit` عند التزام **الجذر** وحده. فسكوتُها هنا أثرُ الحزمة
     * لا سلوكُ الإنتاج.
     *
     * وقد تحقّقتُ من الإنتاج بتشغيلٍ مباشر على قاعدة التطوير: `afterCommit` تُنفَّذ
     * فوراً بلا معاملة، وعند الالتزام (ولو متداخلاً)، **ولا تُنفَّذ عند التراجع**.
     * وهو بالضبط ما يعتمد عليه هذا التغيير.
     */
    public function test_the_confirmation_mail_is_deferred_not_sent_inline(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c2@example.test']);
        $this->lawyer();
        $consult = $this->payableConsult($client);

        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'date' => now()->addDays(2)->toDateString(), 'time' => '10:00',
        ])->assertRedirect();

        // الحجز وقع فعلاً...
        $this->assertNotNull($consult->fresh()->starts_at);
        $this->assertSame(1, Appointment::count());

        // ...والبريد لم يُرسَل داخل المعاملة. هذا **هو** التأجيل المطلوب.
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

        // يُتجاوَز الإسناد بتمرير المحامي صراحةً، فيصل الحارس النهائيّ وحده
        $this->expectException(ValidationException::class);

        ConsultBooking::schedule($consult->fresh(), [
            'lawyer_id' => $lawyer->id,
            'starts_at' => $day.' 11:00:00',
            'duration' => 60,
            'day' => $day,
            'time' => '11:00',
        ]);
    }
}
