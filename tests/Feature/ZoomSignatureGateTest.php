<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بوابة canJoin على توقيع Meeting SDK (الدفعة 2): تعطيل الزر في الواجهة وحده يُلتفّ عليه
 * بطلب توقيع مباشر — الخادم بات يرفض التوقيع خارج نافذة الجلسة (403).
 */
class ZoomSignatureGateTest extends TestCase
{
    use RefreshDatabase;

    private function configureSdk(): void
    {
        config(['services.zoom.sdk_key' => 'SDKKEY', 'services.zoom.sdk_secret' => 'SDKSECRET']);
    }

    private function videoConsult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'محامٍ',
            'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس',
            'session' => 'بانتظار الجلسة', 'status' => 'موعد مؤكد',
            'meet_id' => '987654321', 'meet_password' => 'pw',
        ], $extra));
    }

    public function test_signature_denied_before_link_release(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        // موعد مستقبلي والرابط لم يُطلق بعد (قبل الموعد بـ5د) — الزر معطّل والخادم يصدّ الالتفاف
        $consult = $this->videoConsult($client, ['starts_at' => now()->addDay()]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])
            ->assertForbidden();
    }

    public function test_signature_denied_for_missed_consult_even_with_released_link(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        // أُطلق الرابط لكن الموعد فات نافذته (المدة + 30د) دون انعقاد
        $consult = $this->videoConsult($client, [
            'starts_at' => now()->subHours(3), 'duration_min' => 45,
            'link_released_at' => now()->subHours(4),
        ]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])
            ->assertForbidden();
    }

    public function test_signature_granted_for_live_session(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, ['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])
            ->assertOk()
            ->assertJsonPath('meetingNumber', '987654321');
    }

    public function test_signature_denied_for_meeting_past_its_window(): void
    {
        $this->configureSdk();
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-8100', 'title' => 'اجتماع فائت',
            'when_label' => 'أمس · 10:00', 'status' => 'قادم',
            'starts_at' => now()->subHours(3), 'dur' => '60 دقيقة',
            'meet_id' => '81823767754', 'meet_password' => 'mp',
        ]);

        // «قادم» المخزّنة فات موعدها ⇒ liveState «لم ينعقد» ⇒ لا توقيع
        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $meeting->ref, 'kind' => 'meeting'])
            ->assertForbidden();
    }

    public function test_signature_gate_runs_before_sdk_configuration_check(): void
    {
        // بلا مفاتيح SDK: الجلسة الفائتة تُرفض 403 (البوابة أولاً) لا 503 — رسالة أدقّ للمستخدم
        config(['services.zoom.sdk_key' => null, 'services.zoom.sdk_secret' => null]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->videoConsult($client, ['starts_at' => now()->subHours(3), 'duration_min' => 45]);

        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $consult->ref])
            ->assertForbidden();
    }
}
