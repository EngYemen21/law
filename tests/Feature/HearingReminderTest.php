<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\HearingReminderMail;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تذكير جلسات القضايا: أمر hearings:send-reminders يرسل إشعارًا داخليًا + بريدًا للعميل والمحامي
 * مرّة واحدة لكل طبقة (24س/1س)، ولا يعمل على جلسة بلا starts_at.
 */
class HearingReminderTest extends TestCase
{
    use RefreshDatabase;

    private function caseWithLawyer(): array
    {
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'l@example.com']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-2026-6100', 'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue',
            'update_text' => '—',
        ]);

        return [$client, $lawyer, $case];
    }

    public function test_hour_layer_notifies_and_emails_both_once(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->caseWithLawyer();
        $hearing = $case->hearings()->create([
            'title' => 'الجلسة الأولى', 'day' => '2026-08-10', 'court' => 'الدائرة التجارية', 'status' => 'مجدولة',
            'starts_at' => now()->addMinutes(30),
        ]);

        $this->artisan('hearings:send-reminders')->assertSuccessful();

        Mail::assertQueued(HearingReminderMail::class, fn ($m) => $m->hasTo('c@example.com'));
        Mail::assertQueued(HearingReminderMail::class, fn ($m) => $m->hasTo('l@example.com'));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id, 'icon' => 'cal']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $lawyer->id, 'icon' => 'cal']);
        $this->assertNotNull($hearing->fresh()->reminder_1h_sent_at);

        // idempotent — تشغيل ثانٍ لا يرسل مجدداً
        Mail::fake();
        $this->artisan('hearings:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_24h_layer_stamps_24h_column(): void
    {
        Mail::fake();
        [, , $case] = $this->caseWithLawyer();
        $hearing = $case->hearings()->create([
            'title' => 'جلسة', 'day' => '2026-08-10', 'status' => 'مجدولة', 'starts_at' => now()->addHours(2),
        ]);

        $this->artisan('hearings:send-reminders')->assertSuccessful();

        $this->assertNotNull($hearing->fresh()->reminder_24h_sent_at);
        $this->assertNull($hearing->fresh()->reminder_1h_sent_at);
        Mail::assertQueued(HearingReminderMail::class);
    }

    public function test_no_reminder_without_starts_at(): void
    {
        Mail::fake();
        [, , $case] = $this->caseWithLawyer();
        $case->hearings()->create(['title' => 'جلسة', 'day' => 'الأحد', 'status' => 'مجدولة']); // بلا starts_at

        $this->artisan('hearings:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_no_reminder_for_cancelled_hearing(): void
    {
        Mail::fake();
        [, , $case] = $this->caseWithLawyer();
        $case->hearings()->create(['title' => 'جلسة', 'day' => '2026-08-10', 'status' => 'ملغاة', 'starts_at' => now()->addMinutes(30)]);

        $this->artisan('hearings:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }
}
