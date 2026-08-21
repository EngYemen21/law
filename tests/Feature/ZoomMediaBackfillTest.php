<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\BuildRecordingArchive;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\RecordingArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * تنزيل مخرجات جلسات الاجتماعات (فيديو/صوت ZIP + نص تفريغي) وأمر استكمال بيانات
 * التسجيلات zoom:pull-recordings — شبكة الأمان حين لا يصل ويبهوك التسجيلات.
 */
class ZoomMediaBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function configureS2S(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    /** تزييف سحابة Zoom كاملة: اجتماع ماضٍ بتسجيل فيديو وصوت ونص تفريغي */
    private function fakeZoomCloud(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/*/participants*' => Http::response(['participants' => []]),
            'api.zoom.us/v2/past_meetings/*' => Http::response([
                'uuid' => 'UUID==', 'start_time' => '2026-08-18T10:00:00Z', 'end_time' => '2026-08-18T10:45:00Z',
            ]),
            'api.zoom.us/v2/meetings/*/recordings' => Http::response([
                'download_access_token' => 'DLTOK',
                'share_url' => 'https://zoom.us/rec/share/xyz',
                'recording_files' => [
                    ['recording_type' => 'shared_screen_with_speaker_view', 'file_extension' => 'MP4', 'download_url' => 'https://zoom.us/rec/download/video1', 'play_url' => 'https://zoom.us/rec/play/video1'],
                    ['recording_type' => 'audio_only', 'file_extension' => 'M4A', 'download_url' => 'https://zoom.us/rec/download/audio1', 'play_url' => 'https://zoom.us/rec/play/audio1'],
                    ['recording_type' => 'audio_transcript', 'file_extension' => 'VTT', 'download_url' => 'https://zoom.us/rec/download/vtt1'],
                ],
            ]),
            'zoom.us/rec/download/video1*' => Http::response('VIDEO-BYTES'),
            'zoom.us/rec/download/audio1*' => Http::response('AUDIO-BYTES'),
            'zoom.us/rec/download/vtt1*' => Http::response("WEBVTT\n\n1\n00:00:01.000 --> 00:00:04.000\nمرحباً بكم في الجلسة\n"),
        ]);
    }

    private function endedMeeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-'.random_int(1000, 9999), 'title' => 'اجتماع منعقد', 'when_label' => 'أمس',
            'status' => 'منتهٍ', 'meet_id' => '82711433579',
        ], $extra));
    }

    /**
     * الدورة الكاملة: النقرة الأولى تُجدول البناء في الطابور (لا تنتظر سحابة Zoom داخل
     * طلب HTTP — كان ذلك 504 حتمياً خلف nginx)، وبعد تنفيذ المهمة ينزل الملف فوراً من القرص.
     */
    public function test_first_click_queues_the_build_then_download_is_served_locally(): void
    {
        Storage::fake('local');
        $this->configureS2S();
        $this->fakeZoomCloud();
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->endedMeeting();

        // النقرة الأولى: لا أرشيف محلي بعد ⇒ تُجدوَل المهمة ويُعاد المستخدم برسالة تحضير
        $this->actingAs($admin)->get(route('admin.meetings.recording', $meeting))
            ->assertRedirect()
            ->assertSessionHas('flash');
        Queue::assertPushed(BuildRecordingArchive::class);
    }

    public function test_built_archive_is_downloaded_instantly_with_correct_contents(): void
    {
        Storage::fake('local');
        $this->configureS2S();
        $this->fakeZoomCloud();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->endedMeeting();

        // تنفيذ البناء (كما يفعله عامل الطابور أو المجدول الاستباقي)
        $this->assertSame("recordings/meeting-{$meeting->ref}-video.zip", RecordingArchive::build($meeting, 'video'));
        $this->assertSame("recordings/meeting-{$meeting->ref}-audio.zip", RecordingArchive::build($meeting, 'audio'));
        $this->assertTrue(RecordingArchive::isReady($meeting, 'video'));

        // الفيديو — يُخدَم من القرص فوراً بمحتوى صحيح
        $res = $this->actingAs($admin)->get(route('admin.meetings.recording', $meeting));
        $res->assertOk()->assertDownload('recording-'.$meeting->ref.'.zip');
        $this->assertSame('VIDEO-BYTES', $this->zipEntry($meeting, 'video', 'mp4'));

        // الصوت (يلتقط ملف audio_only لا الفيديو)
        $this->actingAs($admin)->get(route('admin.meetings.audio', $meeting))
            ->assertOk()->assertDownload('audio-'.$meeting->ref.'.zip');
        $this->assertSame('AUDIO-BYTES', $this->zipEntry($meeting, 'audio', 'm4a'));
    }

    /** يقرأ محتوى مُدخل داخل الأرشيف المحفوظ محلياً. */
    private function zipEntry(Meeting $meeting, string $type, string $ext): string|false
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, Storage::disk('local')->get(RecordingArchive::localPath($meeting, $type)));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $content = $zip->getFromName("meeting-{$meeting->ref}.{$ext}");
        $zip->close();
        @unlink($tmp);

        return $content;
    }

    public function test_meeting_transcript_is_fetched_from_cloud_and_cached_locally(): void
    {
        Storage::fake('local');
        $this->configureS2S();
        $this->fakeZoomCloud();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->endedMeeting(); // بلا transcript_path محلي

        $this->actingAs($admin)->get(route('admin.meetings.transcript', $meeting))
            ->assertOk()
            ->assertDownload('transcript-'.$meeting->ref.'.txt');

        // جُلب ونُظّف من ترويسة VTT وخُزّن محلياً وحُدّث السجل — التنزيل التالي محلي مباشرة
        $meeting->refresh();
        $this->assertSame("transcripts/meeting-{$meeting->ref}.txt", $meeting->transcript_path);
        $text = Storage::disk('local')->get($meeting->transcript_path);
        $this->assertStringContainsString('مرحباً بكم في الجلسة', $text);
        $this->assertStringNotContainsString('WEBVTT', $text);
    }

    public function test_any_employee_may_download_meeting_media(): void
    {
        $this->configureS2S();
        $this->fakeZoomCloud();
        $employee = User::factory()->create(['role' => Role::Employee]);
        $meeting = $this->endedMeeting();

        // مكتب واحد بلا فروع: تسجيلات الجلسات متاحة لكل موظفي المكتب
        // (الأرشيف يُبنى مسبقاً كما يفعل المجدول، فالتنزيل فوريّ)
        RecordingArchive::build($meeting, 'video');
        RecordingArchive::build($meeting, 'audio');
        $this->actingAs($employee)->get(route('employee.meetings.recording', $meeting))->assertOk();
        $this->actingAs($employee)->get(route('employee.meetings.audio', $meeting))->assertOk();
    }

    public function test_pull_recordings_backfills_missing_media_fields(): void
    {
        Storage::fake('local');
        $this->configureS2S();
        $this->fakeZoomCloud();
        $client = User::factory()->create(['role' => Role::Client]);

        // اجتماع منعقد بلا أي بيانات تسجيل (الويبهوك لم يصل) واستشارة منتهية مثله
        $meeting = $this->endedMeeting();
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-9001', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس',
            'session' => 'منتهية', 'status' => 'منتهية', 'meet_id' => '84220475264',
        ]);
        // اجتماع بلا معرّف Zoom — لا يُستطلع أصلاً
        $noZoom = Meeting::create(['ref' => 'M-0000', 'title' => 'يدوي', 'when_label' => 'أمس', 'status' => 'منتهٍ']);

        $this->artisan('zoom:pull-recordings')->assertExitCode(0);

        $meeting->refresh();
        $this->assertSame('https://zoom.us/rec/play/video1', $meeting->recording_url);
        $this->assertSame('https://zoom.us/rec/play/audio1', $meeting->zoom_audio_url);
        $this->assertSame('https://zoom.us/rec/share/xyz', $meeting->zoom_share_url);
        $this->assertSame('UUID==', $meeting->zoom_uuid);
        $this->assertSame("transcripts/meeting-{$meeting->ref}.txt", $meeting->transcript_path);
        $this->assertTrue(Storage::disk('local')->exists($meeting->transcript_path));

        $consult->refresh();
        $this->assertSame('https://zoom.us/rec/play/video1', $consult->recording_url);
        $this->assertSame("transcripts/consult-{$consult->ref}.txt", $consult->transcript_path);

        $this->assertNull($noZoom->fresh()->recording_url);
    }

    public function test_pull_recordings_does_not_overwrite_existing_fields(): void
    {
        Storage::fake('local');
        $this->configureS2S();
        $this->fakeZoomCloud();
        $meeting = $this->endedMeeting(['recording_url' => 'https://existing/rec', 'zoom_uuid' => 'KEEP==']);

        $this->artisan('zoom:pull-recordings')->assertExitCode(0);

        $meeting->refresh();
        $this->assertSame('https://existing/rec', $meeting->recording_url); // لم يُدهس
        $this->assertSame('KEEP==', $meeting->zoom_uuid);
        $this->assertSame('https://zoom.us/rec/play/audio1', $meeting->zoom_audio_url); // الناقص فقط أُكمل
    }
}
