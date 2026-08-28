<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ترتيب «مواعيدي» ونافذتها في التبويب الزمني الموحّد.
 *
 * عيبان رُصدا بفحص حالات حدّية على القاعدة:
 *   • الترتيب كان `latest('id')` — أي بالإنشاء لا بالتاريخ. فالموعد **الأقرب انعقاداً**
 *     يظهر أخيراً، والموعد بلا تاريخ محدَّد يتصدّر القائمة.
 *   • نافذة العميل كانت مسقوفة بسنة للأمام، فيختفي **موعد حجزه العميل ولم يأتِ بعد**.
 *     إخفاء التزام قادم أسوأ بكثير من إخفاء أرشيف قديم.
 */
class ClientCalendarOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function appt(User $client, string $ext, $startsAt): Appointment
    {
        return Appointment::create([
            'user_id' => $client->id, 'lawyer_id' => null, 'ext_id' => $ext,
            'type' => 'استشارة حضورية', 'ico' => 'office', 'lawyer' => 'المحامي',
            'day' => $startsAt ? $startsAt->format('Y-m-d') : 'الاثنين',
            'time' => $startsAt ? $startsAt->format('H:i') : '10:00',
            'starts_at' => $startsAt, 'duration_min' => 60, 'place' => 'الرياض',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
    }

    /** الأقرب انعقاداً أوّلاً — لا الأحدث إنشاءً. */
    public function test_upcoming_appointments_are_ordered_by_date_not_by_creation(): void
    {
        $client = $this->client();
        // يُنشآن بترتيب معكوس عمداً: لو كان الترتيب بالإنشاء لظهر البعيد أوّلاً
        $this->appt($client, 'AP-LATER', now()->addDays(10));
        $this->appt($client, 'AP-SOONER', now()->addDays(2));

        $ids = $this->fetchIds($client);

        $this->assertSame(['AP-SOONER', 'AP-LATER'], $ids, 'الموعد الأقرب لم يتصدّر القائمة.');
    }

    /** والموعد بلا تاريخ محدَّد في الذيل لا في القمّة. */
    public function test_an_undated_appointment_sinks_to_the_end(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-UNDATED', null);
        $this->appt($client, 'AP-DATED', now()->addDays(2));

        $ids = $this->fetchIds($client);

        $this->assertSame(['AP-DATED', 'AP-UNDATED'], $ids, 'غير المجدول تصدّر القائمة.');
    }

    /** موعد قادم بعد أكثر من سنة يبقى ظاهراً — العميل حجزه ولم يأتِ بعد. */
    public function test_a_far_future_appointment_does_not_vanish(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-FAR', now()->addYears(2));

        $this->assertContains('AP-FAR', $this->fetchIds($client), 'موعد قادم اختفى من القائمة.');
    }

    /** بينما الماضي الأقدم من سنة يُقصّ (حماية الأداء — قرار مقصود). */
    public function test_ancient_history_is_still_trimmed(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-ANCIENT', now()->subYears(2));

        $this->assertNotContains('AP-ANCIENT', $this->fetchIds($client));
    }

    /** @return array<int, string> معرّفات المواعيد بالترتيب الذي تستلمه الواجهة */
    private function fetchIds(User $client): array
    {
        $ids = [];
        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(function ($p) use (&$ids) {
                $ids = collect($p->toArray()['props']['appointments'] ?? [])->pluck('id')->all();
            });

        return $ids;
    }
}
