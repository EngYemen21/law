<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **إعادة الجدولة تُسقط الموعد الملغى من كلّ تقويم** — لا يبقى تاريخٌ قديم يُعاد بناؤه.
 *
 * 🔴 كان `RescheduleConsult` يمسح `starts_at` ويُبقي `day`/`time`، والتقاويم الثلاثة وملفّ الاشتراك
 * (ICS) ومزامنة Google تعيد بناء التاريخ منهما حين يغيب `starts_at`. فظهرت الاستشارة CN-2026-0336
 * على موعدها الملغى (14 سبتمبر · 16:00) في تقويم الموظّف والإدارة والمحامي وتقويم العميل المشترَك.
 *
 * ويبقى ارتباط الموعد الملغى (`appointment_id`) لأنّ لوحة المواعيد وسجلّ العميل يقرآن تفاصيله منه.
 */
class RescheduleClearsStaleScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_DAY = '+2 days';

    /** استشارة مؤكَّدة على موعدٍ قادم مع موعدها المرافق. */
    private function scheduledConsult(User $client, User $lawyer, string $channel = 'مرئية'): Consult
    {
        $startsAt = now()->modify(self::OLD_DAY)->setTime(16, 0);

        $appointment = Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-26-'.random_int(1000, 9999), 'type' => 'استشارة '.$channel,
            'ico' => $channel === 'مرئية' ? 'video' : 'office', 'lawyer' => $lawyer->name, 'lawyer_id' => $lawyer->id,
            'day' => $startsAt->format('Y-m-d'), 'time' => '16:00', 'starts_at' => $startsAt, 'duration_min' => 60,
            'place' => $channel === 'مرئية' ? 'اجتماع إلكتروني' : 'مقرّ المكتب — الدور الثاني',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-'.random_int(1000, 9999), 'subject' => 'نزاع إيجار',
            'channel' => $channel, 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'day' => $startsAt->format('Y-m-d'), 'time' => '16:00', 'when_label' => $startsAt->format('Y-m-d').' · 16:00',
            'session' => 'بانتظار الجلسة', 'status' => 'موعد مؤكد',
            'starts_at' => $startsAt, 'duration_min' => 60, 'appointment_id' => $appointment->id,
        ]);
    }

    private function reschedule(Consult $consult): Consult
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.reschedule', $consult), ['reason' => 'client_request'])->assertRedirect();

        return $consult->fresh();
    }

    /** @return list<array<string, mixed>> أحداث استشارةٍ بعينها في تقويم الصفحة */
    private function consultEvents(array $events, Consult $consult): array
    {
        return array_values(array_filter(
            $events,
            fn (array $e) => ($e['kindKey'] ?? null) === 'consult' && str_contains((string) ($e['title'] ?? ''), $consult->subject)
        ));
    }

    public function test_reschedule_clears_the_stale_date_but_keeps_the_history_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->scheduledConsult($client, $lawyer);
        $appointmentId = $consult->appointment_id;

        $consult = $this->reschedule($consult);

        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNull($consult->starts_at);
        $this->assertNull($consult->day, 'بقي تاريخ الموعد الملغى على الاستشارة');
        $this->assertNull($consult->time, 'بقي وقت الموعد الملغى على الاستشارة');

        // السجلّ لا يُمسّ: الموعد الملغى ما زال مرتبطاً باستشارته ويُقرأ منها
        $this->assertSame($appointmentId, $consult->appointment_id);
        $appointment = Appointment::find($appointmentId);
        $this->assertSame('ملغي', $appointment->status);
        $this->assertSame($consult->id, $appointment->consult?->id);
    }

    public function test_the_office_calendar_no_longer_shows_the_cancelled_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->reschedule($this->scheduledConsult($client, $lawyer));
        $oldDate = now()->modify(self::OLD_DAY)->format('Y-m-d');

        $admin = User::factory()->create(['role' => Role::Admin]);
        $events = $this->actingAs($admin)->get(route('admin.calendar'))->assertOk()->viewData('page')['props']['events'];

        foreach ($this->consultEvents($events, $consult) as $event) {
            $this->assertFalse(
                str_starts_with((string) ($event['startsAt'] ?? ''), $oldDate),
                'تقويم المكتب ما زال يضع الاستشارة على موعدها الملغى'
            );
        }
    }

    public function test_the_lawyer_calendar_no_longer_shows_the_cancelled_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->reschedule($this->scheduledConsult($client, $lawyer));
        $oldDate = now()->modify(self::OLD_DAY)->format('Y-m-d');

        $events = $this->actingAs($lawyer)->get(route('lawyer.calendar'))->assertOk()->viewData('page')['props']['events'];

        foreach ($this->consultEvents($events, $consult) as $event) {
            $this->assertFalse(
                str_starts_with((string) ($event['startsAt'] ?? ''), $oldDate),
                'تقويم المحامي ما زال يضع الاستشارة على موعدها الملغى'
            );
        }
    }

    public function test_subscribed_calendar_feeds_drop_the_cancelled_consult(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->reschedule($this->scheduledConsult($client, $lawyer));
        $admin = User::factory()->create(['role' => Role::Admin]);

        foreach ([$admin, $client, $lawyer] as $viewer) {
            $body = $this->get(route('calendar.feed', ['user' => $viewer->id, 'token' => $viewer->calendarToken()]))
                ->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'CONSULT-'.$consult->id,
                $body,
                "ملفّ التقويم المشترَك ({$viewer->role->value}) ما زال يحمل الموعد الملغى"
            );
        }
    }

    public function test_the_consult_card_no_longer_shows_the_cancelled_place(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->scheduledConsult($client, $lawyer, 'حضورية');

        $this->assertNotSame('', $consult->placeForCard(), 'الموعد المؤكَّد يعرض مكانه');

        $consult = $this->reschedule($consult);

        $this->assertSame('', $consult->placeForCard(), 'البطاقة ما زالت تعرض مكان الموعد الملغى');
        $this->assertSame('', $consult->placeForClient());
    }

    /** البيانات القائمة قبل الإصلاح تُصحَّح بلا مساسٍ بالمقترحات والمواعيد المؤكَّدة. */
    public function test_the_data_fix_clears_only_stale_rescheduled_rows(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // صفٌّ بائت كما تركه الإصلاح القديم: لا starts_at، تاريخ ووقت باقيان، موعدٌ ملغى
        $stale = $this->scheduledConsult($client, $lawyer);
        Appointment::whereKey($stale->appointment_id)->update(['status' => 'ملغي']);
        $stale->forceFill(['starts_at' => null, 'status' => 'بانتظار تحديد الموعد', 'when_label' => 'بانتظار اختيار موعد جديد'])->saveQuietly();

        // مؤكَّدة قائمة — لا تُمسّ
        $confirmed = $this->scheduledConsult($client, $lawyer);

        (include database_path('migrations/2026_09_18_000001_clear_stale_schedule_on_rescheduled_consults.php'))->up();

        $this->assertNull($stale->fresh()->day);
        $this->assertNull($stale->fresh()->time);
        $this->assertSame($stale->appointment_id, $stale->fresh()->appointment_id, 'رابط السجلّ يبقى');

        $this->assertNotNull($confirmed->fresh()->day);
        $this->assertSame('16:00', $confirmed->fresh()->time);
    }
}
