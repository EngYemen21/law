<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Mail\ConsultBooked;
use App\Mail\MeetingLinkReady;
use App\Models\Consult;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * دورة الاستشارة المرئية مع Zoom — المرحلة 1 (جدولة بالوقت + بريد + زر معطّل)
 * والمرحلة 2 (إطلاق الرابط قبل 5د + تفعيل الدخول + إشعار).
 */
class ZoomLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_schedules_zoom_with_start_time_and_queues_confirmation(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/*' => Http::response(['id' => 987654321, 'join_url' => 'https://z/j', 'start_url' => 'https://z/s', 'password' => 'pw']),
        ]);
        Mail::fake();

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $starts = LawyerAvailability::resolveDate(null)->setTime(11, 0);

        $consult = ConsultBooking::create($client, [
            'type' => 'video', 'lawyer_id' => $lawyer->id,
            'starts_at' => $starts->toDateTimeString(), 'duration' => 60,
            'day' => $starts->toDateString(), 'time' => '11:00',
        ]);

        $this->assertSame('987654321', $consult->meet_id);
        $this->assertSame('pw', $consult->meet_password);
        $this->assertFalse($consult->canJoin()); // الزر معطّل قبل إطلاق الرابط

        // نداء إنشاء الاجتماع حمل start_time الصحيح (اجتماع مجدول على Zoom)
        Http::assertSent(fn ($req) => str_contains($req->url(), 'meetings')
            && ($req['start_time'] ?? null) === $starts->format('Y-m-d\TH:i:s'));
        Mail::assertQueued(ConsultBooked::class);
    }

    public function test_release_command_activates_join_and_notifies(): void
    {
        Mail::fake();
        Event::fake([ConsultStatusBroadcast::class]);

        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-7001', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'اليوم', 'time' => '11:00', 'when_label' => 'اليوم',
            'session' => 'بانتظار الجلسة', 'starts_at' => now()->addMinutes(3),
        ]);
        $this->assertFalse($consult->canJoin());

        $this->artisan('zoom:release-links')->assertExitCode(0);

        $consult->refresh();
        $this->assertNotNull($consult->link_released_at);
        $this->assertTrue($consult->canJoin()); // الزر مفعّل بعد الإطلاق
        Mail::assertQueued(MeetingLinkReady::class);
        Event::assertDispatched(ConsultStatusBroadcast::class);
    }

    public function test_can_join_once_session_is_live_even_without_link_release(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-7003', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'اليوم', 'time' => '11:00', 'when_label' => 'اليوم',
            'session' => 'جلسة جارية', 'link_released_at' => null, 'meet_id' => '999',
        ]);

        // الرابط لم يُطلَق بعد لكن المحامي بدأ الجلسة ⇒ يُفعَّل زر دخول العميل
        $this->assertTrue($consult->canJoin());

        $consult->update(['session' => 'منتهية']);
        $this->assertFalse($consult->fresh()->canJoin());
    }

    public function test_release_skips_meetings_not_yet_due(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-7002', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'غداً', 'time' => '11:00', 'when_label' => 'غداً',
            'session' => 'بانتظار الجلسة', 'starts_at' => now()->addHours(3), // أبعد من 5 دقائق
        ]);

        $this->artisan('zoom:release-links')->assertExitCode(0);

        $this->assertNull($consult->fresh()->link_released_at); // لم يُطلق بعد
        Mail::assertNothingQueued();
    }
}
