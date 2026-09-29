<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * حجز الاستشارة الذكي: ترشيح المحامين بالتخصّص + الترتيب بسجلّ النجاح (احتياط حتميّ بلا AI)
 * + الفترات المتاحة/المحجوزة + منع الحجز المزدوج داخل معاملة + بدائل عند الانشغال.
 */
class SmartBookingTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function lawyer(string $dept): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => $dept]);
    }

    private function closeTickets(User $lawyer, User $client, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Ticket::create([
                'user_id' => $client->id,
                'number' => 'SB-'.$lawyer->id.'-'.$i,
                'type' => 'نزاع تجاري',
                'assigned_lawyer_id' => $lawyer->id,
                'status' => 'مغلقة',
            ]);
        }
    }

    private function bookSlot(User $lawyer, User $client, string $date, string $time): void
    {
        Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'AP-'.$lawyer->id.'-'.str_replace(':', '', $time),
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => $lawyer->name,
            'lawyer_id' => $lawyer->id,
            'day' => $date,
            'time' => $time,
            'starts_at' => $date.' '.$time.':00',
            'duration_min' => 60,
            'place' => 'الرياض',
            'status' => 'مؤكد',
        ]);
    }

    public function test_specialists_are_filtered_by_specialty_and_ranked_by_success(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $strong = $this->lawyer('القضايا التجارية');
        $weak = $this->lawyer('القضايا التجارية');
        $other = $this->lawyer('العقارات');
        $this->closeTickets($strong, $client, 3);

        $date = LawyerAvailability::resolveDate(null)->toDateString();
        // المصدر الداخليّ للطاقم والإسناد — العميل لا يرى التفرّغ (المكتب يحدّد الموعد)
        $ids = collect(LawyerAvailability::rankedSpecialists('القضايا التجارية', null, $date))->pluck('id');
        // فقط المتخصّصان التجاريان (لا العقاري)
        $this->assertEqualsCanonicalizing([$strong->id, $weak->id], $ids->all());
        // الأعلى سجلّ نجاح أولاً
        $this->assertSame($strong->id, $ids->first());
    }

    /** الحجز المباشر عبر الدورة الكاملة (طلب → تسعير → سداد → الإدارة تحدّد الموعد). */
    private function directBookAndSchedule(User $client, ?User $lawyer, string $date, string $time): TestResponse
    {
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'office', 'specialty' => 'القضايا التجارية', 'subject' => 'نزاع تجاري',
        ])->assertRedirect(route('myconsults'));
        $consult = $this->priceAndPay(Consult::where('user_id', $client->id)->latest('id')->firstOrFail(), 500);

        return $this->adminPublishes($consult, array_filter([
            'lawyer_id' => $lawyer?->id, 'date' => $date, 'time' => $time,
        ]));
    }

    /**
     * **الفترة المحجوزة تُوسَم، والمحامي لا يُحجز مرّتين.**
     *
     * الحجز بيد الطاقم (2026-09-14): الحجز الثاني على الخانة نفسها يُردّ ليُختار وقتٌ آخر،
     * وتبقى الاستشارة الثانية مدفوعةً بانتظار موعدها.
     */
    public function test_taken_slot_is_flagged_and_the_lawyer_is_never_double_booked(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer('القضايا التجارية');
        $date = now()->addDays(2)->toDateString();

        // حجز أول ناجح (عبر الدورة الكاملة)
        $this->directBookAndSchedule($client, $lawyer, $date, '10:00')->assertOk();
        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());

        // الفترة تظهر محجوزة في التفرّغ الذي يقرؤه الطاقم عند تحديد الموعد (المحامي الوحيد هنا مشغول فيها)
        $slots = collect(LawyerAvailability::rankedSpecialists('القضايا التجارية', null, $date))->firstWhere('id', $lawyer->id)['slots'];
        $ten = collect($slots)->firstWhere('time', '10:00');
        $this->assertTrue($ten['taken']);

        // حجزٌ ثانٍ على الفترة نفسها للمحامي نفسه ⇒ يُردّ
        $this->directBookAndSchedule($other, $lawyer, $date, '10:00')->assertStatus(422);

        // **الثابت:** موعدٌ واحدٌ للمحامي مهما تعدّد الطالبون
        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());

        $second = Consult::where('user_id', $other->id)->latest('id')->firstOrFail();
        $this->assertNotSame($lawyer->id, $second->assigned_lawyer_id, 'ولا يُسنَد المشغول');
        $this->assertSame('بانتظار تحديد الموعد', $second->status, 'والثانية تنتظر وقتاً آخر');
    }

    public function test_schedule_auto_assigns_top_specialist_when_staff_leave_the_lawyer_open(): void
    {
        // الطاقم لم يختر محامياً ⇒ يُسنَد أعلى مختصّ (تخصّص + سجلّ إنجاز) متاح
        $client = User::factory()->create(['role' => Role::Client]);
        $strong = $this->lawyer('القضايا التجارية');
        $this->lawyer('القضايا التجارية');
        $this->closeTickets($strong, $client, 3); // الأقوى سجلّ إنجاز

        $this->directBookAndSchedule($client, null, now()->addDays(2)->toDateString(), '09:00')->assertOk();

        $appt = Appointment::latest('id')->firstOrFail();
        $this->assertSame($strong->id, $appt->lawyer_id);
    }

    public function test_busy_lawyer_surfaces_available_alternatives(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $busy = $this->lawyer('القضايا التجارية');
        $free = $this->lawyer('القضايا التجارية');
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        // اشغل كامل يوم المحامي الأول (00:00…23:00 — متاح 24 ساعة)
        for ($h = 0; $h < 24; $h++) {
            $this->bookSlot($busy, $client, $date, sprintf('%02d:00', $h));
        }

        $lawyers = collect(LawyerAvailability::rankedSpecialists('القضايا التجارية', null, $date));

        $this->assertSame(0, $lawyers->firstWhere('id', $busy->id)['freeCount']);
        // البديل بنفس التخصّص متاح
        $alt = $lawyers->firstWhere('id', $free->id);
        $this->assertGreaterThan(0, $alt['freeCount']);

        // والعميل لا يرى التفرّغ أصلاً — المكتب يحدّد الموعد (قرار المالك 2026-09-14)
        $this->actingAs($client)->getJson('/book/availability?specialty=x')->assertNotFound();
    }
}
