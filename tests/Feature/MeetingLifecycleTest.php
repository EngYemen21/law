<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\MeetingEventMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * دورة حياة الاجتماع مع مزامنة Zoom: بدء | إعادة جدولة (PATCH) | إلغاء (DELETE) | إنهاء (PUT status end).
 * كل حدث best-effort يزامن Zoom ويُشعر ويبثّ؛ الويبهوك المتأخر لا يُحيي اجتماعًا نهائيًا.
 */
class MeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const WH_SECRET = 'whsec_meet';

    private function configureS2S(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    private function fakeZoom(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*' => Http::response('', 204),
        ]);
    }

    private function meeting(?User $lawyer = null, array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-'.uniqid(),
            'title' => 'اجتماع تجريبي', 'type' => 'اجتماع مع عميل', 'when_label' => 'اليوم · 11:00', 'status' => 'قادم',
            'meet_id' => '81823767754', 'meet_password' => 'mp123',
            'assigned_lawyer_id' => $lawyer?->id, ], $extra));
    }

    // ── بدء الجلسة ──
    public function test_admin_start_marks_meeting_live_and_advances_request(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = $this->meeting(null, ['user_id' => $client->id]);
        $req = MeetRequest::create([
            'ref' => 'MR-1', 'user_id' => $client->id, 'meeting_id' => $meeting->id,
            'service' => 'نزاع', 'day' => '2026-09-01', 'time' => '10:00', 'sent_by' => 'الإدارة', 'type' => 'استشارة مرئية', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.start', $meeting))->assertRedirect();

        $this->assertSame('جارٍ', $meeting->fresh()->status);
        // «القادم/الجاري» يُشتق حيّاً — is_up مهجور لم يعد يُكتب
        $this->assertTrue($meeting->fresh()->isUpcoming());
        $this->assertSame(MeetRequest::STAGE_EXECUTED, $req->fresh()->stage);
    }

    public function test_start_does_not_resurrect_ended_meeting(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ']);

        // يُرفض بسببه (`StartMeeting::guard`) — كان يُعاد بصمت فلا يعرف الضاغط لماذا لم يبدأ
        $this->actingAs($admin)->post(route('admin.meetings.start', $meeting))->assertStatus(422);

        $this->assertSame('منتهٍ', $meeting->fresh()->status);
    }

    // ── إعادة الجدولة: تزامن Zoom PATCH + إعادة تسليح التذكير + بريد ──
    public function test_reschedule_syncs_zoom_rearms_reminder_and_mails(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        Mail::fake();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'lw@example.com']);
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'cl@example.com']);
        $meeting = $this->meeting($lawyer, ['user_id' => $client->id, 'reminder_sent_at' => now()->subHour()]);

        // موعدٌ لاحقٌ دائماً — تاريخٌ مثبَّت يصير ماضياً مع الأيّام، والماضي يُرفض الآن
        $day = now()->addWeek()->toDateString();
        $this->actingAs($lawyer)
            ->post(route('lawyer.meetings.reschedule', $meeting), ['day' => $day, 'time' => '14:30', 'reason' => 'client_request'])
            ->assertRedirect();

        $fresh = $meeting->fresh();
        $this->assertSame('قادم', $fresh->status);
        $this->assertNotNull($fresh->starts_at);
        $this->assertNull($fresh->reminder_sent_at);
        $this->assertStringContainsString('14:30', (string) $fresh->when_label);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'api.zoom.us/v2/meetings/81823767754')
            && $r['start_time'] === $day.'T14:30:00');
        Mail::assertQueued(MeetingEventMail::class, fn ($m) => $m->event === 'rescheduled' && $m->hasTo('cl@example.com'));
        Mail::assertQueued(MeetingEventMail::class, fn ($m) => $m->hasTo('lw@example.com'));
    }

    /**
     * **التأجيل بلا موعد يبقى ممكناً — لكن بنيّةٍ صريحة.**
     *
     * كان يقع بكتابة أيّ نصٍّ لا يُفكّ في حقل التاريخ: تأجيلٌ بالمصادفة لا بالقصد.
     * وتشديدُ الصيغة وحده كان سيجعل الحالة «مؤجل» **غير قابلة للبلوغ** رغم أن لها
     * تبويباً وعدّاداً في لوحة الاجتماعات — عدّادٌ يتجمّد فيبدو أنه لا تأجيل في المكتب.
     */
    public function test_an_explicit_postpone_marks_postponed_and_skips_zoom(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = $this->meeting($lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.meetings.reschedule', $meeting), ['postpone' => true, 'reason' => 'client_request'])
            ->assertRedirect();

        $fresh = $meeting->fresh();
        $this->assertSame('مؤجل', $fresh->status);
        $this->assertNull($fresh->starts_at, 'التأجيل بلا موعد — ولا يُخترع له طابع زمنيّ');
        // ولا يُمسّ اجتماع Zoom: التأجيل ليس إلغاءً، والخلط بينهما يحذف الغرفة.
        Http::assertNothingSent();
    }

    /** **والنصّ الحرّ لم يعد باباً خلفياً إليها:** يُرفض برسالة، ولا يُؤجّل صامتاً. */
    public function test_unparseable_text_no_longer_postpones_by_accident(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = $this->meeting($lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.meetings.reschedule', $meeting), ['day' => 'يُحدَّد لاحقًا', 'reason' => 'client_request'])
            ->assertSessionHasErrors('day');

        $this->assertSame('قادم', $meeting->fresh()->status, 'نصٌّ لا يُفكّ لا يُغيّر الحالة');
        Http::assertNothingSent();
    }

    // ── الإلغاء: تزامن Zoom DELETE + تصفير meet_id + بريد ──
    public function test_cancel_deletes_zoom_clears_meet_id_and_mails(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        Mail::fake();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'cl@example.com']);
        $meeting = $this->meeting($lawyer, ['user_id' => $client->id]);
        MeetRequest::create([
            'ref' => 'MR-2', 'user_id' => $client->id, 'meeting_id' => $meeting->id,
            'service' => 'نزاع', 'day' => '2026-09-01', 'time' => '10:00', 'sent_by' => 'الإدارة', 'type' => 'استشارة مرئية', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.meetings.cancel', $meeting))->assertRedirect();

        $fresh = $meeting->fresh();
        $this->assertSame('ملغى', $fresh->status);
        $this->assertNull($fresh->meet_id);
        $this->assertFalse($fresh->isUpcoming());
        // الدعوة تبقى سجلاً تاريخياً بمرحلة «أُلغيت» (كان الحذف الصلب يُخفيها عن العميل بلا تفسير)
        $req = MeetRequest::where('meeting_id', $meeting->id)->firstOrFail();
        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->stage);
        $this->assertFalse($req->canJoin());

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), 'api.zoom.us/v2/meetings/81823767754'));
        Mail::assertQueued(MeetingEventMail::class, fn ($m) => $m->event === 'cancelled' && $m->hasTo('cl@example.com'));
    }

    public function test_cancel_is_noop_for_already_ended_meeting(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ']);

        $this->actingAs($admin)->post(route('admin.meetings.cancel', $meeting))->assertRedirect();

        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        Http::assertNothingSent();
    }

    // ── الإنهاء: تزامن Zoom PUT status end ──
    public function test_end_calls_zoom_end_and_marks_finished(): void
    {
        $this->configureS2S();
        $this->fakeZoom();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(null, ['status' => 'جارٍ', 'is_up' => true]);

        $this->actingAs($admin)->post(route('admin.meetings.end', $meeting), ['attend' => 90])->assertRedirect();

        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'api.zoom.us/v2/meetings/81823767754/status')
            && $r['action'] === 'end');
    }

    // ── الويبهوك المتأخر لا يُحيي اجتماعًا مُلغى ──
    public function test_late_webhook_does_not_resurrect_cancelled_meeting(): void
    {
        // اجتماع مُلغى لكن meet_id ما يزال موجودًا (حدث Zoom مطابور قبل الحذف)
        $meeting = $this->meeting(null, ['status' => 'ملغى']);

        $this->postSignedWebhook(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();

        $this->assertSame('ملغى', $meeting->fresh()->status);
    }

    private function postSignedWebhook(array $payload): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::WH_SECRET]);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp;
        $sig = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::WH_SECRET);

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    // ── وصول مسارات الغرفة المضمّنة (لا 404) ──
    public function test_meeting_rooms_are_reachable_in_site(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting();

        $this->actingAs($admin)->get(route('admin.meetingroom', ['ref' => $meeting->ref]))->assertOk();

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->get(route('employee.meetingroom', ['ref' => $meeting->ref]))->assertOk();
    }

    // ── تنزيل النص الكامل المحفوظ ──
    public function test_transcript_downloads_when_present(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('transcripts/meeting-M-1.txt', 'نص الاجتماع الكامل');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ', 'transcript_path' => 'transcripts/meeting-M-1.txt']);

        $this->actingAs($admin)->get(route('admin.meetings.transcript', $meeting))
            ->assertOk()
            ->assertDownload('transcript-'.$meeting->ref.'.txt');
    }

    public function test_transcript_returns_404_when_absent(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ']);

        $this->actingAs($admin)->get(route('admin.meetings.transcript', $meeting))->assertNotFound();
    }
}
