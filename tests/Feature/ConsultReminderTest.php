<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Mail\ConsultReminderMail;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SessionLinkSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تذكيرات مواعيد الاستشارات — لم يكن لها اختبار واحد قبل اليوم (الاجتماعات وجلسات
 * المحاكم مغطّاة، والاستشارات لا).
 *
 * الطبقتان: بريد قبل 24 ساعة · قبل 30 دقيقة رسالةٌ نصّيّة للحضوريّة والهاتفيّة، وإشعارٌ في الحساب
 * للمرئيّة (رسالتها عند فتح الدخول — قرار «ب»). وما بعد فتح الدخول يغطّيه zoom:release-links.
 */
class ConsultReminderTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledConsult(User $client, int $minutesAway, bool $paid = true, string $channel = 'حضورية'): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-REM-'.$minutesAway,
            'subject' => 'نزاع تجاري',
            'channel' => $channel,
            'lawyer' => 'أ. سارة',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'starts_at' => now()->addMinutes($minutesAway),
            'when_label' => 'اليوم',
            'paid_at' => $paid ? now()->subDay() : null,
        ]);
    }

    private function configureTaqnyat(): void
    {
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
    }

    public function test_sms_reminder_goes_out_once_half_an_hour_before(): void
    {
        Bus::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550001']);
        $consult = $this->scheduledConsult($client, 8);   // داخل الطبقة القريبة (10د افتراضاً)

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertDispatched(SendSmsJob::class, function (SendSmsJob $job) use ($consult) {
            return $job->intlPhone === '966555550001'
                && str_contains($job->body, $consult->ref)
                && str_contains($job->body, 'تذكير')
                && str_contains($job->body, SessionLinkSms::when($consult->starts_at)); // التاريخ والوقت
        });
        $this->assertNotNull($consult->fresh()->reminder_30m_sent_at);

        // تشغيل ثانٍ لا يرسل شيئاً — الختم يمنع التكرار
        Bus::fake();
        $this->artisan('consults:send-reminders')->assertSuccessful();
        Bus::assertNotDispatched(SendSmsJob::class);
    }

    /** الرسالة تكلّف مالاً — لا تُنفَق على استشارة غير مسدَّدة. */
    public function test_unpaid_consult_gets_no_reminder(): void
    {
        Bus::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550002']);
        $consult = $this->scheduledConsult($client, 20, paid: false);

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertNull($consult->fresh()->reminder_30m_sent_at);
    }

    /** بلا جوال: لا إرسال ولا ختم — كي يُعاد في تشغيل لاحق إن أُضيف الرقم. */
    public function test_client_without_a_phone_is_skipped_without_stamping(): void
    {
        Bus::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => null]);
        $consult = $this->scheduledConsult($client, 20);

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertNull($consult->fresh()->reminder_30m_sent_at);
    }

    /** مزوّد غير مهيّأ: لا استثناء ولا ختم. */
    public function test_unconfigured_provider_does_not_stamp_or_throw(): void
    {
        Bus::fake();
        config(['services.taqnyat.api_key' => '', 'services.taqnyat.sender' => '']);
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550003']);
        $consult = $this->scheduledConsult($client, 20);

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertNull($consult->fresh()->reminder_30m_sent_at);
    }

    /** الطبقة البعيدة تبقى بريداً — ولا رسالة نصّية معها. */
    public function test_far_layer_emails_only(): void
    {
        Bus::fake();
        Mail::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550004']);
        $consult = $this->scheduledConsult($client, 120);

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Mail::assertQueued(ConsultReminderMail::class);
        Bus::assertNotDispatched(SendSmsJob::class);

        $fresh = $consult->fresh();
        $this->assertNotNull($fresh->reminder_24h_sent_at);
        $this->assertNull($fresh->reminder_30m_sent_at);
    }

    /** قرار «ب»: المرئيّة يصلها في الطبقة القريبة إشعارٌ لا رسالة — رسالتها واحدةٌ عند فتح الدخول. */
    public function test_video_consult_near_layer_is_a_notification_not_an_sms(): void
    {
        Bus::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550006']);
        $consult = $this->scheduledConsult($client, 8, channel: Consult::CHANNEL_VIDEO);

        $this->artisan('consults:send-reminders')->assertSuccessful();
        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        $this->assertNotNull($consult->fresh()->reminder_30m_sent_at);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%'.$consult->ref.'%')->count());
    }

    /** ما بعد فتح الدخول ليس مسؤوليّة هذا الأمر — يغطّيه إطلاق رابط الجلسة. */
    public function test_last_minutes_are_left_to_link_release(): void
    {
        Bus::fake();
        Mail::fake();
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550005']);
        $consult = $this->scheduledConsult($client, 3);

        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendSmsJob::class);
        Mail::assertNothingQueued();
        $this->assertNull($consult->fresh()->reminder_30m_sent_at);
    }

    /**
     * 🔴 إعادة الجدولة كانت تُبقي الأختام، فالاستشارة المؤجَّلة لا يصلها تذكير أبداً.
     */
    public function test_rescheduling_clears_the_stamps_so_reminders_resume(): void
    {
        $this->configureTaqnyat();
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550006']);
        $consult = $this->scheduledConsult($client, 20);
        $consult->update([
            'reminder_24h_sent_at' => now()->subHour(),
            'reminder_30m_sent_at' => now()->subMinutes(10),
        ]);

        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->post(route('employee.consults.reschedule', $consult), ['reason' => 'client_request']);

        $fresh = $consult->fresh();
        $this->assertNull($fresh->reminder_24h_sent_at, 'ختم 24 ساعة بقي بعد إعادة الجدولة.');
        $this->assertNull($fresh->reminder_30m_sent_at, 'ختم 30 دقيقة بقي بعد إعادة الجدولة.');
    }
}
