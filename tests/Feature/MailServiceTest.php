<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_to_user_model(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'lawyer@example.com', 'role' => Role::Lawyer]);

        $ok = app(MailService::class)->send($user, new MeetingScheduledMail('أ. سارة', 'جلسة تحكيم', 'الأحد 10ص'));

        $this->assertTrue($ok);
        Mail::assertQueued(MeetingScheduledMail::class, fn ($m) => $m->hasTo('lawyer@example.com'));
    }

    public function test_sends_to_plain_email_and_array(): void
    {
        Mail::fake();

        app(MailService::class)->send('a@example.com', new MeetingScheduledMail('عميل', 'اجتماع', 'غداً'));
        app(MailService::class)->send(['b@example.com', 'c@example.com'], new MeetingScheduledMail('فريق', 'اجتماع', 'غداً'));

        Mail::assertQueued(MeetingScheduledMail::class, fn ($m) => $m->hasTo('a@example.com'));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($m) => $m->hasTo('b@example.com') && $m->hasTo('c@example.com'));
    }

    public function test_invalid_recipient_sends_nothing(): void
    {
        Mail::fake();

        $this->assertFalse(app(MailService::class)->send('not-an-email', new MeetingScheduledMail('x', 'y', 'z')));
        Mail::assertNothingQueued();
    }
}
