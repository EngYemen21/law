<?php

namespace Tests\Feature;

use App\Models\Consult;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    /** المسار النسبيّ لملف الاعتماد الاصطناعيّ (نسبةً لـbase_path كما يتوقّعه الصنف). */
    private const FAKE_CREDENTIALS = 'storage/framework/testing/google-credentials-fake.json';

    /**
     * اعتماد اصطناعيّ: ملف وهميّ بلا مفتاح حقيقيّ. كان الاختبار يقرأ ملف الاعتماد
     * الفعليّ من الجهاز، فينجح هنا ويسقط على أي جهاز نظيف أو على CI.
     * التوقيع نفسه لا يُنفَّذ لأن التوكن يُقرأ من الـCache (انظر `fakeToken`).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path(self::FAKE_CREDENTIALS);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'client_email' => 'fake-service-account@example.invalid',
            'private_key' => 'not-a-real-key',
        ]));

        config(['services.google_calendar.credentials_path' => self::FAKE_CREDENTIALS]);
    }

    /** توكن محقون في الـCache — يتجاوز توقيع JWT فلا يلزم مفتاح RSA حقيقيّ في الاختبار. */
    private function fakeToken(): void
    {
        Cache::put('google_service_account_access_token', 'mock_token_123', 3300);
    }

    protected function tearDown(): void
    {
        @unlink(base_path(self::FAKE_CREDENTIALS));

        parent::tearDown();
    }

    public function test_service_detects_configuration(): void
    {
        $this->assertTrue(GoogleCalendarService::isConfigured());
    }

    /** التحييد في phpunit.xml يجب أن يُطفئ المزوّد فعلاً — وإلّا تسرّبت نداءات جوجل لبقيّة الاختبارات. */
    public function test_service_is_disabled_when_configuration_is_emptied(): void
    {
        config(['services.google_calendar.credentials_path' => '']);

        $this->assertFalse(GoogleCalendarService::isConfigured());
    }

    public function test_create_and_sync_consult_event(): void
    {
        $this->fakeToken();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock_token_123',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => 'gcal_event_9988',
                'htmlLink' => 'https://www.google.com/calendar/event?eid=mock',
                'status' => 'confirmed',
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'client@test.com']);
        $consult = Consult::create([
            'user_id' => $user->id,
            'ref' => 'CN-2026-9999',
            'subject' => 'استشارة تجارية تجريبية',
            'channel' => 'مرئية',
            'status' => 'مجدولة',
            'lawyer' => 'أ. خالد الشهري',
            'day' => '2026-09-10',
            'time' => '10:00 ص',
            'starts_at' => '2026-09-10 10:00:00',
            'duration_min' => 45,
        ]);

        $eventId = GoogleCalendarService::syncConsult($consult);

        $this->assertSame('gcal_event_9988', $eventId);
        $this->assertSame('gcal_event_9988', $consult->fresh()->google_event_id);
    }
}
