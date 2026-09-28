<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\ZoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تكامل Zoom + أمان الروابط:
 * - createMeeting يحلّل [id, join_url, start_url] عند النجاح، وnull بلا مفاتيح (فيظهر الاحتياطي).
 * - الاجتماع السرّي يفعّل غرفة الانتظار.
 * - رابط المضيف (start_url/host_link) لا يتسرّب أبداً لصفحات العميل.
 */
class ZoomIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function configureZoom(): void
    {
        config([
            'services.zoom.account_id' => 'acc',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'sec',
        ]);
    }

    public function test_create_meeting_returns_parsed_links_on_success(): void
    {
        $this->configureZoom();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok-123'], 200),
            'api.zoom.us/v2/*' => Http::response([
                'id' => 987654321,
                'join_url' => 'https://us05web.zoom.us/j/987654321?pwd=abc',
                'start_url' => 'https://us05web.zoom.us/s/987654321?zak=SECRET',
            ], 201),
        ]);

        $out = app(ZoomService::class)->createMeeting('استشارة اختبار');

        $this->assertNotNull($out);
        $this->assertSame('987654321', $out['id']);
        $this->assertStringContainsString('zoom.us/j/', $out['join_url']);
        $this->assertStringContainsString('zak=', $out['start_url']);
    }

    public function test_create_meeting_null_without_keys(): void
    {
        // بلا مفاتيح (phpunit يصفّرها) → null، فيستخدم المُستدعي الرابط الاحتياطي
        $this->assertNull(app(ZoomService::class)->createMeeting('بلا مفاتيح'));
    }

    public function test_confidential_meeting_enables_waiting_room(): void
    {
        $this->configureZoom();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok'], 200),
            'api.zoom.us/v2/*' => Http::response(['id' => 1, 'join_url' => 'j', 'start_url' => 's'], 201),
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع سري', 'type' => 'اجتماع مع عميل', 'conf' => 'سري',
            'client_id' => $client->id, 'day' => now()->addDays(3)->toDateString(), 'time' => '10:00',
        ])->assertRedirect();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v2/users/me/meetings')
            && ($req['settings']['waiting_room'] ?? null) === true
            && ($req['settings']['join_before_host'] ?? null) === false);
    }

    public function test_host_link_never_leaks_to_client_in_myconsults(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-7001', 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'day' => 'الأحد', 'time' => '10ص',
            'when_label' => 'الأحد 10ص', 'price' => 450, 'vat' => 68, 'total' => 518,
            'session' => 'بانتظار الجلسة', 'status' => 'قيد الاستشارة',
            'meet_link' => 'https://us05web.zoom.us/j/1?pwd=x',
            'host_link' => 'https://us05web.zoom.us/s/1?zak=HOSTSECRET',
            'ai_summary' => 'تحليل داخلي سرّي', 'employee' => 'منيرة الحربي',
        ]);

        $this->actingAs($client)->get(route('myconsults'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('myconsults')
            ->where('consults.0.slink', url('/consults/room?ref=CN-2026-7001')) // رابط Zoom الخام لا يصل العميل — غرفة المنصة فقط
            ->missing('consults.0.hostLink')
            ->missing('consults.0.aiSummary')
            ->missing('consults.0.employee')
            ->missing('consults.0.audit'));
    }

    /** تبويب الدعوات طُوي؛ رابط المضيف يُفحَص الآن في شاشة الاجتماعات. */
    public function test_host_link_never_leaks_to_client_in_meetreqs(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-7700', 'service' => 'نزاع',
            'type' => 'استشارة مرئية', 'day' => 'الأحد', 'time' => '10ص',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_CONFIRMED,
            'meet_link' => 'https://us05web.zoom.us/j/2?pwd=y',
            'host_link' => 'https://us05web.zoom.us/s/2?zak=HOSTSECRET2',
        ]);

        // تبويب الدعوات طُوي؛ المسار يُحوّل إلى «الاجتماعات» — وهناك يُفحص التسريب
        $this->actingAs($client)->get(route('meetreqs'))->assertRedirect(route('meetings'));

        $this->actingAs($client)->get(route('meetings'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('meetings')
            ->missing('meetings.0.hostLink'));
    }
}
