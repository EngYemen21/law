<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Services\ZoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * توقيع Meeting SDK (م3): توليد JWT صحيح + تفويض النقطة (عميل مشارك، محامٍ مضيف+ZAK)،
 * وسدّ IDOR، والتدرّج (بلا اجتماع 422، بلا مفاتيح 503).
 */
class ZoomSdkSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function videoConsult(User $client, ?User $lawyer = null, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.uniqid(),
            'subject' => 'نزاع',
            'channel' => 'مرئية',
            'lawyer' => $lawyer?->name ?? 'محامٍ',
            'assigned_lawyer_id' => $lawyer?->id,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة',
            'meet_id' => '987654321', 'meet_password' => 'pw',
        ], $extra));
    }

    private function configureSdk(): void
    {
        config(['services.zoom.sdk_key' => 'SDKKEY', 'services.zoom.sdk_secret' => 'SDKSECRET']);
    }

    // ── الخدمة: JWT ──
    public function test_sdk_signature_is_null_without_keys(): void
    {
        config(['services.zoom.sdk_key' => null, 'services.zoom.sdk_secret' => null]);
        $this->assertNull(app(ZoomService::class)->sdkSignature('123', 0));
    }

    public function test_sdk_signature_encodes_valid_jwt(): void
    {
        $this->configureSdk();
        $jwt = app(ZoomService::class)->sdkSignature('987654321', 1);
        $this->assertNotNull($jwt);

        [$h, $p, $s] = explode('.', $jwt);
        $decode = fn ($seg) => json_decode(base64_decode(strtr($seg, '-_', '+/')), true);
        $header = $decode($h);
        $payload = $decode($p);

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('SDKKEY', $payload['appKey']);
        $this->assertSame('SDKKEY', $payload['sdkKey']);
        $this->assertSame('987654321', $payload['mn']);
        $this->assertSame(1, $payload['role']);
        $this->assertGreaterThanOrEqual(1800, $payload['exp'] - $payload['iat']);
        $this->assertSame($payload['exp'], $payload['tokenExp']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'SDKSECRET', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s);
    }

    // ── النقطة: التفويض والدور ──
    public function test_owner_client_gets_participant_signature(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client);

        $res = $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref]);
        $res->assertOk()
            ->assertJsonPath('role', 0)
            ->assertJsonPath('meetingNumber', '987654321')
            ->assertJsonPath('zak', null);
        $this->assertNotEmpty($res->json('signature'));
    }

    public function test_assigned_lawyer_gets_host_signature_with_zak(): void
    {
        $this->configureSdk();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/token*' => Http::response(['token' => 'ZAK123']),
        ]);

        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, $lawyer);

        $res = $this->actingAs($lawyer)->postJson(route('zoom.signature'), ['ref' => $consult->ref]);
        $res->assertOk()->assertJsonPath('role', 1)->assertJsonPath('zak', 'ZAK123');
    }

    public function test_lawyer_without_zak_scope_falls_back_to_participant(): void
    {
        Cache::flush();
        $this->configureSdk();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        // نطاق user:read:token غير مُفعّل ⇒ Zoom يرفض جلب ZAK (كود 4711)
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/token*' => Http::response(['code' => 4711, 'message' => 'no scope'], 400),
        ]);

        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, $lawyer);

        // بلا ZAK يُخفَّض المحامي إلى مشارك (role 0) بدل فشل الانضمام كمضيف
        $this->actingAs($lawyer)->postJson(route('zoom.signature'), ['ref' => $consult->ref])
            ->assertOk()
            ->assertJsonPath('role', 0)
            ->assertJsonPath('zak', null);
    }

    public function test_unassigned_lawyer_is_forbidden(): void
    {
        $this->configureSdk();
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, $lawyerA);

        $this->actingAs($lawyerB)->postJson(route('zoom.signature'), ['ref' => $consult->ref])->assertForbidden();
    }

    public function test_other_client_is_forbidden(): void
    {
        $this->configureSdk();
        $owner = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($owner);

        $this->actingAs($other)->postJson(route('zoom.signature'), ['ref' => $consult->ref])->assertForbidden();
    }

    public function test_consult_without_meeting_returns_422(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, null, ['meet_id' => null]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])->assertStatus(422);
    }

    public function test_sdk_not_configured_returns_503(): void
    {
        config(['services.zoom.sdk_key' => null, 'services.zoom.sdk_secret' => null]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])->assertStatus(503);
    }
}
