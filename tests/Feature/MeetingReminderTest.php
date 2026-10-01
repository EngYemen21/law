<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\User;
use App\Support\MeetingTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** تذكير الاجتماعات: starts_at يُعبّأ عند الجدولة، وأمر meetings:send-reminders يرسل مرّة واحدة. */
class MeetingReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_populates_starts_at(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع', 'type' => 'اجتماع مع عميل',
            'client_id' => $client->id, 'day' => now()->addDays(3)->toDateString(), 'time' => '10:00',
        ])->assertRedirect();

        $meeting = Meeting::firstOrFail();
        $this->assertNotNull($meeting->starts_at);
        $this->assertSame(now()->addDays(3)->toDateString().' 10:00', $meeting->starts_at->format('Y-m-d H:i'));
    }

    public function test_reminder_sent_once_within_window(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'l@example.com']);

        $meeting = Meeting::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'ref' => 'M-8000', 'title' => 'جلسة مرافعة', 'when_label' => 'اليوم',
            // داخل الطبقة البعيدة (60د) وقبل القريبة (30د) — ما دون نصف الساعة إشعارٌ ورسالة لا بريد (2026-10-01)
            'starts_at' => now()->addMinutes(45), 'status' => 'قادم',
        ]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Mail::assertQueued(MeetingReminderMail::class, fn ($m) => $m->hasTo('c@example.com'));
        Mail::assertQueued(MeetingReminderMail::class, fn ($m) => $m->hasTo('l@example.com'));
        $this->assertNotNull($meeting->fresh()->reminder_sent_at);

        // idempotent — تشغيل ثانٍ لا يرسل مجدداً
        Mail::fake();
        $this->artisan('meetings:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_no_reminder_outside_window_or_without_starts_at(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);

        // بعيد (خارج نافذة 60د)
        Meeting::create(['user_id' => $client->id, 'ref' => 'M-8001', 'title' => 'بعيد', 'when_label' => 'غداً',
            'starts_at' => now()->addHours(5), 'status' => 'قادم']);
        // بلا starts_at (تعذّر تحليل الموعد)
        Meeting::create(['user_id' => $client->id, 'ref' => 'M-8002', 'title' => 'بلا موعد', 'when_label' => 'الاثنين',
            'starts_at' => null, 'status' => 'قادم']);
        // منتهٍ
        Meeting::create(['user_id' => $client->id, 'ref' => 'M-8003', 'title' => 'منتهٍ', 'when_label' => 'اليوم',
            'starts_at' => now()->addMinutes(20), 'status' => 'منتهٍ']);

        $this->artisan('meetings:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_arabic_free_text_date_yields_null(): void
    {
        $this->assertNull(MeetingTime::parse('الاثنين 29 يونيو', '11:30 ص'));
        $this->assertNull(MeetingTime::parse('—', '—'));
        $this->assertNotNull(MeetingTime::parse('2026-08-08', '10:00'));
    }
}
