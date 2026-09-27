<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ملخّص AI Companion من Zoom: تحليل الاستجابة، تفعيل التلخيص عند الإنشاء،
 * وأمر الاستطلاع (حفظ + بثّ + idempotent).
 */
class ZoomMeetingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function configureS2S(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    private function endedVideoConsult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.uniqid(),
            'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'اليوم', 'time' => '11:00', 'when_label' => 'اليوم',
            'session' => 'منتهية', 'status' => 'منتهية', 'meet_id' => '81823767754',
        ], $extra));
    }

    public function test_meeting_summary_parses_zoom_response(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'نظرة عامة عن الجلسة',
                'summary_details' => [
                    ['label' => 'الوقائع', 'summary' => 'عرض العميل موضوعه'],
                    ['label' => 'الرأي', 'summary' => 'يُنصح بإنذار'],
                ],
                'next_steps' => ['توجيه إنذار', 'تجهيز دعوى'],
            ]),
        ]);

        $out = app(ZoomService::class)->meetingSummary('81823767754');

        $this->assertNotNull($out);
        $this->assertSame('نظرة عامة عن الجلسة', $out['overview']);
        $this->assertCount(2, $out['details']);
        $this->assertSame('الوقائع', $out['details'][0]['label']);
        $this->assertSame(['توجيه إنذار', 'تجهيز دعوى'], $out['next_steps']);
    }

    public function test_meeting_summary_null_when_not_ready_or_no_scope(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response(['message' => 'not ready'], 400),
        ]);

        $this->assertNull(app(ZoomService::class)->meetingSummary('999'));
    }

    public function test_failed_token_is_not_re_fetched_immediately_negative_cache(): void
    {
        // خلل التخزين السلبي: كان فشل الرمز يُعاد نداؤه كل مرّة (عاصفة إعادة محاولة أثناء انقطاع Zoom).
        // الآن يُوضع مفتاح تهدئة، فنداءان متتاليان بعد الفشل = نداء OAuth واحد فقط.
        $this->configureS2S();
        Http::fake(['zoom.us/oauth/token' => Http::response(['error' => 'down'], 500)]);

        $zoom = app(ZoomService::class);
        $this->assertNull($zoom->meetingSummary('123')); // يفشل الرمز → تهدئة
        $this->assertNull($zoom->meetingSummary('123')); // يجب أن يُرجع فوراً بلا نداء جديد

        Http::assertSentCount(1); // نداء OAuth واحد فقط رغم محاولتين
    }

    public function test_create_meeting_enables_auto_summary(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response(['id' => 123, 'join_url' => 'j', 'start_url' => 's', 'password' => 'p']),
        ]);

        app(ZoomService::class)->createMeeting('اختبار', 60);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'meetings')
            && ($req['settings']['auto_start_meeting_summary'] ?? null) === true);
    }

    public function test_pull_command_saves_zoom_summary_and_broadcasts(): void
    {
        $this->configureS2S();
        Event::fake([ConsultStatusBroadcast::class]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'ملخّص مولّد من زوم',
                'summary_details' => [['label' => 'أبرز النقاط', 'summary' => 'نوقشت التفاصيل']],
                'next_steps' => ['متابعة المستندات'],
            ]),
        ]);
        $consult = $this->endedVideoConsult();

        $this->artisan('zoom:pull-summaries')->assertExitCode(0);

        $consult->refresh();
        $this->assertNotNull($consult->zoom_summary_at);
        $this->assertStringContainsString('ملخّص مولّد من زوم', (string) $consult->summary);
        // لا يُكشف للعميل أنّ الملخّص مُولّد بالذكاء الاصطناعي
        $this->assertStringNotContainsString('Zoom AI Companion', (string) $consult->summary);
        $this->assertStringContainsString('متابعة المستندات', (string) $consult->summary);
        Event::assertDispatched(ConsultStatusBroadcast::class);
    }

    public function test_summary_from_payload_parses_webhook_object(): void
    {
        // حمولة meeting.summary_completed تحمل الملخّص جاهزاً — تُقرأ مباشرةً بلا نداء API
        $out = ZoomService::summaryFromPayload([
            'summary_overview' => 'نظرة عامة من الحمولة',
            'summary_details' => [['label' => 'الوقائع', 'summary' => 'تفصيل']],
            'next_steps' => ['خطوة أولى'],
        ]);

        $this->assertNotNull($out);
        $this->assertSame('نظرة عامة من الحمولة', $out['overview']);
        $this->assertSame('الوقائع', $out['details'][0]['label']);
        $this->assertSame(['خطوة أولى'], $out['next_steps']);
    }

    public function test_summary_from_empty_payload_is_null(): void
    {
        // حمولة بلا محتوى لا تُختَم كملخّص (كي لا تُحجب محاولة لاحقة)
        $this->assertNull(ZoomService::summaryFromPayload(null));
        $this->assertNull(ZoomService::summaryFromPayload([]));
        $this->assertNull(ZoomService::summaryFromPayload(['summary_overview' => '', 'summary_details' => [], 'next_steps' => []]));
    }

    public function test_pull_falls_back_to_webhook_payload_when_api_has_nothing(): void
    {
        // العقد الجديد (2026-08-26): يُستعلم الـAPI أولاً — الجلب الكامل يدمج كل انعقادات
        // الجلسة المنقطعة بينما حمولة الويبهوك تخصّ انعقاداً واحداً (حادثة M-26753 الثانية).
        // فإن لم يكن لدى الـAPI شيء، تبقى الحمولة احتياطاً حياً فلا يضيع ملخّصها.
        $this->configureS2S();
        Event::fake([ConsultStatusBroadcast::class]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/*/instances' => Http::response(['meetings' => []]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response(['message' => 'Invalid meeting id'], 400),
            'api.zoom.us/v2/past_meetings/*' => Http::response(['message' => 'not found'], 404),
        ]);
        $consult = $this->endedVideoConsult();

        $ok = ConsultSummary::pull($consult, app(ZoomService::class), [
            'summary_overview' => 'ملخّص من الحمولة مباشرةً',
            'summary_details' => [['label' => 'أبرز النقاط', 'summary' => 'نوقشت التفاصيل']],
            'next_steps' => ['متابعة المستندات'],
        ]);

        $this->assertTrue($ok);
        $consult->refresh();
        $this->assertNotNull($consult->zoom_summary_at);
        $this->assertStringContainsString('ملخّص من الحمولة مباشرةً', (string) $consult->summary);
        $this->assertStringContainsString('متابعة المستندات', (string) $consult->summary);
        // الـAPI استُعلم أولاً (الجلب الكامل) وسقط ⇒ الحمولة أنقذت الملخّص
        Http::assertSent(fn ($req) => str_contains($req->url(), 'meeting_summary'));
        Event::assertDispatched(ConsultStatusBroadcast::class);
    }

    public function test_pull_command_is_idempotent(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response(['summary_overview' => 'يجب ألا يُستدعى'], 200),
        ]);
        // سبق جلب ملخّصها ⇒ تُتجاهل
        $consult = $this->endedVideoConsult(['zoom_summary_at' => now(), 'summary' => 'ملخّص سابق']);

        $this->artisan('zoom:pull-summaries')->assertExitCode(0);

        $this->assertSame('ملخّص سابق', $consult->fresh()->summary);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'meeting_summary'));
    }
}
