<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Jobs\EndZoomMeetingJob;
use App\Jobs\FinalizeConsultJob;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\LawyerAvailability;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * **الجلسة تنتهي حين تُنهى — لا حين تبلغ الساعةُ مدّتها** (قرار المالك 2026-09-26).
 *
 * كانت نهاية الاستشارة والاجتماع حسبةً «البداية + المدّة» منسوخةً بسقوفٍ متباينة في ثلاثة نماذج
 * ومجدول: تُغلق غرفةً ما زال فيها الموكّل، وتُعلن جلسةً جارية «منتهية». الآن:
 *
 * - النهاية **حدث**: `EndSession` / `EndMeeting` من زرّ الطاقم أو ويبهوك Zoom.
 * - الفوات (لم تبدأ قطّ) **من البداية** بإعداداته.
 * - المنسيّة (بدأت ولم تُنهَ) تُنهى بعد مهلة النسيان — لا بمدّة — مع تنبيه الطاقم مرّةً واحدة
 *   (`sessions:close-stale`، قرار المالك الأخير في اليوم نفسه).
 * - إنهاؤها في النظام يُغلق غرفة Zoom في الطابور — إلّا إن جاء الإنهاء من Zoom نفسه.
 * - المسافة بين المواعيد باقيةٌ لمنع التعارض — ولا تُنهي شيئاً.
 *
 * والحارس الأخير يمنع عودة الحسبة.
 */
class SessionEndsByEventTest extends TestCase
{
    use RefreshDatabase;

