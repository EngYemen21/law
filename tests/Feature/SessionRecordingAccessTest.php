<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Permissions;
use App\Support\RecordingArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **مخرجات جلسات Zoom داخل النظام — لا رابطَ خارجيّ في أيّ زرّ** (قرار المالك 2026-09-15).
 *
 * 🔴 كانت أزرار «التسجيل المرئيّ» و«الصوت» و«رابط المشاركة» و«مشاهدة السحابة» تفتح سحابة Zoom
 * في نافذةٍ خارج النظام، وتصل المتصفّحَ روابطُ مشاهدةٍ قد تحمل رموز وصول. ولم يكن للموظّف
 * والمحامي مسارُ تنزيلٍ داخليّ لتسجيل استشارة — الرابط الخارجيّ وحده.
 *
 * الآن: البطاقات أعلامٌ لا روابط، والتشغيل والتنزيل عبر مسارات المكتب، والعميل لا يرى التسجيل.
 */
class SessionRecordingAccessTest extends TestCase
{
    use RefreshDatabase;

    private function endedConsult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-REC-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية',
            'session' => 'منتهية', 'tone' => 'b-green', 'lawyer' => 'مستشار',
            'meet_id' => '81823767754',
            'recording_url' => 'https://zoom.us/rec/play/secret-token',
            'zoom_share_url' => 'https://zoom.us/rec/share/public-link',
            'zoom_audio_url' => 'https://zoom.us/rec/play/audio-token',
        ], $extra));
    }

    private function storeMedia(Consult|Meeting $model, string $type = 'video'): void
    {
        Storage::disk('local')->put(RecordingArchive::localPath($model, $type), 'FAKE-MEDIA-BYTES');
    }

    /** @param  list<string>  $names */
    private function limitTo(User $user, array $names): void
    {
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $user->syncPermissions(Permission::whereIn('name', $names)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_staff_consult_card_carries_media_flags_not_zoom_links(): void
    {
        $consult = $this->endedConsult();

        $card = $consult->toCard();
        $this->assertStringNotContainsString('zoom.us', (string) json_encode($card), 'رابط سحابة Zoom يصل المتصفّح');
        $this->assertTrue($card['media']['video']);
        $this->assertTrue($card['media']['audio']);
        $this->assertFalse($card['media']['videoReady'], 'لا ملفّ على القرص بعد');

        $this->storeMedia($consult);
        $this->assertTrue($consult->fresh()->toCard()['media']['videoReady']);
    }

    public function test_the_client_card_carries_no_recording_at_all(): void
    {
        $card = $this->endedConsult()->toClientCard();

        $this->assertArrayNotHasKey('media', $card, 'العميل لا يرى تسجيل استشارته');
        $this->assertStringNotContainsString('zoom.us', (string) json_encode($card));
    }

    public function test_the_meeting_card_carries_media_flags_not_zoom_links(): void
    {
        $meeting = Meeting::create([
            'ref' => 'M-'.random_int(1000, 9999), 'title' => 'اجتماع منعقد', 'when_label' => 'أمس',
            'status' => 'منتهٍ', 'meet_id' => '82711433579',
            'recording_url' => 'https://zoom.us/rec/play/meeting-token',
            'zoom_audio_url' => 'https://zoom.us/rec/play/meeting-audio',
        ]);

        $card = $meeting->toFullCard();
        $this->assertStringNotContainsString('zoom.us', (string) json_encode($card));
        $this->assertTrue($card['recording']);
        $this->assertTrue($card['media']['audio']);
    }

    public function test_the_assigned_lawyer_plays_the_recording_inside_the_system(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(['assigned_lawyer_id' => $lawyer->id]);
        $this->storeMedia($consult);

        $res = $this->actingAs($lawyer)->get(route('lawyer.consults.stream', ['consult' => $consult, 'type' => 'video']));

        $res->assertOk();
        $this->assertStringContainsString('video/mp4', (string) $res->headers->get('content-type'));
        $this->assertStringStartsWith('inline', (string) $res->headers->get('content-disposition'), 'يُشغَّل ولا يُنزَّل');
    }

    public function test_another_lawyer_cannot_reach_the_recording(): void
    {
        $assigned = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->endedConsult(['assigned_lawyer_id' => $assigned->id]);
        $this->storeMedia($consult);

        $this->actingAs($other)->get(route('lawyer.consults.stream', ['consult' => $consult, 'type' => 'video']))->assertForbidden();
        $this->actingAs($other)->get(route('lawyer.consults.recording', $consult))->assertForbidden();
    }

    public function test_the_employee_downloads_through_the_server(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->limitTo($employee, ['استقبال الاستشارات', Permissions::PLAY_RECORDINGS]);
        $consult = $this->endedConsult();
        $this->storeMedia($consult, 'audio');

        $res = $this->actingAs($employee)->get(route('employee.consults.audio', $consult));

        $res->assertOk();
        $this->assertStringContainsString("audio-{$consult->ref}.m4a", (string) $res->headers->get('content-disposition'));
    }

    /**
     * **صلاحيّة القسم لا تفتح التسجيلات** (قرار المالك 2026-09-18): موظّف «استقبال الاستشارات»
     * بلا «تشغيل تسجيلات الجلسات» لا يشغّل ولا ينزّل ولا يقرأ النصّ — وبطاقته تقول «مقفل» لا «لا تسجيل».
     */
    public function test_the_section_permission_alone_does_not_open_the_recordings(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->limitTo($employee, ['استقبال الاستشارات']);
        $consult = $this->endedConsult();
        $this->storeMedia($consult);
        $this->storeMedia($consult, 'audio');

        foreach (['employee.consults.stream' => ['consult' => $consult, 'type' => 'video'], 'employee.consults.recording' => $consult, 'employee.consults.audio' => $consult, 'employee.consults.transcript' => $consult] as $route => $params) {
            $this->actingAs($employee)->get(route($route, $params))
                ->assertForbidden()
                ->assertSee('تشغيل تسجيلات الجلسات');
        }

        $this->actingAs($employee);
        $media = $consult->fresh()->toCard()['media'];
        $this->assertTrue($media['locked']);
        $this->assertFalse($media['video'] || $media['audio'] || $media['transcript'], 'لا زرّ يردّه الخادم');
    }

    public function test_a_file_not_yet_ready_never_redirects_to_zoom(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->endedConsult();

        $res = $this->actingAs($admin)->get(route('admin.consults.stream', ['consult' => $consult, 'type' => 'video']));

        $res->assertNotFound();
        $this->assertNull($res->headers->get('location'), 'لا تحويل إلى سحابة Zoom');
    }

    public function test_the_client_has_no_route_to_a_recording(): void
    {
        $consult = $this->endedConsult();
        $this->storeMedia($consult);
        $client = User::find($consult->user_id);

        foreach (['/employee', '/lawyer', '/admin'] as $base) {
            $res = $this->actingAs($client)->get("{$base}/consults/{$consult->id}/stream/video");
            $this->assertNotSame(200, $res->getStatusCode(), "العميل وصل التسجيل عبر {$base}");
        }
    }

    public function test_a_session_that_never_took_place_has_no_recording(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->endedConsult(['session' => 'بانتظار الجلسة', 'status' => 'بانتظار الجلسة']);

        $this->actingAs($admin)->get(route('admin.consults.recording', $consult))->assertNotFound();
    }

    /** **الحارس الأثمن:** لا شاشةَ تعرض رابط سحابة Zoom في زرّ. */
    public function test_no_screen_links_a_recording_to_the_zoom_cloud(): void
    {
        $files = [
            'js/lib/consult-ui.tsx', 'js/lib/meeting-ui.tsx', 'js/lib/recording-ui.tsx',
            'js/pages/admin/consults.tsx', 'js/pages/admin/meetlog.tsx', 'js/pages/admin/archive.tsx',
        ];

        foreach ($files as $rel) {
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', (string) file_get_contents(resource_path($rel)));

            foreach (['zoomShareUrl', 'zoomAudioUrl', 'href={c.recording}', 'href={m.recording}', 'href={a.recording}', 'drawerConsult.recording', 'drawerItem.recording'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "«{$needle}» في {$rel} يفتح سحابة Zoom خارج النظام");
            }
        }
    }
}
