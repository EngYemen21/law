<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\ZoomService;
use App\Support\DecisionTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * م5: التسجيل السحابي + النصّ المحفوظ محلياً + المدّة/الدخول/الخروج + المهام التلقائية من القرارات.
 */
class ZoomRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const WH = 'whsec_rec';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zoom.webhook_secret' => self::WH]);
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
    }

    private function postSigned(array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp; // ختم حديث (فحص منع إعادة الإرسال)
        $sig = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::WH);

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function consult(?User $lawyer = null, array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => $lawyer?->name ?? 'محامٍ',
            'assigned_lawyer_id' => $lawyer?->id, 'day' => 'اليوم', 'time' => '11:00', 'when_label' => 'اليوم', 'session' => 'منتهية',
            'meet_id' => '55500011122',
        ], $extra));
    }

    public function test_download_transcript_cleans_vtt(): void
    {
        Http::fake(['*' => Http::response("WEBVTT\n\n1\n00:00:01.000 --> 00:00:03.000\nمرحبا\n\n2\n00:00:03.000 --> 00:00:05.000\nبخير شكرا")]);

        $text = app(ZoomService::class)->downloadTranscript('https://z/t.vtt', 'TOK');

        $this->assertSame("مرحبا\nبخير شكرا", $text);
    }

    public function test_recording_completed_stores_url_and_local_transcript(): void
    {
        Storage::fake('local');
        Http::fake([
            'z/transcript.vtt' => Http::response("WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nوقائع الجلسة"),
        ]);
        $consult = $this->consult();

        $this->postSigned([
            'event' => 'recording.completed',
            'download_token' => 'DLTOK',
            'payload' => ['object' => ['id' => '55500011122', 'recording_files' => [
                ['file_type' => 'MP4', 'play_url' => 'https://zoom/rec/play'],
                ['file_type' => 'TRANSCRIPT', 'download_url' => 'https://z/transcript.vtt'],
            ]]],
        ])->assertOk();

        $consult->refresh();
        $this->assertSame('https://zoom/rec/play', $consult->recording_url);
        $this->assertNotNull($consult->transcript_path);
        Storage::disk('local')->assertExists($consult->transcript_path);
        $this->assertSame('وقائع الجلسة', Storage::disk('local')->get($consult->transcript_path));
    }

    public function test_recording_completed_works_for_meetings_too(): void
    {
        Storage::fake('local');
        Http::fake(['z/m.vtt' => Http::response("WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nمحضر")]);
        $meeting = Meeting::create([
            'ref' => 'M-'.uniqid(), 'title' => 'اجتماع', 'type' => 'اجتماع', 'when_label' => 'اليوم',
            'status' => 'منتهٍ', 'meet_id' => '77700033344',
        ]);

        $this->postSigned([
            'event' => 'recording.completed', 'download_token' => 'T',
            'payload' => ['object' => ['id' => '77700033344', 'recording_files' => [
                ['file_type' => 'MP4', 'download_url' => 'https://zoom/m/play'],
                ['file_type' => 'TRANSCRIPT', 'download_url' => 'https://z/m.vtt'],
            ]]],
        ])->assertOk();

        $meeting->refresh();
        $this->assertSame('https://zoom/m/play', $meeting->recording_url);
        Storage::disk('local')->assertExists($meeting->transcript_path);
    }

    public function test_participant_events_capture_join_leave_duration(): void
    {
        $consult = $this->consult();

        $this->postSigned([
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => ['id' => '55500011122', 'participant' => ['join_time' => '2026-07-17T10:00:00Z']]],
        ])->assertOk();
        $this->postSigned([
            'event' => 'meeting.participant_left',
            'payload' => ['object' => ['id' => '55500011122', 'participant' => ['leave_time' => '2026-07-17T10:30:00Z']]],
        ])->assertOk();

        $consult->refresh();
        $this->assertNotNull($consult->join_time);
        $this->assertNotNull($consult->leave_time);
        $this->assertSame(1800, $consult->duration_sec); // 30 دقيقة
    }

    /**
     * الويبهوك يُنتج **اقتراحات** لا مهامّ — تغيير عقد مقصود (المرحلة P3).
     *
     * كان ملخّص Zoom يُنشئ مهامّ لدى المحامي تلقائياً: أي أن نصّاً استخرجه نموذج
     * يُنشئ التزاماً على إنسان بلا أن يقرّه أحد، ملتفّاً حول زرّ الاعتماد الموجود
     * أصلاً (`createTasks`). الاقتراح يُحفظ الآن وينتظر ذلك الزرّ.
     */
    public function test_summary_completed_suggests_tasks_without_creating_them(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        // قرارات حقيقية مبذورة (مصدرها AI في الإنتاج) — لا اعتماد على نصّ احتياطي وهمي
        $consult = $this->consult($lawyer, ['decisions' => ['توجيه إنذار رسمي', 'تجهيز مذكرة الدعوى']]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_overview' => 'ملخّص', 'summary_details' => [], 'next_steps' => ['توجيه إنذار'],
            ]),
        ]);

        $this->postSigned([
            'event' => 'meeting.summary_completed',
            'payload' => ['object' => ['id' => '55500011122']],
        ])->assertOk();

        $consult->refresh();
        $this->assertFalse((bool) $consult->tasks_created, 'الويبهوك لا يعتمد شيئاً نيابةً عن إنسان');
        $this->assertSame(0, Task::count(), 'لا مهمّة تُنشأ بلا اعتماد');
        $this->assertSame(['توجيه إنذار رسمي', 'تجهيز مذكرة الدعوى'], $consult->suggested_tasks);

        // تشغيل ثانٍ لا يضاعف الاقتراحات (idempotent)
        $this->postSigned(['event' => 'meeting.summary_completed', 'payload' => ['object' => ['id' => '55500011122']]])->assertOk();
        $this->assertCount(2, $consult->fresh()->suggested_tasks);

        // والاعتماد البشريّ وحده يُنشئ المهامّ
        DecisionTasks::create($consult->fresh(), app(LegalAiService::class), $lawyer);
        $this->assertSame(2, Task::where('assigned_to', $lawyer->id)->count());
        $this->assertTrue((bool) $consult->fresh()->tasks_created);
    }
}
