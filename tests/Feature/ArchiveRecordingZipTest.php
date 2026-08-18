<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * تنزيل فيديو الاستشارة المؤرشفة ملفاً مضغوطاً (ZIP) — الأرشيف يعرض بيانات حقيقية وزرّ التنزيل
 * يجلب MP4 من سحابة Zoom عبر الخادم (روابط play_url صفحات مشاهدة لا ملفات) ويضغطه ويبثّه.
 */
class ArchiveRecordingZipTest extends TestCase
{
    use RefreshDatabase;

    private function endedConsult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع تجاري', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة',
            'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس · 10ص',
            'session' => 'منتهية', 'status' => 'منتهية',
        ], $extra));
    }

    private function configureS2S(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    public function test_archive_rows_expose_zip_link_for_recorded_consults(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $recorded = $this->endedConsult($client, ['meet_id' => '81823767754', 'recording_url' => 'https://zoom.us/rec/play/abc']);
        $this->endedConsult($client, ['ref' => 'CN-2026-0002']); // بلا اجتماع ولا تسجيل

        $this->actingAs($admin)->get(route('admin.archive'))
            ->assertInertia(fn ($p) => $p
                ->has('rows', 2)
                ->where('rows.1.zip', route('admin.consults.recording', $recorded, absolute: false))
                ->where('rows.0.zip', null));
    }

    public function test_admin_downloads_recording_as_zip_via_zoom_api(): void
    {
        $this->configureS2S();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/recordings' => Http::response([
                'download_access_token' => 'DLTOK',
                'recording_files' => [[
                    'recording_type' => 'shared_screen_with_speaker_view',
                    'file_extension' => 'MP4',
                    'download_url' => 'https://zoom.us/rec/download/abc',
                    'play_url' => 'https://zoom.us/rec/play/abc',
                ]],
            ]),
            'zoom.us/rec/download/*' => Http::response('FAKE-MP4-BYTES'),
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->endedConsult($client, ['meet_id' => '81823767754']);

        $res = $this->actingAs($admin)->get(route('admin.consults.recording', $consult));

        $res->assertOk();
        $this->assertStringContainsString("recording-{$consult->ref}.zip", (string) $res->headers->get('content-disposition'));

        // ملف ZIP حقيقي يحوي فيديو الجلسة بالبايتات المجلوبة من Zoom
        $path = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame('FAKE-MP4-BYTES', $zip->getFromName("consult-{$consult->ref}.mp4"));
        $zip->close();
        @unlink($path);

        // طلب التنزيل من Zoom حمل رمز التنزيل
        Http::assertSent(fn ($r) => str_contains($r->url(), '/rec/download/abc') && str_contains($r->url(), 'access_token=DLTOK'));
    }

    public function test_download_falls_back_to_stored_direct_url_without_api(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);
        Http::fake(['zoom.us/rec/download/*' => Http::response('DIRECT-BYTES')]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->endedConsult($client, ['recording_url' => 'https://zoom.us/rec/download/direct123']);

        $res = $this->actingAs($admin)->get(route('admin.consults.recording', $consult));

        $res->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame('DIRECT-BYTES', $zip->getFromName("consult-{$consult->ref}.mp4"));
        $zip->close();
        @unlink($path);
    }

    public function test_download_unavailable_returns_404(): void
    {
        config(['services.zoom.account_id' => null, 'services.zoom.client_id' => null, 'services.zoom.client_secret' => null]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // منتهية لكن بلا اجتماع ولا رابط تنزيل مباشر (play_url صفحة مشاهدة لا ملف)
        $noSource = $this->endedConsult($client, ['recording_url' => 'https://zoom.us/rec/play/watch-only']);
        $this->actingAs($admin)->get(route('admin.consults.recording', $noSource))->assertNotFound();

        // جلستها لم تنعقد أصلاً
        $notEnded = $this->endedConsult($client, ['session' => 'بانتظار الجلسة', 'meet_id' => '123']);
        $this->actingAs($admin)->get(route('admin.consults.recording', $notEnded))->assertNotFound();
    }

    public function test_non_admin_cannot_reach_archive_download(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->endedConsult($client, ['meet_id' => '123']);

        // حارس الدور يصدّ غير الإدارة عن مسار /admin
        $res = $this->actingAs($client)->get(route('admin.consults.recording', $consult));
        $this->assertNotSame(200, $res->getStatusCode());
    }
}
