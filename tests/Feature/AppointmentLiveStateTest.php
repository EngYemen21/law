<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تبويب المواعيد: التصنيف قادمة/سابقة يُشتق من الزمن الحقيقي لا من when_kind المخزّنة الثابتة،
 * وحالة الموعد المنقضي تعكس الحضور الفعلي من جلسة الاستشارة المرتبطة:
 * «منتهية» ⇒ تم الحضور، بلا جلسة ⇒ لم يحضر، «جلسة جارية» ⇒ قيد الجلسة (تبقى قادمة).
 */
class AppointmentLiveStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(User $client, array $extra = []): Appointment
    {
        return Appointment::create(array_merge([
            'user_id' => $client->id,
            'ext_id' => 'APT-'.uniqid(),
            'type' => 'استشارة مرئية',
            'ico' => 'video',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => '2026-08-20',
            'time' => '10:00 ص',
            'duration_min' => 60,
            'place' => 'اجتماع إلكتروني',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up', // مخزّنة «قادمة» دائماً — الاشتقاق الزمني هو الفيصل
        ], $extra));
    }

    private function linkConsult(Appointment $a, string $session): Consult
    {
        return Consult::create([
            'user_id' => $a->user_id,
            'appointment_id' => $a->id,
            'ref' => 'CN-'.uniqid(),
            'subject' => 'نزاع تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة',
            'day' => 'الأحد',
            'time' => '10 ص',
            'when_label' => 'الأحد 10 ص',
            'price' => 450, 'vat' => 68, 'total' => 518,
            'status' => 'قيد الاستشارة',
            'session' => $session,
        ]);
    }

    public function test_future_appointment_is_upcoming_with_stored_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $a = $this->makeAppointment($client, ['starts_at' => now()->addDay()]);

        $card = $a->fresh()->load('consult')->toCard();

        $this->assertSame('up', $card['when']);
        $this->assertSame('مؤكد', $card['status']);
        $this->assertSame('b-green', $card['tone']);
    }

    public function test_past_appointment_without_session_shows_no_show(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // when_kind المخزّنة تقول «قادمة» لكن الوقت انقضى فعلاً — الاشتقاق يصحّحها
        $a = $this->makeAppointment($client, ['starts_at' => now()->subDay()]);
        $this->linkConsult($a, 'بانتظار الجلسة');

        $card = $a->fresh()->load('consult')->toCard();

        $this->assertSame('past', $card['when']);
        $this->assertSame('لم يحضر', $card['status']);
        $this->assertSame('b-red', $card['tone']);
    }

    public function test_past_appointment_with_finished_session_shows_attended(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $a = $this->makeAppointment($client, ['starts_at' => now()->subDay()]);
        $this->linkConsult($a, 'منتهية');

        $card = $a->fresh()->load('consult')->toCard();

        $this->assertSame('past', $card['when']);
        $this->assertSame('تم الحضور', $card['status']);
        $this->assertSame('b-green', $card['tone']);
    }

    public function test_running_session_shows_in_progress_and_stays_upcoming(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // بدأت الجلسة قبل نصف ساعة وما زالت جارية — لا تُرمى في «السابقة» أثناء انعقادها
        $a = $this->makeAppointment($client, ['starts_at' => now()->subMinutes(90)]);
        $this->linkConsult($a, 'جلسة جارية');

        $card = $a->fresh()->load('consult')->toCard();

        $this->assertSame('up', $card['when']);
        $this->assertSame('قيد الجلسة', $card['status']);
        $this->assertSame('b-blue', $card['tone']);
    }

    public function test_appointments_page_splits_upcoming_and_past(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $future = $this->makeAppointment($client, ['starts_at' => now()->addDays(2)]);
        $missed = $this->makeAppointment($client, ['starts_at' => now()->subDays(2)]);
        $this->linkConsult($missed, 'بانتظار الجلسة');

        $this->actingAs($client)->get(route('appointments'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('appointments')
                ->has('appointments', 2)
                ->where('appointments.0.when', fn ($v) => in_array($v, ['up', 'past'], true)));

        // التحقق الدقيق من التصنيف عبر البطاقات نفسها
        $this->assertSame('up', $future->fresh()->load('consult')->toCard()['when']);
        $this->assertSame('past', $missed->fresh()->load('consult')->toCard()['when']);
    }
}
