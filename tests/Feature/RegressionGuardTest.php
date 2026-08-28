<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\BuildRecordingArchive;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use App\Support\RecordingArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * حرّاس انحدار: كل اختبار هنا يمثّل وظيفة كُسرت فعلاً وكشفها تدقيق عميق،
 * ولم تكن أيّ من الاختبارات القائمة تمسّها.
 */
class RegressionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function executionAtStage(int $stage, int $fee = 3000): Execution
    {
        return Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EXE-R-'.random_int(1000, 9999), 'subject' => 'تنفيذ حكم',
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
            'stage' => $stage, 'fee' => $fee,
        ]);
    }

    /** الواجهة ترسل fee=0 حين يُترك حقل «تعديل الأتعاب (اختياري)» فارغاً — وهو المسار الطبيعي. */
    public function test_approve_fee_accepts_zero_as_keep_current_amount(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $execution = $this->executionAtStage(4, 3000);

        $this->actingAs($admin)->post(route('exec-flow.act', $execution), [
            'action' => 'approveFee', 'fee' => 0,
        ])->assertSessionHasNoErrors();

        $fresh = $execution->fresh();
        $this->assertTrue((bool) $fresh->fee_approved, 'العرض لم يُعتمد رغم إرسال fee=0');
        $this->assertSame(3000, (int) $fresh->fee, 'الأتعاب الأصلية لم تُحفظ');
    }

    public function test_approve_fee_still_rejects_negative_amount(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $execution = $this->executionAtStage(4);

        $this->actingAs($admin)->post(route('exec-flow.act', $execution), [
            'action' => 'approveFee', 'fee' => -5000,
        ])->assertSessionHasErrors('fee');
    }

    /** غرفة العميل محروسة بـrole:client — إعادتها لبقية الأدوار زرٌّ يطرد صاحبه. */
    public function test_session_link_points_each_role_to_its_own_room(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-R-1', 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
        ]);

        $this->assertStringContainsString('/consults/room', $consult->joinLink($client));
        $this->assertStringContainsString('/lawyer/videoroom', $consult->joinLink(User::factory()->create(['role' => Role::Lawyer])));
        $this->assertStringContainsString('/employee/videoroom', $consult->joinLink(User::factory()->create(['role' => Role::Employee])));
        $this->assertStringContainsString('/admin/videoroom', $consult->joinLink(User::factory()->create(['role' => Role::Admin])));
    }

    public function test_appointment_card_gives_employee_a_room_it_may_actually_open(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $appointment = Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-R-1', 'type' => 'استشارة مرئية', 'ico' => 'video',
            'lawyer' => 'أ. سارة', 'day' => 'غد', 'time' => '10:00', 'starts_at' => now()->addDay(),
            'duration_min' => 45, 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
        Consult::create([
            'user_id' => $client->id, 'appointment_id' => $appointment->id, 'ref' => 'CN-R-2',
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة',
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
        ]);

        $card = $appointment->fresh()->toCard($employee);

        $this->assertStringContainsString('/employee/videoroom', (string) $card['joinLink']);
        $this->assertStringNotContainsString('/consults/room', (string) $card['joinLink']);
    }

    private function fakeZoom(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/recordings' => Http::response([
                'download_access_token' => 'DLTOK',
                'recording_files' => [[
                    'recording_type' => 'shared_screen_with_speaker_view', 'file_extension' => 'MP4',
                    'download_url' => 'https://zoom.us/rec/download/v1', 'play_url' => 'https://zoom.us/rec/play/v1',
                ]],
            ]),
            'zoom.us/rec/download/*' => Http::response('VIDEO-BYTES'),
        ]);
    }

    /** يُكتب بالتدفّق: القراءة الكاملة كانت تُسقط المهمة على أرشيف بمئات الميغابايت. */
    public function test_archive_is_written_to_disk_and_readable(): void
    {
        Storage::fake('local');
        $this->fakeZoom();
        $meeting = Meeting::create([
            'ref' => 'M-R-1', 'title' => 'اجتماع', 'when_label' => 'أمس',
            'status' => 'منتهٍ', 'meet_id' => '82711433579',
        ]);

        $path = RecordingArchive::build($meeting, 'video');

        $this->assertSame("recordings/meeting-{$meeting->ref}-video.mp4", $path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertGreaterThan(0, strlen((string) Storage::disk('local')->get($path)));
    }

    /** المجدول يعمل كل 15 دقيقة — بلا كابح يُعاد صفّ الأرشيف الفاشل 96 مرة يومياً. */
    public function test_failed_archive_is_not_requeued_immediately(): void
    {
        $meeting = Meeting::create([
            'ref' => 'M-R-2', 'title' => 'اجتماع', 'when_label' => 'أمس', 'status' => 'منتهٍ', 'meet_id' => '999',
        ]);

        $this->assertFalse(BuildRecordingArchive::recentlyFailed($meeting, 'video'));

        Cache::put('recording-archive-failed:'.$meeting::class.':'.$meeting->getKey().':video', true, now()->addHours(6));

        $this->assertTrue(BuildRecordingArchive::recentlyFailed($meeting, 'video'));
    }

    /** يكفي أن يطابق أحد السجلّين — الشرط السابق كان يستوجب كليهما فيحجب المالك. */
    public function test_assistant_accepts_reference_when_one_record_matches(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-R-9', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->postJson(route('lawyer.assistant.generate'), [
            'kind' => 'analyze', 'docType' => 'تحليل', 'ref' => 'CASE-R-9',
        ])->assertOk();
    }

    /** CaseMessage::booted يبثّ عند الإنشاء — البثّ اليدوي كان يضاعف الحركة. */
    public function test_lawyer_case_reply_creates_exactly_one_message(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-R-10', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.reply', $case), ['body' => 'تمت المراجعة.'])
            ->assertNoContent();

        $this->assertSame(1, $case->messages()->where('who', 'lawyer')->count());
    }
}