    private const WH_SECRET = 'whsec_session_end';

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-END-'.uniqid(),
            'subject' => 'نزاع تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة',
            'day' => 'اليوم',
            'time' => '10:00',
            'when_label' => 'اليوم · 10:00',
            'status' => 'قيد الاستشارة',
            'session' => 'جلسة جارية',
            'duration_min' => 60,
        ], $extra));
    }

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-END-'.uniqid(),
            'title' => 'اجتماع طويل',
            'when_label' => 'اليوم · 10:00',
            'status' => 'جارٍ',
        ], $extra));
    }

    private function postSignedWebhook(array $payload): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::WH_SECRET]);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp;
        $sig = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::WH_SECRET);

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    // ── ١ · جلسةٌ تجاوزت «البداية + المدّة» ولم تُنهَ: ما زالت منعقدة ──

    public function test_a_live_consult_past_its_booked_length_is_still_joinable_and_not_over(): void
    {
        $startsAt = now()->subMinutes(150); // الشريحة ٦٠ + كلّ السقوف القديمة (+30 · +120) مضت
        $consult = $this->consult(['starts_at' => $startsAt]);
        $appt = Appointment::create([
            'user_id' => $consult->user_id, 'ext_id' => 'AP-END-'.uniqid(), 'type' => 'استشارة مرئية',
            'ico' => 'video', 'lawyer' => 'أ. سارة', 'day' => 'اليوم', 'time' => $startsAt->format('H:i'),
            'starts_at' => $startsAt, 'duration_min' => 60, 'place' => 'اجتماع إلكتروني',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
        $consult->forceFill(['appointment_id' => $appt->id])->saveQuietly();

        $this->assertTrue($consult->canJoin(), 'الجارية تُدخَل حتى تُختم');
        $this->assertFalse($consult->isMissed());
        $this->assertSame(['up', 'قيد الجلسة', 'b-blue'], $appt->fresh()->liveState());
    }

    public function test_ending_the_consult_makes_it_over(): void
    {
        $consult = $this->consult(['starts_at' => now()->subMinutes(150)]);

        Workflow::run(new EndSession, $consult, null, ['source' => 'zoom']);

        $consult->refresh();
        $this->assertSame('منتهية', $consult->session);
        $this->assertFalse($consult->canJoin());
    }

    public function test_a_live_meeting_past_its_old_length_runs_until_the_zoom_webhook_ends_it(): void
    {
        Bus::fake([GenerateMeetingSummaryJob::class]);
        $meeting = $this->meeting(['starts_at' => now()->subHours(4), 'dur' => '60 دقيقة', 'meet_id' => '555001']);

        $this->assertSame(['up', 'جارٍ', 'b-amber'], $meeting->liveState());
        $this->assertTrue($meeting->canJoin());

        $this->postSignedWebhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '555001']]])->assertOk();

        $meeting->refresh();
        $this->assertSame('منتهٍ', $meeting->status);
        $this->assertFalse($meeting->canJoin());
        // الإنهاء انتقالٌ مسجَّل بمصدره — لا كتابةٌ صامتة
        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Meeting', 'entity_id' => $meeting->id, 'transition' => 'meeting.end', 'to_state' => 'منتهٍ',
        ]);
        Bus::assertDispatched(GenerateMeetingSummaryJob::class);
    }

    public function test_the_staff_end_button_goes_through_the_same_transition(): void
    {
        Bus::fake([GenerateMeetingSummaryJob::class]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['starts_at' => now()->subHours(3)]);

        $this->actingAs($admin)->post(route('admin.meetings.end', $meeting), ['attend' => 75])->assertRedirect();

        $meeting->refresh();
        $this->assertSame('منتهٍ', $meeting->status);
        $this->assertSame(75, (int) $meeting->attend);
        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Meeting', 'entity_id' => $meeting->id, 'transition' => 'meeting.end', 'actor_id' => $admin->id,
        ]);
    }

    // ── ٢ · الغياب يُكشف من البداية بإعداداته ──

    public function test_a_session_that_never_started_is_missed_by_the_start_based_setting(): void
    {
        $consult = $this->consult(['session' => 'بانتظار الجلسة', 'status' => 'جديدة', 'starts_at' => now()->subMinutes(40), 'link_released_at' => now()->subMinutes(45)]);
        $this->assertFalse($consult->isMissed());
        $this->assertTrue($consult->canJoin());

        Setting::put('session_missed_after_minutes', 30);
        $consult = $consult->fresh();
        $this->assertTrue($consult->isMissed());
        $this->assertFalse($consult->canJoin(), 'فاتت دون أن تبدأ — يُغلق بابها');
    }

    public function test_no_show_closing_stays_start_based_for_consults_and_meetings(): void
    {
        $consult = $this->consult(['session' => 'بانتظار الجلسة', 'status' => 'جديدة', 'starts_at' => now()->subHours(2)]);
        $meeting = $this->meeting(['status' => 'قادم', 'starts_at' => now()->subHours(2)]);

        // المهلة من الإعدادات بالدقائق: ٣ ساعات ⇒ لم يُحسم شيء بعد
        Setting::put('consult_autoclose_minutes', 180);
        Setting::put('meeting_autoclose_minutes', 180);
        $this->artisan('consults:auto-close-missed')->assertExitCode(0);
        $this->artisan('zoom:auto-close-missed')->assertExitCode(0);
        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session);
        $this->assertSame('قادم', $meeting->fresh()->status);

        // ساعةٌ واحدة ⇒ يُحسمان
        Setting::put('consult_autoclose_minutes', 60);
        Setting::put('meeting_autoclose_minutes', 60);
        $this->artisan('consults:auto-close-missed')->assertExitCode(0);
        $this->artisan('zoom:auto-close-missed')->assertExitCode(0);
        $this->assertSame('لم تُعقد', $consult->fresh()->session);
        $this->assertSame('لم ينعقد', $meeting->fresh()->status);
    }

    // ── ٣ · شبكة النسيان: تنبيهٌ مرّةً واحدة **وإنهاءٌ** في النظام وفي Zoom (قرار المالك 2026-09-26 الأخير) ──
    // اختباراتها في `SessionZoomClosureTest` — مسارٌ لكلّ طريقٍ يختم جلسة، والشبكة منها.

    public function test_the_stale_safety_net_now_ends_the_forgotten_session(): void
    {
        Bus::fake([FinalizeConsultJob::class, EndZoomMeetingJob::class]);
        $forgotten = $this->consult(['starts_at' => now()->subHours(7), 'meet_id' => '777001']);

        $this->artisan('sessions:close-stale')->assertExitCode(0);

        $this->assertSame('منتهية', $forgotten->fresh()->session);
        $this->assertDatabaseHas('journey_transitions', ['entity_id' => $forgotten->id, 'transition' => 'consult.end', 'reason' => SessionWindow::STALE_END_REASON]);
        Bus::assertDispatched(EndZoomMeetingJob::class, fn (EndZoomMeetingJob $job) => $job->meetId === '777001');
    }

    // ── ٤ · إنهاء الجلسة في النظام يُغلق غرفة Zoom — إلّا إن جاء الإنهاء من Zoom ──

    public function test_staff_ending_a_consult_closes_the_zoom_room_after_commit(): void
    {
        Bus::fake([EndZoomMeetingJob::class, FinalizeConsultJob::class]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult(['meet_id' => '81800001', 'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name]);

        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/end")->assertRedirect();

        $this->assertSame('منتهية', $consult->fresh()->session);
        Bus::assertDispatched(EndZoomMeetingJob::class, fn (EndZoomMeetingJob $job) => $job->meetId === '81800001' && $job->ref === $consult->ref);
    }

    public function test_staff_ending_a_meeting_closes_the_zoom_room(): void
    {
        Bus::fake([EndZoomMeetingJob::class, GenerateMeetingSummaryJob::class]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['meet_id' => '81800002']);

        $this->actingAs($admin)->post(route('admin.meetings.end', $meeting))->assertRedirect();

        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        Bus::assertDispatched(EndZoomMeetingJob::class, fn (EndZoomMeetingJob $job) => $job->meetId === '81800002');
    }

    public function test_an_end_that_came_from_zoom_does_not_call_zoom_back(): void
    {
        Bus::fake([EndZoomMeetingJob::class, FinalizeConsultJob::class, GenerateMeetingSummaryJob::class]);
        $consult = $this->consult(['meet_id' => '81800003']);
        $meeting = $this->meeting(['meet_id' => '81800004']);

        $this->postSignedWebhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '81800003']]])->assertOk();
        $this->postSignedWebhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '81800004']]])->assertOk();

        $this->assertSame('منتهية', $consult->fresh()->session);
        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        Bus::assertNotDispatched(EndZoomMeetingJob::class);
    }

    public function test_a_session_without_a_zoom_room_dispatches_nothing(): void
    {
        Bus::fake([EndZoomMeetingJob::class, FinalizeConsultJob::class]);
        $consult = $this->consult(['channel' => 'حضورية']);

        Workflow::run(new EndSession, $consult);

        Bus::assertNotDispatched(EndZoomMeetingJob::class);
    }

    public function test_a_rolled_back_end_closes_no_room(): void
    {
        Bus::fake([EndZoomMeetingJob::class]);
        $consult = $this->consult(['meet_id' => '81800005']);

        try {
            DB::transaction(function () use ($consult) {
                Workflow::run(new EndSession, $consult);
                throw new \RuntimeException('تراجع');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('جلسة جارية', $consult->fresh()->session);
        Bus::assertNotDispatched(EndZoomMeetingJob::class);
    }

    public function test_the_job_ends_the_meeting_through_the_zoom_status_endpoint(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/status' => Http::response('', 204),
        ]);

        (new EndZoomMeetingJob('81800006', 'CN-1'))->handle(app(ZoomService::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.zoom.us/v2/meetings/81800006/status'
            && $r['action'] === 'end');
    }

    public function test_the_job_is_a_no_op_without_zoom_credentials(): void
    {
        Http::fake();

        (new EndZoomMeetingJob('81800007', 'CN-2'))->handle(app(ZoomService::class));

        Http::assertNothingSent();
    }

    public function test_a_room_that_is_not_running_is_not_retried_but_a_zoom_outage_is(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/81800008/status' => Http::response(['code' => 3000], 400),
            'api.zoom.us/v2/meetings/81800009/status' => Http::response('', 503),
        ]);

        // خرج الجميع قبل الزرّ ⇒ لا غرفة مفتوحة، والغاية متحقّقة
        (new EndZoomMeetingJob('81800008', 'CN-3'))->handle(app(ZoomService::class));

        // تعذّرٌ عابر ⇒ يُرمى ليُعيد الطابور المحاولة — والختم محفوظٌ قبله لا يمسّه
        $this->expectException(\RuntimeException::class);
        (new EndZoomMeetingJob('81800009', 'CN-4'))->handle(app(ZoomService::class));
    }

    // ── ٥ · المسافة بين المواعيد باقية — تمنع التعارض ولا تُنهي شيئاً ──

    public function test_booking_conflicts_are_still_blocked_by_the_spacing(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        // `isBusy` يأخذ `Illuminate\Support\Carbon` و`now()` هنا ثابتة (Immutable) — فاللحظة تُبنى صراحةً
        $at = fn (string $hm) => Carbon::parse(now()->addWeek()->toDateString().' '.$hm);
        // اجتماعٌ جديد لا يحمل مدّةً — يشغل مسافة شريحةٍ واحدة من الإعدادات
        Meeting::create([
            'ref' => 'M-SPACE', 'title' => 'اجتماع', 'when_label' => 'x', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $at('10:00'),
        ]);

        $this->assertTrue(LawyerAvailability::isBusy($lawyer->id, $at('10:30')));
        $this->assertFalse(LawyerAvailability::isBusy($lawyer->id, $at('11:00')));

        Setting::put('consult_slot_minutes', 30);
        $this->assertFalse(LawyerAvailability::isBusy($lawyer->id, $at('10:30')), 'المسافة إعدادها');

        // والإعداد يقول ما هو: مسافةٌ بين الحجوزات لا مدّةُ جلسة
        $this->assertStringContainsString('المسافة بين مواعيد الحجز', SettingsRegistry::field('consult_slot_minutes')['label']);
    }

    public function test_zoom_receives_the_nominal_length_when_a_meeting_is_created(): void
    {
        config([
            'services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c',
        ]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/*' => Http::response(['id' => '9333', 'join_url' => 'https://zoom.test/j', 'start_url' => 'https://zoom.test/s']),
            'api.zoom.us/v2/*' => Http::response([]),
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // «dur» لم يعد حقلاً — يُتجاهل إن أُرسل
        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع بلا مدّة', 'type' => 'اجتماع داخلي',
            'day' => now()->addDays(3)->format('Y-m-d'), 'time' => '10:00', 'dur' => '120 دقيقة',
        ])->assertRedirect();

        $this->assertNull(Meeting::firstOrFail()->dur);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/meetings')
            && ($r->data()['duration'] ?? null) === SessionWindow::nominalMinutes());
    }

    // ── ٦ · الحارس: لا تعود حسبة «البداية + المدّة» ──

    /**
     * يُفحص الكود بلا تعليقاته (التعليقات تروي التاريخ: «كان … + المدة + 180د»).
     *
     * @return array<string, string> مسارٌ نسبيّ ⇒ الشيفرة بلا تعليقات
     */
    private static function phpSources(string $dir): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = $code;
        }

        return $out;
    }

    public function test_duration_based_session_endings_do_not_return(): void
    {
        $violations = [];
        $sources = self::phpSources('app');

        foreach ($sources as $path => $code) {
            // «مدّة الجلسة» مفهومٌ أُزيل: لا دالّة `durationMinutes()` تُعرَّف أو تُنادى
            if (preg_match('/\bdurationMinutes\s*\(/', $code)) {
                $violations[] = "{$path}: durationMinutes() — الجلسة لا مدّة لها؛ النهاية حدث (EndSession/EndMeeting)";
            }
        }

        // النماذج والأوامر المجدولة لا تشتقّ نهايةً بإضافة طولٍ إلى البداية
        $endDerivers = ['app/Models/Consult.php', 'app/Models/Meeting.php', 'app/Models/Appointment.php', 'app/Models/MeetRequest.php'];
        foreach ($sources as $path => $code) {
            if (! in_array($path, $endDerivers, true) && ! str_starts_with($path, 'app/Console/Commands/')) {
                continue;
            }
            if (preg_match('/addMinutes\([^;]*(duration|\bdur\b|slotMinutes|nominalMinutes|duration_min)/i', $code)) {
                $violations[] = "{$path}: addMinutes(…مدّة…) — نهايةٌ تقرّرها الساعة";
            }
            if (preg_match('/[+]\s*(30|120|180)\b[^;]*isFuture|isFuture[^;]*[+]\s*(30|120|180)\b/', $code)) {
                $violations[] = "{$path}: سقفٌ زمنيّ منقوش لجلسةٍ جارية";
            }
        }

        /*
         * **الرقم الاسميّ حيث يلزم وحده** — Zoom يشترط `duration` وiCalendar يشترط `DTEND`. كلّ
         * موضعٍ آخر يقرأ `nominalMinutes()` يوشك أن يبني عليه نهاية.
         */
        $nominalAllowed = [
            'app/Support/SessionWindow.php' => 'التعريف',
            'app/Http/Controllers/Staff/MeetingController.php' => 'إنشاء اجتماع Zoom',
            'app/Support/MeetInvitation.php' => 'إنشاء اجتماع Zoom للدعوة',
            'app/Support/ConsultAppointments.php' => 'إنشاء اجتماع Zoom للاستشارة',
            'app/Support/Booking/BookingMoved.php' => 'تحديث موعد Zoom',
            'app/Services/IcalendarService.php' => 'DTEND في التقويم',
        ];
        foreach ($sources as $path => $code) {
            if (preg_match('/nominalMinutes\s*\(/', $code) && ! isset($nominalAllowed[$path])) {
                $violations[] = "{$path}: nominalMinutes() خارج Zoom/التقويم";
            }
        }

        /*
         * **مسافة الحجز لمنع التعارض وحده** — `slotMinutes()` في محرّك التفرّغ ومن يحجز به.
         */
        $spacingAllowed = [
            'app/Support/LawyerAvailability.php' => 'محرّك التفرّغ',
            'app/Support/SessionWindow.php' => 'مصدر الرقم الاسميّ',
            'app/Support/ConsultAppointments.php' => 'المسافة المحجوزة للموعد',
            'app/Http/Controllers/Employee/ScheduleController.php' => 'فحص التعارض',
        ];
        foreach ($sources as $path => $code) {
            if (preg_match('/slotMinutes\s*\(/', $code) && ! isset($spacingAllowed[$path])) {
                $violations[] = "{$path}: slotMinutes() خارج الحجز — المسافة لا تُنهي جلسة";
            }
        }

        // والواجهة لا تَعِد بمدّة: لا منتقي مدّة، ولا «60 دقيقة» احتياطاً لاجتماع
        foreach (['resources/js/lib/meeting-ui.tsx', 'resources/js/pages/admin/meetmgmt.tsx', 'resources/js/pages/meetings.tsx'] as $path) {
            $src = (string) file_get_contents(base_path($path));
            if (preg_match('/MI_DURATIONS|miDuration|durationMin\b|\bdur\s*\|\||setDur\(/', $src)) {
                $violations[] = "{$path}: مدّةٌ للاجتماع في الواجهة";
            }
        }

        $this->assertSame([], $violations, "عادت نهايةٌ محسوبة من المدّة:\n".implode("\n", $violations));
    }
}
