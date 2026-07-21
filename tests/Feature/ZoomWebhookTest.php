<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Jobs\ProcessZoomSummaryJob;
use App\Models\Consult;
use App\Models\User;
use App\Support\ZoomWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * أحداث Zoom (م4): تحقّق التوقيع، تحقّق نقطة النهاية، وتوجيه بدء/انتهاء/ملخّص إلى الاستشارة.
 */
class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private function configureSecret(): void
    {
        config(['services.zoom.webhook_secret' => self::SECRET]);
    }

    /** يرسل حمولة webhook موقّعة تماماً كما يفعل Zoom (توقيع على الجسم الخام). */
    private function postSigned(array $payload, ?string $signature = null, ?string $timestamp = null): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = $timestamp ?? (string) now()->timestamp; // ختم حديث (فحص منع إعادة الإرسال)
        $sig = $signature ?? 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::SECRET);

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function videoConsult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'محامٍ',
            'day' => 'اليوم', 'time' => '11:00', 'when_label' => 'اليوم', 'meet_id' => '81823767754',
        ], $extra));
    }

    public function test_verify_and_validation_response(): void
    {
        $this->configureSecret();
        $raw = '{"a":1}';
        $ts = '123';
        $good = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::SECRET);

        $this->assertTrue(ZoomWebhook::verify($ts, $raw, $good));
        $this->assertFalse(ZoomWebhook::verify($ts, $raw, 'v0=deadbeef'));

        $resp = ZoomWebhook::validationResponse('abc123');
        $this->assertSame('abc123', $resp['plainToken']);
        $this->assertSame(hash_hmac('sha256', 'abc123', self::SECRET), $resp['encryptedToken']);
    }

    public function test_url_validation_endpoint_returns_encrypted_token(): void
    {
        $this->configureSecret();

        $this->postSigned([
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'PLAIN123'],
        ])->assertOk()
            ->assertJsonPath('plainToken', 'PLAIN123')
            ->assertJsonPath('encryptedToken', hash_hmac('sha256', 'PLAIN123', self::SECRET));
    }

    public function test_invalid_signature_is_forbidden(): void
    {
        $this->configureSecret();

        $this->postSigned(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '1']]], 'v0=wrong')
            ->assertForbidden();
    }

    public function test_missing_secret_returns_503(): void
    {
        config(['services.zoom.webhook_secret' => null]);

        $this->postSigned(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '1']]])
            ->assertStatus(503);
    }

    public function test_meeting_ended_marks_consult_ended_and_broadcasts(): void
    {
        $this->configureSecret();
        Event::fake([ConsultStatusBroadcast::class]);
        $consult = $this->videoConsult(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);

        $this->postSigned([
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '81823767754']],
        ])->assertOk();

        $this->assertSame('منتهية', $consult->fresh()->session);
        Event::assertDispatched(ConsultStatusBroadcast::class);
    }

    public function test_meeting_started_marks_consult_live(): void
    {
        $this->configureSecret();
        $consult = $this->videoConsult(['session' => 'بانتظار الجلسة', 'status' => 'مؤكدة']);

        $this->postSigned([
            'event' => 'meeting.started',
            'payload' => ['object' => ['id' => '81823767754']],
        ])->assertOk();

        $this->assertSame('جلسة جارية', $consult->fresh()->session);
    }

    public function test_ended_event_does_not_downgrade_already_ended(): void
    {
        $this->configureSecret();
        $consult = $this->videoConsult(['session' => 'منتهية', 'status' => 'منتهية', 'summary' => 'محفوظ']);

        $this->postSigned(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '81823767754']]])->assertOk();

        $this->assertSame('منتهية', $consult->fresh()->session); // لا يُعاد فتحها
    }

    public function test_summary_completed_pulls_and_stores_zoom_summary(): void
    {
        $this->configureSecret();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'ملخّص فوري من زوم',
                'summary_details' => [['label' => 'أبرز النقاط', 'summary' => 'نقاش']],
                'next_steps' => ['متابعة'],
            ]),
        ]);
        $consult = $this->videoConsult(['session' => 'منتهية', 'status' => 'منتهية']);

        $this->postSigned([
            'event' => 'meeting.summary_completed',
            'payload' => ['object' => ['id' => '81823767754']],
        ])->assertOk();

        $consult->refresh();
        $this->assertNotNull($consult->zoom_summary_at);
        $this->assertStringContainsString('ملخّص فوري من زوم', (string) $consult->summary);
    }

    public function test_summary_completed_defers_to_queued_job_not_synchronous(): void
    {
        // الاختناق: كان الويبهوك ينفّذ LLM + جلب API + بثّاً متزامناً قبل الردّ 200.
        // الآن يُرسِل مهمّة فقط ويردّ فوراً؛ العمل الثقيل خلفي (Zoom يُعطّل النقاط البطيئة).
        $this->configureSecret();
        Bus::fake([ProcessZoomSummaryJob::class]);
        $consult = $this->videoConsult(['session' => 'منتهية', 'status' => 'منتهية']);

        $this->postSigned([
            'event' => 'meeting.summary_completed',
            'payload' => ['object' => ['meeting_id' => '81823767754', 'summary_overview' => 'ملخّص']],
        ])->assertOk();

        Bus::assertDispatched(ProcessZoomSummaryJob::class, fn ($job) => $job->model->is($consult));
        // لم يُنفَّذ التخزين متزامناً في الطلب — تأجّل للمهمّة
        $this->assertNull($consult->fresh()->zoom_summary_at);
    }

    public function test_stale_timestamp_is_rejected_as_replay(): void
    {
        // منع إعادة الإرسال: طلب بختم قديم (10د) يُرفَض رغم صحّة توقيعه
        $this->configureSecret();
        $stale = (string) (now()->timestamp - 600);

        $this->postSigned(
            ['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '1']]],
            null,
            $stale,
        )->assertForbidden();
    }

    public function test_summary_completed_routes_via_meeting_id_field_and_reads_payload(): void
    {
        // Zoom يرسل لهذا الحدث «meeting_id» لا «id»، والملخّص مضمّن في الحمولة.
        // كان التوجيه يفشل (يبحث عن id فقط) فلا يُخزَّن الملخّص إطلاقاً — هذا الاختبار يحرس الإصلاح.
        $this->configureSecret();
        Event::fake([ConsultStatusBroadcast::class]);
        $consult = $this->videoConsult(['session' => 'منتهية', 'status' => 'منتهية']);

        $this->postSigned([
            'event' => 'meeting.summary_completed',
            'payload' => ['object' => [
                'meeting_id' => '81823767754',   // حقل Zoom الحقيقي لهذا الحدث (بلا id)
                'meeting_uuid' => 'abc==',
                'summary_overview' => 'ملخّص من الحمولة',
                'summary_details' => [['label' => 'أبرز النقاط', 'summary' => 'نقاش']],
                'next_steps' => ['متابعة المستندات'],
            ]],
        ])->assertOk();

        $consult->refresh();
        $this->assertNotNull($consult->zoom_summary_at);
        $this->assertStringContainsString('ملخّص من الحمولة', (string) $consult->summary);
        $this->assertStringContainsString('متابعة المستندات', (string) $consult->summary);
        // لم تُهيّأ مفاتيح S2S، وقراءة الحمولة لا تنادي API إطلاقاً
        Http::assertNothingSent();
        Event::assertDispatched(ConsultStatusBroadcast::class);
    }
}
