<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\MeetInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تكافؤ اجتماعات المكتب مع Zoom: توقيع التضمين (kind=meeting)، حفظ كلمة المرور،
 * وأحداث/ملخّص AI للاجتماعات.
 */
class MeetingZoomParityTest extends TestCase
{
    use RefreshDatabase;

    private const WH_SECRET = 'whsec_meet';

    private function configureSdk(): void
    {
        config(['services.zoom.sdk_key' => 'SDKKEY', 'services.zoom.sdk_secret' => 'SDKSECRET']);
    }

    private function configureS2S(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    private function meeting(?User $lawyer = null, array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-'.random_int(1000, 9999),
            'title' => 'اجتماع تجريبي', 'type' => 'اجتماع مع عميل', 'when_label' => 'اليوم · 11:00', 'status' => 'قادم',
            'meet_id' => '81823767754', 'meet_password' => 'mp123',
            'assigned_lawyer_id' => $lawyer?->id, ], $extra));
    }

    // ── توقيع التضمين للاجتماع (kind=meeting) ──
    public function test_meeting_owner_client_gets_participant_signature(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        // «جارٍ» — التوقيع بات يفرض نافذة canJoin خادمياً، واجتماع الساعة 11:00 خارجها في وقت الاختبار المثبّت (00:30)
        $meeting = $this->meeting(null, ['user_id' => $client->id, 'status' => 'جارٍ']);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertOk()
            ->assertJsonPath('role', 0)
            ->assertJsonPath('meetingNumber', '81823767754')
            ->assertJsonPath('password', 'mp123')
            ->assertJsonPath('zak', null);
    }

    public function test_meeting_assigned_lawyer_gets_host_signature_with_zak(): void
    {
        Cache::flush();
        $this->configureSdk();
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/token*' => Http::response(['token' => 'ZAKMEET']),
        ]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = $this->meeting($lawyer, ['status' => 'جارٍ']);

        $this->actingAs($lawyer)->postJson(route('zoom.signature'), ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertOk()
            ->assertJsonPath('role', 1)
            ->assertJsonPath('zak', 'ZAKMEET');
    }

    public function test_unassigned_lawyer_cannot_sign_meeting(): void
    {
        $this->configureSdk();
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = $this->meeting($lawyerA);

        $this->actingAs($lawyerB)->postJson(route('zoom.signature'), ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertForbidden();
    }

    public function test_unknown_meeting_ref_returns_404(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => 'M-0000', 'kind' => 'meeting'])
            ->assertNotFound();
    }

    public function test_meeting_without_meet_id_returns_422(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = $this->meeting(null, ['user_id' => $client->id, 'meet_id' => null]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertStatus(422);
    }

    // ── حفظ كلمة المرور عند التأكيد ──
    public function test_schedule_persists_meeting_password(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 900900900, 'join_url' => 'https://z/j', 'start_url' => 'https://z/s', 'password' => 'secret9',
            ]),
        ]);
        $client = User::factory()->create(['role' => Role::Client]);
        $mr = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-'.random_int(1000, 9999),
            'service' => 'استشارة', 'type' => 'استشارة مرئية', 'day' => 'اليوم', 'time' => '11:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_SENT,
        ]);
        // حفظ كلمة المرور انتقل من confirm إلى MeetInvitation::schedule
        MeetInvitation::schedule($mr, $client);

        $this->assertSame('secret9', Meeting::where('meet_id', '900900900')->first()?->meet_password);
    }

    // ── Webhook للاجتماع ──
    private function postSignedWebhook(array $payload): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::WH_SECRET]);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp; // ختم حديث (فحص منع إعادة الإرسال)
        $sig = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::WH_SECRET);

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_webhook_started_and_ended_update_meeting_status(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);
        $meeting = $this->meeting(null, ['status' => 'قادم']);

        $this->postSignedWebhook(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();
        $this->assertSame('جارٍ', $meeting->fresh()->status);

        $this->postSignedWebhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();
        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        $this->assertFalse($meeting->fresh()->isUpcoming());
        Event::assertDispatched(MeetingStatusBroadcast::class);
    }

    public function test_webhook_ended_completes_lifecycle_like_manual_end(): void
    {
        // كان الويبهوك يضبط «منتهٍ» فقط فتبقى دعوة العميل عالقة في «تنفيذ الجلسة» بلا ملخّص ولا حضور
        Event::fake([MeetingStatusBroadcast::class]);
        Bus::fake([GenerateMeetingSummaryJob::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = $this->meeting(null, ['status' => 'جارٍ', 'user_id' => $client->id]);
        $mr = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $meeting->id, 'ref' => 'MR-8801',
            'service' => 'استشارة', 'type' => 'استشارة مرئية', 'day' => 'اليوم', 'time' => '11:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->postSignedWebhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();

        $meeting->refresh();
        $this->assertSame('منتهٍ', $meeting->status);
        // 0 = «حضور غير مسجَّل» تماماً كالإنهاء اليدوي بلا مدخل. كان الاختبار يطالب بـ90
        // وهي نسبة مختلقة أُزيلت عمداً من المسارين (ZoomWebhookController::endMeeting
        // وMeetingController::end) — لا تُعاد، فالعرض لا يحمل بيانات ملفّقة.
        $this->assertSame(0, $meeting->attend);
        $this->assertSame(MeetRequest::STAGE_EXECUTED, $mr->fresh()->stage);
        Bus::assertDispatched(GenerateMeetingSummaryJob::class, fn ($job) => $job->meeting->is($meeting));
        Event::assertDispatched(MeetingStatusBroadcast::class);
    }

    public function test_webhook_summary_completed_stores_meeting_zoom_summary(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'ملخّص اجتماع من زوم',
                'summary_details' => [['label' => 'أبرز النقاط', 'summary' => 'نقاش']],
                'next_steps' => ['متابعة'],
            ]),
        ]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ']);

        $this->postSignedWebhook(['event' => 'meeting.summary_completed', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();

        $meeting->refresh();
        $this->assertNotNull($meeting->zoom_summary_at);
        $this->assertStringContainsString('ملخّص اجتماع من زوم', (string) $meeting->zoom_summary);
    }

    // ── الأمر المجدول يلتقط الاجتماعات ──
    public function test_pull_command_picks_up_ended_meetings(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'ملخّص من الاستطلاع',
                'summary_details' => [], 'next_steps' => [],
            ]),
        ]);
        $meeting = $this->meeting(null, ['status' => 'منتهٍ']);

        $this->artisan('zoom:pull-summaries')->assertExitCode(0);

        $this->assertStringContainsString('ملخّص من الاستطلاع', (string) $meeting->fresh()->zoom_summary);
    }
}
