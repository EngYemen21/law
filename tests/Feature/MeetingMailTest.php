<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\MeetingEndedMail;
use App\Mail\MeetingScheduledMail;
use App\Mail\MeetInviteMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\MeetInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** بريد الاجتماعات موصول بدورة الحياة: جدولة → بريد موعد؛ اعتماد → بريد انتهاء + ملخّص. */
class MeetingMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_meeting_emails_client_and_lawyer(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'client@example.com']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'lawyer@example.com']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع مراجعة العقد', 'type' => 'اجتماع مع عميل',
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'day' => now()->addDays(3)->toDateString(), 'time' => '10:00',
        ])->assertRedirect();

        Mail::assertQueued(MeetInviteMail::class, fn ($m) => $m->hasTo('client@example.com'));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($m) => $m->hasTo('lawyer@example.com'));
    }

    public function test_publishing_invite_emails_client(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c2@example.com']);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-9001', 'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية', 'day' => 'الاثنين', 'time' => '11:30', 'sent_by' => 'المكتب',
        ]);
        // المنطق انتقل من confirm إلى MeetInvitation: الجدولة تُنشئ الاجتماع، وannounce
        // تُرسل بريد «اجتماع مجدول» للعميل. الاختبار ينادي موضعه الجديد لا مساراً محذوفاً.
        $meeting = MeetInvitation::schedule($req, $client);
        MeetInvitation::announce($req->fresh(), $meeting, $client);

        Mail::assertQueued(MeetingScheduledMail::class, fn ($m) => $m->hasTo('c2@example.com'));
    }

    public function test_approval_emails_client_meeting_ended_with_summary(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c3@example.com']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9100', 'title' => 'اجتماع مراجعة',
            'when_label' => 'اليوم · 10ص', 'status' => 'منتهٍ', 'summary' => 'ملخّص معتمد', 'minutes' => 'محضر',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();

        Mail::assertQueued(MeetingEndedMail::class, fn ($m) => $m->hasTo('c3@example.com') && $m->summary === 'ملخّص معتمد');
    }

    public function test_no_email_when_meeting_has_no_client(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);

        // اجتماع داخليّ بلا عميل → لا بريد جدولة للعميل
        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع داخلي', 'type' => 'داخلي', 'day' => '2026-08-09', 'time' => '09:00',
        ])->assertRedirect();

        Mail::assertNotQueued(MeetingScheduledMail::class);
    }
}
