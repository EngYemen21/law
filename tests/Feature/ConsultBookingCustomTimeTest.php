<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حجزُ موعد الاستشارة بدقّة الدقيقة — وشاشةٌ لا تَعِد بما لا يقع.
 *
 * كان منتقي وقت العميل صفَّ أزرارٍ على شبكةٍ نصف ساعيّة، بينما الخادم يقبل أيّ `H:i`
 * صالحٍ غير ماضٍ. فمن أراد ١١:٠٧ لم يجد سبيلاً، ومَن اختار وقتاً بلا محامٍ متفرّغ
 * كان زرُّ التأكيد يُقفَل عليه رغم أنّ `lawyer_id` **لا يُرسَل أصلاً** والإسنادُ خادميّ.
 *
 * والقاعدة المحكِمة (قرار 2026-09-05): الحجزُ المتعذّر **يُرفع للإدارة لا يُرفض** —
 * فالعميل قد سدّد، ورفضُه بعد السداد ظلمٌ وصمتٌ عن الإدارة معاً.
 */
class ConsultBookingCustomTimeTest extends TestCase
{
    use RefreshDatabase;

    /** استشارةٌ مسدَّدةٌ واقفةٌ عند «بانتظار تحديد الموعد». */
    private function paidConsult(User $client): Consult
    {
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-CT-'.random_int(100, 999),
            'type' => 'استشارة', 'subject' => 'نزاع تجاري', 'status' => 'جديدة',
        ]);

        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $pricing = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($pricing)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    // ————— ١ · الدقيقة المخصّصة تُثبَّت كما اختارها العميل —————

    public function test_a_custom_minute_is_booked_exactly_and_assigned_when_someone_is_free(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->paidConsult($client);

        $day = LawyerAvailability::resolveDate(null)->toDateString();

        // 11:07 ليست من شبكة الفترات — والخادم لا يقيّد الوقت بها
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'date' => $day, 'time' => '11:07',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame('11:07', $consult->time, 'الموعد يُثبَّت بالدقيقة التي اختارها العميل');
        $this->assertSame($day, $consult->day);
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id, 'ومحامٍ متفرّغ يُسنَد تلقائيّاً');
    }

    public function test_a_custom_minute_with_nobody_free_is_still_booked_and_escalated(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->paidConsult($client);

        // لا محاميَ في المكتب إطلاقاً ⇒ يتعذّر الإسناد
        $day = LawyerAvailability::resolveDate(null)->toDateString();
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'date' => $day, 'time' => '11:07',
        ])->assertRedirect();

        $consult->refresh();
        // **يُحجز ولا يُرفض**: العميل سدّد
        $this->assertSame('11:07', $consult->time);
        $this->assertSame($admin->id, $consult->assigned_lawyer_id, 'ويُرفع الملفّ للإدارة لتوزّعه');
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $consult->lawyer);

        // **والعميل لا يُوعَد بما لم يقع**: موعدُه مؤكَّد، ومستشارُه لم يُسنَد بعد
        $notice = (string) DB::table('user_notifications')
            ->where('user_id', $client->id)->orderByDesc('id')->value('body');
        $this->assertStringContainsString('يُسنَد مستشارك قبل الجلسة', $notice);
    }

    public function test_staff_can_schedule_a_custom_minute_too(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-CT-STAFF',
            'type' => 'استشارة', 'subject' => 'نزاع تجاري', 'status' => 'بانتظار حجز الاستشارة',
        ]);

        $this->actingAs($employee)->postJson('/employee/schedule', [
            'client_id' => $client->id,
            'type' => 'video',
            'subject' => 'نزاع تجاري',
            'date' => now()->addDays(2)->format('Y-m-d'),
            'time' => '11:07',
            'ticket_no' => $ticket->number,
        ])->assertSuccessful();

        $this->assertDatabaseHas('appointments', ['time' => '11:07']);
    }

    // ————— ٢ · الشاشة تعرض المنتقي المشترك ولا تقفل التأكيد —————

    public function test_the_client_picker_uses_the_shared_component_with_custom_minutes(): void
    {
        $picker = file_get_contents(resource_path('js/components/SpecialistPicker.tsx'));

        // المنتقي المشترك نفسه المستعمل في دعوات الاجتماعات — لا صفّ أزرارٍ خام
        $this->assertStringContainsString('<TimeSlotPicker', $picker);
        $this->assertStringNotContainsString("title={past ? 'انقضى الوقت' : free ? 'متاح' : 'محجوز'}", $picker);
        // «محجوز» من التوفّر الحقيقيّ لا من العلم الخام
        $this->assertStringContainsString('taken: freeAt(s.time).length === 0', $picker);
        // ونصٌّ صادق حين يخرج الوقت عن الشبكة
        $this->assertStringContainsString('يُسنَد مستشارُك قبل الجلسة ويصلك إشعار', $picker);
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
}
