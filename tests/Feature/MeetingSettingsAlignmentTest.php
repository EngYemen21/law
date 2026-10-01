<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **إعدادات الاجتماع تحكمه كما تقول نصوصها** (قرار المالك 2026-10-01) — ما ثبت في المتصفّح أنّ الإعداد يعلنه
 * للاجتماع ولا يطبّقه، أو يطبّقه للاستشارة وحدها:
 *
 * 1. مهلة طلب العميل تغيير الموعد · 2. نافذة بدء الطاقم · 3. لا اعتماد لدعوةٍ فات موعدها ·
 * 4. إطلاق رابط الاجتماع · 5. قسم «الاجتماعات» · 6. تذكير المشاركين · 7. تذكير العميل الثاني (30د).
 */
class MeetingSettingsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function meeting(array $o = []): Meeting
    {
        static $n = 0;
        $n++;

        return Meeting::create($o + [
            'ref' => 'M-SET-'.$n, 'title' => 'اجتماع '.$n, 'type' => 'اجتماع مع عميل', 'client_name' => 'عميل',
            'when_label' => 'اليوم', 'starts_at' => now()->addDays(2), 'status' => MeetingStatus::Upcoming->value,
        ]);
    }

    // ── ١ · مهلة طلب تغيير الموعد تسري على الاجتماع ──

    public function test_client_cannot_request_change_inside_notice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $soon = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(4)]);

        $card = $soon->toCard();
        $this->assertFalse($card['canRequestChange']);
        $this->assertStringContainsString('تواصل مع المكتب', (string) $card['changeRequestNote']);

        $this->actingAs($client)->post(route('meetings.change-request', $soon))->assertStatus(422);
        $this->assertNull($soon->fresh()->reschedule_requested_at);
    }

    public function test_client_can_request_change_beyond_notice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $far = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addDays(2)]);

        $this->assertTrue($far->toCard()['canRequestChange']);
        $this->actingAs($client)->post(route('meetings.change-request', $far))->assertRedirect();
        $this->assertNotNull($far->fresh()->reschedule_requested_at);
    }

    public function test_notice_zero_allows_request_any_time(): void
    {
        Setting::put('consult_reschedule_notice_minutes', 0);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->assertTrue($this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(4)])->toCard()['canRequestChange']);
    }

    // ── ٢ · لا يُبدأ الاجتماع قبل نافذة بدء الطاقم ──

    public function test_staff_cannot_start_meeting_days_early(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $m = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addDays(4)]);

        $this->assertFalse($m->lifecycleActions()['start'], 'زرّ البدء لا يُعرض قبل النافذة');
        $this->actingAs($admin)->post(route('admin.meetings.start', $m))->assertStatus(422);

        $this->assertSame(MeetingStatus::Upcoming->value, $m->fresh()->status);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'لا «يمكنك الدخول الآن» لاجتماعٍ بعد أيّام');
    }

    public function test_staff_can_start_inside_window(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $m = $this->meeting(['starts_at' => now()->addMinutes(10)]); // النافذة 15د

        $this->assertTrue($m->lifecycleActions()['start']);
        $this->actingAs($admin)->post(route('admin.meetings.start', $m))->assertRedirect();
        $this->assertSame(MeetingStatus::Live->value, $m->fresh()->status);
    }

    public function test_postponed_meeting_without_time_can_still_start(): void
    {
        $m = $this->meeting(['status' => MeetingStatus::Postponed->value, 'starts_at' => null, 'when_label' => 'يُحدَّد لاحقاً']);

        $this->assertTrue($m->lifecycleActions()['start']);
    }

    // ── ٣ · لا تُعتمد دعوةٌ فات موعدها ──

    public function test_admin_cannot_approve_invitation_after_its_time(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-LATE', 'service' => 'نزاع', 'type' => 'استشارة مرئية',
            'day' => now()->subMinutes(20)->format('Y-m-d'), 'time' => now()->subMinutes(20)->format('H:i'),
            'assigned_lawyer_id' => $lawyer->id, 'sent_by' => 'موظف', 'sent_by_id' => $admin->id, 'stage' => MeetRequest::STAGE_SENT,
        ]);

        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertSessionHasErrors('approve');

        $this->assertSame(MeetRequest::STAGE_EXPIRED, $req->fresh()->stage, 'تصير منتهيةً فيُتاح إعادة إرسالها');
        $this->assertSame(0, Meeting::count(), 'لا اجتماع بموعدٍ مضى');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'لا «اجتماع مجدول» للعميل');
    }

    // ── ٤ · رابط الاجتماع يُطلق عند فتح الدخول ──

    public function test_meeting_link_released_when_join_opens(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'l@example.com']);
        $m = $this->meeting(['user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'starts_at' => now()->addMinutes(3)]);
        $later = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(40)]);

        $this->artisan('zoom:release-links')->assertSuccessful();

        $this->assertNotNull($m->fresh()->link_released_at);
        $this->assertNull($later->fresh()->link_released_at, 'قبل فتح الدخول لا إطلاق');
        Mail::assertQueued(MeetingReminderMail::class, fn ($mail) => $mail->hasTo('c@example.com') && str_contains((string) $mail->joinUrl, '/meetingroom?ref='.$m->ref));
        Mail::assertQueued(MeetingReminderMail::class, fn ($mail) => $mail->hasTo('l@example.com') && str_contains((string) $mail->joinUrl, '/lawyer/meetingroom'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%فُتح باب الدخول%')->count());

        // مرّةً واحدة
        Mail::fake();
        $this->artisan('zoom:release-links')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_reschedule_rearms_reminders_and_link(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $m = $this->meeting(['reminder_sent_at' => now(), 'reminder_near_sent_at' => now(), 'link_released_at' => now()]);

        $this->actingAs($admin)->post(route('admin.meetings.reschedule', $m), [
            'day' => now()->addDays(5)->format('Y-m-d'), 'time' => '11:00', 'reason' => 'client_request',
        ])->assertRedirect();

        $fresh = $m->fresh();
        $this->assertNull($fresh->reminder_sent_at);
        $this->assertNull($fresh->reminder_near_sent_at);
        $this->assertNull($fresh->link_released_at);
    }

    // ── ٥ · قسم «الاجتماعات» في الإعدادات ──

    public function test_meeting_settings_live_in_their_own_group(): void
    {
        $this->assertArrayHasKey('meetings', SettingsRegistry::groups());
        foreach (['meeting_reschedule_limit', 'meeting_reminder_lead', 'meeting_reminder_near_minutes', 'meeting_autoclose_minutes', 'meet_invite_expire_minutes', 'decision_task_due_days'] as $key) {
            $this->assertSame('meetings', SettingsRegistry::field($key)['group'], $key);
        }
        $this->assertStringNotContainsString('غير المؤكَّدة', SettingsRegistry::field('meet_invite_expire_minutes')['label']);
        $this->assertSame(30, SettingsRegistry::int('meeting_reminder_near_minutes'));
    }

    public function test_first_reminder_must_precede_the_second(): void
    {
        $errors = SettingsRegistry::relationErrors(['meeting_reminder_lead' => 20, 'meeting_reminder_near_minutes' => 30]);

        $this->assertArrayHasKey('meeting_reminder_lead', $errors);
    }

    // ── ٦ و٧ · طبقتا التذكير ──

    public function test_first_reminder_reaches_participants_too(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);
        $employee = User::factory()->create(['role' => Role::Employee, 'email' => 'e@example.com']);
        $m = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(45)]);
        $m->participantUsers()->sync([$employee->id]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Mail::assertQueued(MeetingReminderMail::class, fn ($mail) => $mail->hasTo('c@example.com'));
        Mail::assertQueued(MeetingReminderMail::class, fn ($mail) => $mail->hasTo('e@example.com'));
    }

    public function test_client_gets_second_reminder_half_an_hour_before(): void
    {
        Mail::fake();
        Bus::fake([SendSmsJob::class]);
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550001']);
        $m = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(25), 'reminder_sent_at' => now()->subMinutes(30)]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        $this->assertNotNull($m->fresh()->reminder_near_sent_at);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%تذكير: اجتماعك%')->count());
        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550001' && str_contains($job->body, $m->ref));
        Mail::assertNothingQueued();

        // مرّةً واحدة
        Bus::fake([SendSmsJob::class]);
        $this->artisan('meetings:send-reminders')->assertSuccessful();
        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%تذكير: اجتماعك%')->count());
    }

    public function test_second_reminder_without_sms_provider_still_notifies(): void
    {
        Bus::fake([SendSmsJob::class]);
        config(['services.taqnyat.api_key' => null]);
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550001']);
        $this->meeting(['user_id' => $client->id, 'starts_at' => now()->addMinutes(25)]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%تذكير: اجتماعك%')->count());
    }
}
