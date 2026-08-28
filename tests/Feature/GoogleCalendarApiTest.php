<?php

namespace Tests\Feature;

use App\Models\Consult;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_detects_configuration(): void
    {
        $this->assertTrue(GoogleCalendarService::isConfigured());
    }

    public function test_create_and_sync_consult_event(): void
    {
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
