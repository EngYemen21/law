<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use App\Support\SessionLinkSms;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **زرّ الدخول ورسالة الرابط قبل الموعد بربع ساعة** (قرار المالك 2026-10-01، الخيار «ب»):
 * يُفتح الدخول قبل 15 دقيقة، وفيها تصل العميلَ رسالةٌ نصّيّة واحدة فيها تاريخ الجلسة ووقتها ورابط غرفتها —
 * للاجتماع والاستشارة المرئيّة. ورابطها رابطُ غرفة المنصّة لا رابط Zoom.
 */
class SessionLinkReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake([SendSmsJob::class]);
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
    }

    private function client(string $phone = '+966555550101'): User
    {
        return User::factory()->create(['role' => Role::Client, 'phone' => $phone]);
    }

    private function meeting(User $client, int $minutesAway): Meeting
    {
        return Meeting::create([
            'ref' => 'M-LINK-'.$minutesAway, 'title' => 'اجتماع', 'type' => 'اجتماع مع عميل', 'client_name' => $client->name,
            'user_id' => $client->id, 'when_label' => 'اليوم', 'starts_at' => now()->addMinutes($minutesAway),
            'status' => MeetingStatus::Upcoming->value,
        ]);
    }

    private function consult(User $client, int $minutesAway, string $channel = Consult::CHANNEL_VIDEO, bool $paid = true): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-LINK-'.$minutesAway.'-'.$client->id, 'subject' => 'نزاع', 'channel' => $channel,
            'lawyer' => 'محامٍ', 'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'when_label' => 'اليوم',
            'starts_at' => now()->addMinutes($minutesAway), 'paid_at' => $paid ? now()->subDay() : null,
        ]);
    }

    /** خمس دقائق افتراضاً (قرار المالك 2026-10-02؛ كانت ربع ساعة). */
    public function test_join_opens_five_minutes_before_by_default(): void
    {
        $this->assertSame(5, SessionWindow::JOIN_OPENS_BEFORE_MINUTES);
        $this->assertSame(5, SettingsRegistry::int('session_join_opens_minutes'));
        $this->assertSame([], SettingsRegistry::relationErrors([]), 'الافتراضات متّسقة: بدء الطاقم ≥ فتح الدخول < التذكير القريب');

        $this->assertFalse(SessionWindow::joinOpened(now()->addMinutes(6)), 'قبل 6 دقائق: الزرّ مغلق');
        $this->assertTrue(SessionWindow::joinOpened(now()->addMinutes(5)), 'قبل 5 دقائق: الزرّ مفتوح');

        $client = $this->client();
        $this->assertFalse($this->meeting($client, 6)->canJoin());
        $this->assertTrue($this->meeting($client, 4)->canJoin());
    }

    public function test_meeting_sms_carries_date_time_and_platform_room_link_once(): void
    {
        $client = $this->client();
        $m = $this->meeting($client, 4);
        $later = $this->meeting($client, 20);

        $this->artisan('zoom:release-links')->assertSuccessful();
        $this->artisan('zoom:release-links')->assertSuccessful();

        Bus::assertDispatchedTimes(SendSmsJob::class, 1);
        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550101'
            && str_contains($job->body, $m->ref)
            && str_contains($job->body, SessionLinkSms::when($m->starts_at))
            && str_contains($job->body, $m->joinLink($client))
            && ! str_contains($job->body, 'zoom.us'));
        $this->assertNull($later->fresh()->link_released_at, 'قبل فتح الدخول لا إطلاق ولا رسالة');
    }

    public function test_video_consult_sms_carries_date_time_and_room_link(): void
    {
        $client = $this->client('+966555550102');
        $c = $this->consult($client, 4);

        $this->artisan('zoom:release-links')->assertSuccessful();

        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550102'
            && str_contains($job->body, $c->ref)
            && str_contains($job->body, SessionLinkSms::when($c->starts_at))
            && str_contains($job->body, url('/consults/room?ref='.$c->ref)));
        $this->assertNotNull($c->fresh()->link_released_at);
    }

    public function test_no_link_sms_for_in_person_or_unpaid_consult(): void
    {
        $this->consult($this->client('+966555550103'), 10, channel: 'حضورية');
        $this->consult($this->client('+966555550104'), 10, paid: false);

        $this->artisan('zoom:release-links')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
    }

    public function test_no_sms_without_a_sendable_phone_or_provider(): void
    {
        $this->meeting($this->client('123'), 10);
        $this->artisan('zoom:release-links')->assertSuccessful();
        Bus::assertNotDispatched(SendSmsJob::class);

        config(['services.taqnyat.api_key' => null]);
        $this->meeting($this->client('+966555550105'), 12);
        $this->artisan('zoom:release-links')->assertSuccessful();
        Bus::assertNotDispatched(SendSmsJob::class);
    }

    /** الإعداد يحكم: لو ضبطته الإدارة 10 دقائق فالرسالة والزرّ عند 10. */
    public function test_window_follows_the_setting(): void
    {
        Setting::put('session_join_opens_minutes', 10);
        $client = $this->client();
        $m = $this->meeting($client, 12);

        $this->artisan('zoom:release-links')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertNull($m->fresh()->link_released_at);
        $this->assertFalse($m->fresh()->canJoin());
    }

    /** المهاجرة: من بقي على 5 القديمة يُنقل إلى 15، ومن ضبط غيرها يبقى. */
    public function test_migration_moves_saved_five_to_fifteen_only(): void
    {
        $migration = require database_path('migrations/2026_10_01_310000_join_opens_quarter_hour.php');

        Setting::put('session_join_opens_minutes', 5);
        $migration->up();
        $this->assertSame('15', Setting::get('session_join_opens_minutes'));

        Setting::put('session_join_opens_minutes', 8);
        $migration->up();
        $this->assertSame('8', Setting::get('session_join_opens_minutes'));

        // ولا تكسر علاقةً محفوظة: بدء الطاقم 10 ⇒ فتح الدخول لا يتجاوزه
        Setting::put('session_join_opens_minutes', 5);
        Setting::put('consult_staff_start_minutes', 10);
        $migration->up();
        $this->assertSame('10', Setting::get('session_join_opens_minutes'));
    }
}
