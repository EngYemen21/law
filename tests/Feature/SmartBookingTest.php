<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجز الاستشارة الذكي: ترشيح المحامين بالتخصّص + الترتيب بسجلّ النجاح (احتياط حتميّ بلا AI)
 * + الفترات المتاحة/المحجوزة + منع الحجز المزدوج داخل معاملة + بدائل عند الانشغال.
 */
class SmartBookingTest extends TestCase
{
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
        $res = $this->actingAs($client)->getJson(route('book.availability', ['specialty' => 'القضايا التجارية', 'date' => $date]));

        $res->assertOk();
        $ids = collect($res->json('lawyers'))->pluck('id');
        // فقط المتخصّصان التجاريان (لا العقاري)
        $this->assertEqualsCanonicalizing([$strong->id, $weak->id], $ids->all());
        // الأعلى سجلّ نجاح أولاً
        $this->assertSame($strong->id, $ids->first());
    }

    /** يقود الحجز المباشر عبر الدورة الكاملة (طلب → تسعير → دفع → اختيار موعد)؛ يُرجع استجابة الجدولة. */
    private function directBookAndSchedule(User $client, User $lawyer, string $date, string $time)
    {
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'office', 'specialty' => 'القضايا التجارية',
        ])->assertRedirect(route('myconsults'));
        $consult = Consult::where('user_id', $client->id)->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 500])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => $date, 'time' => $time,
        ]);
    }

    public function test_taken_slot_is_flagged_and_double_booking_is_rejected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer('القضايا التجارية');
        $date = LawyerAvailability::resolveDate(null)->toDateString();

        // حجز أول ناجح (عبر الدورة الكاملة)
        $this->directBookAndSchedule($client, $lawyer, $date, '10:00')->assertRedirect();
        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());

        // الفترة تظهر محجوزة في التفرّغ
        $res = $this->actingAs($other)->getJson(route('book.availability', ['specialty' => 'القضايا التجارية', 'date' => $date]));
        $slots = collect($res->json('lawyers'))->firstWhere('id', $lawyer->id)['slots'];
        $ten = collect($slots)->firstWhere('time', '10:00');
        $this->assertTrue($ten['taken']);

        // محاولة حجز عميل آخر لنفس الفترة تُرفض: المختصّ الوحيد مشغول، فلا يوجد بديل متاح
        $this->directBookAndSchedule($other, $lawyer, $date, '10:00')->assertSessionHasErrors('time');
        $this->assertSame(1, Appointment::where('lawyer_id', $lawyer->id)->count());
    }

    public function test_schedule_auto_assigns_top_specialist_ignoring_client(): void
    {
        // العميل لا يختار المحامي؛ يُسنَد أعلى مختصّ (تخصّص + سجلّ إنجاز) متاح، ويُتجاهَل أيّ lawyer_id وارد
        $client = User::factory()->create(['role' => Role::Client]);
        $strong = $this->lawyer('القضايا التجارية');
        $weak = $this->lawyer('القضايا التجارية');
        $this->closeTickets($strong, $client, 3); // الأقوى سجلّ إنجاز

        $date = LawyerAvailability::resolveDate(null)->toDateString();
        // نحقن الأضعف عمداً — يجب أن يُتجاهَل ويُسنَد الأقوى
        $this->directBookAndSchedule($client, $weak, $date, '09:00')->assertRedirect();

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

        $res = $this->actingAs($client)->getJson(route('book.availability', ['specialty' => 'القضايا التجارية', 'date' => $date]));
        $lawyers = collect($res->json('lawyers'));

        $this->assertSame(0, $lawyers->firstWhere('id', $busy->id)['freeCount']);
        // البديل بنفس التخصّص متاح
        $alt = $lawyers->firstWhere('id', $free->id);
        $this->assertGreaterThan(0, $alt['freeCount']);
    }
}
