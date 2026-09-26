<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\EventStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مجدولات الدفعة 3 — مثبِّتات القاعدة (العرض الفوري تكفله liveState/isMissed/isLapsed):
 * حسم الاستشارات الفائتة، وحسم الاجتماعات التي لم تبدأ (والتي بدأت لا يُنهيها إلّا إنهاؤها —
 * قرار المالك 2026-09-26)، وجلسات القضايا الفائتة.
 */
class SchedulerAutoCloseTest extends TestCase
{
    use RefreshDatabase;

    private function consult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'محامٍ',
            'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس',
            'session' => 'بانتظار الجلسة', 'status' => 'موعد مؤكد',
        ], $extra));
    }

    public function test_auto_close_missed_consults_marks_only_stale_waiting_sessions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $stale = $this->consult($client, ['starts_at' => now()->subHours(13)]);
        $recent = $this->consult($client, ['starts_at' => now()->subHours(2)]);   // فائتة لكن لم تبلغ 12 ساعة
        $live = $this->consult($client, ['starts_at' => now()->subHours(13), 'session' => 'جلسة جارية']);
        $unscheduled = $this->consult($client, ['starts_at' => null, 'status' => 'بانتظار التسعير']);

        $this->artisan('consults:auto-close-missed')->assertExitCode(0);

        $this->assertSame(['لم تُعقد', 'لم يحضر'], [$stale->fresh()->session, $stale->fresh()->status]);
        $this->assertSame('بانتظار الجلسة', $recent->fresh()->session);
        $this->assertSame('جلسة جارية', $live->fresh()->session);
        $this->assertSame('بانتظار التسعير', $unscheduled->fresh()->status);
        // أُشعر عميل الفائتة فقط
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        // القيد موثّق بسجل التدقيق باسم النظام
        $this->assertSame('النظام', $stale->fresh()->audit[0]['user']);
    }

    /**
     * **المجدول يحسم ما لم يبدأ وحده** (قرار المالك 2026-09-26). كان يختم «منتهٍ» كلَّ قادمٍ دخله
     * أحدٌ بعد ١٢ ساعة — نهايةٌ تقرّرها الساعة. الاجتماع الذي بدأ لا ينتهي إلّا بإنهائه، والمنسيّ
     * منه تُنهيه شبكة النسيان بعد مهلتها (`sessions:close-stale` · `SessionZoomClosureTest`).
     */
    public function test_auto_close_meetings_misses_unjoined_and_leaves_started_ones(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        // فات ودخل أحد ⇒ بدأ فعلاً — لا يحسمه هذا المجدول
        $joined = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9001', 'title' => 'انعقد بلا إنهاء', 'when_label' => 'أمس',
            'status' => 'قادم', 'starts_at' => now()->subHours(13), 'join_time' => now()->subHours(13),
        ]);
        $joinedReq = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $joined->id, 'ref' => 'MR-9001',
            'service' => 'خدمة', 'type' => 'استشارة مرئية', 'day' => 'أمس', 'time' => '10:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        // فات ولم يدخل أحد ⇒ لم ينعقد ودعوته «منتهية الصلاحية» (يُتاح إعادة إرسالها)
        $missed = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9002', 'title' => 'لم يحضر أحد', 'when_label' => 'أمس',
            'status' => 'بانتظار التأكيد', 'starts_at' => now()->subHours(13),
        ]);
        $missedReq = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $missed->id, 'ref' => 'MR-9002',
            'service' => 'خدمة', 'type' => 'استشارة مرئية', 'day' => 'أمس', 'time' => '10:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        // قادم حديث — لا يُمسّ
        $upcoming = Meeting::create([
            'ref' => 'M-9003', 'title' => 'قادم', 'when_label' => 'غداً',
            'status' => 'قادم', 'starts_at' => now()->addDay(),
        ]);

        $this->artisan('zoom:auto-close-missed')->assertExitCode(0);

        $this->assertSame('قادم', $joined->fresh()->status);
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $joinedReq->fresh()->stage);

        $this->assertSame('لم ينعقد', $missed->fresh()->status);
        $this->assertSame(MeetRequest::STAGE_EXPIRED, $missedReq->fresh()->stage);
        // وحسمه انتقالٌ مسجَّل لا كتابةٌ صامتة
        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Meeting', 'entity_id' => $missed->id, 'transition' => 'meeting.missed',
        ]);

        $this->assertSame('قادم', $upcoming->fresh()->status);
    }

    public function test_auto_close_meetings_never_ends_a_live_meeting(): void
    {
        // كانت «جارٍ» تُختم بعد «المدة + 3 ساعات» — الآن لا يمسّها هذا المجدول مهما طالت
        $longLive = Meeting::create([
            'ref' => 'M-9010', 'title' => 'جارٍ طويل', 'when_label' => 'أمس',
            'status' => 'جارٍ', 'starts_at' => now()->subHours(5), 'dur' => '60 دقيقة',
        ]);

        $this->artisan('zoom:auto-close-missed')->assertExitCode(0);

        $this->assertSame('جارٍ', $longLive->fresh()->status);
    }

    public function test_auto_lapse_hearings_marks_and_notifies_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id,
        ]);
        $stale = $case->hearings()->create(['title' => 'جلسة فائتة', 'day' => 'قبل يومين', 'status' => 'مجدولة', 'starts_at' => now()->subDays(2)]);
        $recent = $case->hearings()->create(['title' => 'فائتة حديثاً', 'day' => 'اليوم', 'status' => 'مجدولة', 'starts_at' => now()->subHours(3)]);
        $future = $case->hearings()->create(['title' => 'قادمة', 'day' => 'الأسبوع القادم', 'status' => 'مجدولة', 'starts_at' => now()->addWeek(), 'time' => '10:00']);

        $this->artisan('hearings:auto-lapse')->assertExitCode(0);

        // الثابت لا النصّ الحرفيّ: السلسلة وُحّدت في EventStatus::HEARING_LAPSED لأن
        // «بانتظار تسجيل النتيجة» كانت سلسلة ثانية لا تعرفها شروط أزرار تسجيل النتيجة
        // ولا خريطة الألوان — فتختفي الأزرار وتزرقّ الشارة.
        $this->assertSame(EventStatus::HEARING_LAPSED, $stale->fresh()->status);
        $this->assertSame('مجدولة', $recent->fresh()->status); // لم تبلغ 24 ساعة
        $this->assertSame('مجدولة', $future->fresh()->status);
        // «الجلسة القادمة» المخزّنة ثُبّتت على القادمة الحقيقية وأُشعر المحامي
        $this->assertStringContainsString('10:00', (string) $case->fresh()->next_hearing);
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
    }

    public function test_meeting_reschedule_resets_zoom_session_and_reverts_invite_stage(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9020', 'title' => 'لم ينعقد', 'when_label' => 'أمس',
            'status' => 'لم ينعقد', 'starts_at' => now()->subDay(),
            // بقايا جلسةٍ لم تنعقد (لا دخول ولا تسجيل): خروجٌ ومدّةٌ وحضورٌ مُدخَل يدوياً.
            // اجتماعٌ فيه `join_time` أو `recording_url` انعقد فعلاً فيُرفض نقله —
            // MeetingRescheduleRulesTest::test_a_held_meeting_is_refused_and_keeps_its_recording
            'leave_time' => now()->subDay(),
            'duration_sec' => 1200, 'attend' => 40,
        ]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $meeting->id, 'ref' => 'MR-9020',
            'service' => 'خدمة', 'type' => 'استشارة مرئية', 'day' => 'أمس', 'time' => '10:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_EXPIRED,
        ]);

        $newDay = now()->addWeek()->format('Y-m-d');
        $this->actingAs($admin)->post(route('admin.meetings.reschedule', $meeting), [
            'day' => $newDay, 'time' => '11:00', 'reason' => 'client_request',
        ])->assertRedirect();

        $meeting->refresh();
        $this->assertSame('قادم', $meeting->status);
        // بقايا الجلسة القديمة صُفّرت — لا تخصّ الموعد الجديد
        $this->assertNull($meeting->join_time);
        $this->assertNull($meeting->leave_time);
        $this->assertNull($meeting->duration_sec);
        $this->assertSame(0, $meeting->attend);
        // الدعوة المنتهية عادت «مؤكدة» بالموعد الجديد
        $req->refresh();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $this->assertSame($newDay, $req->day);
    }

    public function test_reschedule_rejected_for_final_meetings(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ended = Meeting::create(['ref' => 'M-9021', 'title' => 'منتهٍ', 'when_label' => 'أمس', 'status' => 'منتهٍ']);

        // بسببٍ صالح — كي يُردّ الطلب بحارس النهائيّة لا بنقص السبب
        $this->actingAs($admin)->post(route('admin.meetings.reschedule', $ended), [
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:00', 'reason' => 'client_request',
        ])->assertStatus(422);
        $this->assertSame('منتهٍ', $ended->fresh()->status);
    }
}
