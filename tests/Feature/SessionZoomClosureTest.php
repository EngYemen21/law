<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Consult\MarkNoShow;
use App\Domain\Journey\Transitions\Meeting\MarkMeetingMissed;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\Journey\SessionEndedInSystem;
use App\Jobs\DropZoomMeetingJob;
use App\Jobs\EndZoomMeetingJob;
use App\Jobs\FinalizeConsultJob;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Listeners\Journey\HandleSessionEndedInSystem;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ZoomService;
use App\Support\SessionWindow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * **كلّ مسارٍ يختم جلسةً في النظام يُغلق غرفتها في Zoom — إلّا ما جاء من Zoom نفسه.**
 * (قرار المالك 2026-09-26: «عند إنهاء الاجتماع أو الاستشارة يجب أن يُغلق في Zoom أيضاً — تحقّق».)
 *
 * مسارٌ لكلّ اختبار، بالطابور الحقيقيّ مُزيَّفاً (`Queue::fake`) لا `Bus::fake`: فيمرّ الحدث
 * بمستمعه المسجَّل فعلاً (`HandleSessionEndedInSystem`) — لو نُسي تسجيله سقطت هذه الاختبارات.
 *
 * | المسار | الغرفة |
 * |---|---|
 * | زرّ الطاقم (استشارة/اجتماع) | تُنهى (`EndZoomMeetingJob`) |
 * | شبكة النسيان (`sessions:close-stale`) | تُنهى + ما بعد الإنهاء |
 * | «لم يحضر» / «لم ينعقد» | تُنهى (لا تُحذف: يُعاد جدولتها/يُحييها Zoom) |
 * | إلغاء الاجتماع أو دعوته | تُحذف (`DropZoomMeetingJob`)؛ والجاري لا يُلغى أصلاً (`CancelMeeting`) |
 * | إعادة جدولة الاستشارة | تُحذف (`RescheduleHasMemoryTest`) |
 * | ويبهوك `meeting.ended` | لا نداء (أُغلقت هناك) |
 */
class SessionZoomClosureTest extends TestCase
{
    use RefreshDatabase;

    private const WH_SECRET = 'whsec_closure';

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-ZC-'.uniqid(),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة',
            'day' => 'اليوم', 'time' => '10:00', 'when_label' => 'اليوم · 10:00',
            'status' => 'قيد الاستشارة', 'session' => 'جلسة جارية',
            'meet_id' => '91000001',
        ], $extra));
    }

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-ZC-'.uniqid(), 'title' => 'اجتماع', 'when_label' => 'اليوم · 10:00',
            'status' => 'جارٍ', 'starts_at' => now()->subHour(), 'meet_id' => '92000001',
        ], $extra));
    }

    private function webhook(array $payload): TestResponse
    {
        config(['services.zoom.webhook_secret' => self::WH_SECRET]);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp;

        return $this->call('POST', '/webhooks/zoom', [], [], [], [
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::WH_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function assertRoomClosed(string $meetId): void
    {
        Queue::assertPushed(EndZoomMeetingJob::class, fn (EndZoomMeetingJob $job) => $job->meetId === $meetId);
    }

    // ── التسجيل في التطبيق الحقيقيّ ──

    public function test_the_listener_is_registered_for_the_real_app(): void
    {
        $this->assertTrue(Event::hasListeners(SessionEndedInSystem::class), 'بلا مستمعٍ لا تُغلق غرفةٌ أبداً');
        $listeners = collect(Event::getRawListeners()[SessionEndedInSystem::class] ?? [])
            ->map(fn ($l) => is_array($l) ? $l[0] : (is_string($l) ? explode('@', $l)[0] : $l));
        $this->assertTrue($listeners->contains(HandleSessionEndedInSystem::class));
        // والوظيفة للطابور لا متزامنة — تحتاج عامل طوابير يعمل (`queue:work`)
        $this->assertContains(ShouldQueue::class, class_implements(EndZoomMeetingJob::class));
    }

    // ── زرّ الطاقم ──

    public function test_staff_end_of_a_consult_closes_the_zoom_room(): void
    {
        Queue::fake();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult(['assigned_lawyer_id' => $lawyer->id, 'meet_id' => '91000010']);

        $this->actingAs($lawyer)->post(route('lawyer.consults.end', $consult))->assertRedirect();

        $this->assertSame('منتهية', $consult->fresh()->session);
        $this->assertRoomClosed('91000010');
    }

    public function test_staff_end_of_a_live_meeting_closes_the_zoom_room(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['meet_id' => '92000010']);

        $this->actingAs($admin)->post(route('admin.meetings.end', $meeting))->assertRedirect();

        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        $this->assertRoomClosed('92000010');
    }

    /** البند (ج): الزرّ لا يُنهي اجتماعاً لم يبدأ — كان يكتب «منتهٍ» لقادمٍ ويُطلب محضره. */
    public function test_staff_cannot_end_an_upcoming_meeting(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['status' => 'قادم', 'starts_at' => now()->addHour(), 'meet_id' => '92000011']);

        $this->actingAs($admin)->postJson(route('admin.meetings.end', $meeting))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'لم يبدأ هذا الاجتماع'));

        $this->assertSame('قادم', $meeting->fresh()->status);
        Queue::assertNotPushed(EndZoomMeetingJob::class);
        Queue::assertNotPushed(GenerateMeetingSummaryJob::class);
    }

    public function test_ending_from_the_room_goes_to_the_session_page_not_back_into_the_closed_room(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting();

        $this->actingAs($admin)
            ->from('/admin/meetingroom?ref='.$meeting->ref)
            ->post(route('admin.meetings.end', $meeting))
            ->assertRedirect('/admin/meeting?id='.$meeting->ref)
            ->assertSessionHas('flash'); // الغرفة لا تعرض إشعاراً بنفسها — النجاح يُقال في التحويل
    }

    // ── شبكة النسيان: تنبيهٌ مرّةً واحدة + إنهاءٌ في النظام وفي Zoom ──

    public function test_the_safety_net_alerts_once_and_ends_a_forgotten_consult_in_the_system_and_zoom(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-ZC-'.uniqid(), 'type' => 'نزاع', 'status' => 'موعد مؤكد']);
        $ticket->forceFill(['handler_id' => $employee->id])->saveQuietly();

        $forgotten = $this->consult([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => now()->subHours(7), 'meet_id' => '91000020',
        ]);
        $fresh = $this->consult(['starts_at' => now()->subHours(2), 'meet_id' => '91000021']);

        $this->artisan('sessions:close-stale')->assertExitCode(0);

        $this->assertSame('منتهية', $forgotten->fresh()->session);
        $this->assertSame('جلسة جارية', $fresh->fresh()->session, 'دون المهلة — لا يُمسّ');
        $this->assertRoomClosed('91000020');
        Queue::assertNotPushed(EndZoomMeetingJob::class, fn ($job) => $job->meetId === '91000021');
        // ما بعد الإنهاء كما بعد ويبهوك `meeting.ended`
        Queue::assertPushed(FinalizeConsultJob::class);
        $this->assertSame('بانتظار ملخّص الجلسة', $ticket->fresh()->status);

        // سطر الإنهاء بسببه ومصدره
        $end = JourneyTransition::where('entity_id', $forgotten->id)->where('transition', 'consult.end')->firstOrFail();
        $this->assertSame(SessionWindow::STALE_END_REASON, $end->reason);
        $this->assertSame(SessionEndedInSystem::VIA_SAFETY_NET, $end->payload['source']);
        $this->assertDatabaseHas('journey_transitions', ['entity_id' => $forgotten->id, 'transition' => 'consult.stale_alerted']);

        // الطاقم يُنبَّه مرّةً واحدة بالإنهاء؛ الموكّل لا يصله هذا التنبيه
        $alerted = fn (int $id) => UserNotification::where('user_id', $id)->where('body', 'like', '%أُنهيت آليّاً%')->count();
        $this->assertSame(1, $alerted($admin->id));
        $this->assertSame(1, $alerted($lawyer->id));
        $this->assertSame(1, $alerted($employee->id));
        $this->assertSame(0, $alerted($client->id));

        // المرور التالي: لا تنبيه ولا إنهاء ثانٍ
        $this->artisan('sessions:close-stale')->assertExitCode(0);
        $this->assertSame(1, $alerted($admin->id));
        $this->assertSame(1, JourneyTransition::where('entity_id', $forgotten->id)->where('transition', 'consult.end')->count());
    }

    public function test_the_safety_net_ends_a_forgotten_meeting_and_closes_its_room(): void
    {
        Queue::fake();
        $forgotten = $this->meeting(['starts_at' => now()->subHours(7), 'meet_id' => '92000020']);
        // «قادم» دخله أحدٌ وضاع حدث البدء — بدأ فعلاً
        $joined = $this->meeting(['status' => 'قادم', 'starts_at' => now()->subHours(8), 'join_time' => now()->subHours(8), 'meet_id' => '92000021']);
        $fresh = $this->meeting(['starts_at' => now()->subHours(2), 'meet_id' => '92000022']);

        $this->artisan('sessions:close-stale')->assertExitCode(0);

        $this->assertSame('منتهٍ', $forgotten->fresh()->status);
        $this->assertSame('منتهٍ', $joined->fresh()->status);
        $this->assertSame('جارٍ', $fresh->fresh()->status);
        $this->assertRoomClosed('92000020');
        $this->assertRoomClosed('92000021');
        Queue::assertPushed(GenerateMeetingSummaryJob::class, 2);
        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Meeting', 'entity_id' => $forgotten->id, 'transition' => 'meeting.end', 'reason' => SessionWindow::STALE_END_REASON,
        ]);
    }

    public function test_a_session_alerted_under_the_old_alert_only_rule_is_now_ended_without_a_second_alert(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult(['starts_at' => now()->subHours(9), 'meet_id' => '91000030']);
        JourneyTransition::create([
            'entity_type' => 'Consult', 'entity_id' => $consult->id, 'transition' => 'consult.stale_alerted',
            'from_state' => 'جلسة جارية', 'to_state' => 'جلسة جارية',
        ]);

        $this->artisan('sessions:close-stale')->assertExitCode(0);

        $this->assertSame('منتهية', $consult->fresh()->session);
        $this->assertRoomClosed('91000030');
        $this->assertSame(0, UserNotification::where('user_id', $admin->id)->count(), 'نُبّه من قبل');
    }

    public function test_the_threshold_is_the_admin_setting(): void
    {
        Queue::fake();
        $consult = $this->consult(['starts_at' => now()->subMinutes(20)]);

        Setting::put('session_stale_minutes', 30);
        $this->artisan('sessions:close-stale')->assertExitCode(0);
        $this->assertSame('جلسة جارية', $consult->fresh()->session);

        Setting::put('session_stale_minutes', 10);
        $this->artisan('sessions:close-stale')->assertExitCode(0);
        $this->assertSame('منتهية', $consult->fresh()->session);
    }

    // ── حسم الغياب ──

    public function test_marking_a_consult_no_show_closes_any_zoom_room(): void
    {
        Queue::fake();
        $consult = $this->consult([
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
            'starts_at' => now()->subHours(3), 'meet_id' => '91000040',
        ]);

        Workflow::run(new MarkNoShow, $consult, null, ['automatic' => true]);

        $this->assertSame('لم تُعقد', $consult->fresh()->session);
        $this->assertRoomClosed('91000040');
        Queue::assertNotPushed(DropZoomMeetingJob::class);
    }

    public function test_marking_a_meeting_missed_closes_its_zoom_room_but_keeps_it_for_rescheduling(): void
    {
        Queue::fake();
        $meeting = $this->meeting(['status' => 'قادم', 'starts_at' => now()->subHours(3), 'meet_id' => '92000040']);

        Workflow::run(new MarkMeetingMissed, $meeting);

        $this->assertSame('لم ينعقد', $meeting->fresh()->status);
        $this->assertSame('92000040', $meeting->fresh()->meet_id);
        $this->assertRoomClosed('92000040');
        Queue::assertNotPushed(DropZoomMeetingJob::class);
    }

    // ── الإلغاء ──

    public function test_cancelling_a_meeting_deletes_its_zoom_room_in_the_queue(): void
    {
        Queue::fake();
        Http::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['status' => 'قادم', 'starts_at' => now()->addDay(), 'meet_id' => '92000050']);

        $this->actingAs($admin)->post(route('admin.meetings.cancel', $meeting))->assertRedirect();

        $fresh = $meeting->fresh();
        $this->assertSame('ملغى', $fresh->status);
        $this->assertNull($fresh->meet_id, 'غرفةٌ محذوفة لا يوجَّه إليها ويبهوك متأخّر');
        Queue::assertPushed(DropZoomMeetingJob::class, fn ($job) => $job->meetId === '92000050');
        Queue::assertNotPushed(EndZoomMeetingJob::class);
        Http::assertNothingSent(); // لا نداء متزامن يقف عليه الزرّ
        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Meeting', 'entity_id' => $meeting->id, 'transition' => 'meeting.cancel', 'actor_id' => $admin->id,
        ]);
    }

    /** قرار المالك (2026-09-26): الجاري لا يُلغى — يُنهى أوّلاً. */
    public function test_a_live_meeting_cannot_be_cancelled(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['meet_id' => '92000051']);

        $this->actingAs($admin)->postJson(route('admin.meetings.cancel', $meeting))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الاجتماع جارٍ الآن — أنهِه أوّلاً ثمّ ألغِه إن لزم.');

        $this->assertSame('جارٍ', $meeting->fresh()->status);
        $this->assertSame('92000051', $meeting->fresh()->meet_id);
        Queue::assertNotPushed(DropZoomMeetingJob::class);
        $this->assertFalse($meeting->fresh()->toFullCard()['actions']['cancel'], 'ولا يُعرض زرّ الإلغاء');
        $this->assertTrue($meeting->fresh()->toFullCard()['actions']['end']);
    }

    /** قرار المالك (2026-09-26): إلغاء الدعوة يُلغي اجتماعها ويحذف غرفته. */
    public function test_cancelling_an_invitation_cancels_its_meeting_and_deletes_its_zoom_room(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = $this->meeting(['status' => 'قادم', 'starts_at' => now()->addDay(), 'meet_id' => '92000052', 'user_id' => $client->id]);
        $req = MeetRequest::create([
            'ref' => 'MR-ZC-1', 'user_id' => $client->id, 'meeting_id' => $meeting->id,
            'service' => 'نزاع', 'day' => now()->addDay()->format('Y-m-d'), 'time' => '10:00',
            'sent_by' => 'الإدارة', 'type' => 'استشارة مرئية', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($admin)->post(route('admin.meetreqs.cancel', $req))->assertRedirect()->assertSessionHas('flash');

        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->fresh()->stage);
        $this->assertSame('ملغى', $meeting->fresh()->status);
        Queue::assertPushed(DropZoomMeetingJob::class, fn ($job) => $job->meetId === '92000052');
        $row = JourneyTransition::where('entity_type', 'Meeting')->where('entity_id', $meeting->id)->where('transition', 'meeting.cancel')->firstOrFail();
        $this->assertSame('invitation', $row->payload['via']);
    }

    public function test_an_invitation_whose_meeting_is_live_is_not_cancelled(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        // بدأ على Zoom (دخولٌ مسجَّل) ولم ترتفع الدعوة بعد إلى «نُفّذت»
        $meeting = $this->meeting(['status' => 'قادم', 'join_time' => now()->subMinutes(5), 'meet_id' => '92000053', 'user_id' => $client->id]);
        $req = MeetRequest::create([
            'ref' => 'MR-ZC-2', 'user_id' => $client->id, 'meeting_id' => $meeting->id,
            'service' => 'نزاع', 'day' => now()->format('Y-m-d'), 'time' => '10:00',
            'sent_by' => 'الإدارة', 'type' => 'استشارة مرئية', 'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($admin)->postJson(route('admin.meetreqs.cancel', $req))->assertStatus(422);

        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->fresh()->stage, 'لا تُلغى الدعوة وحدها وتبقى غرفتها');
        $this->assertSame('قادم', $meeting->fresh()->status);
        Queue::assertNotPushed(DropZoomMeetingJob::class);
    }

    // ── ما جاء من Zoom لا يُردّ إليه ──

    public function test_an_end_from_zoom_itself_does_not_call_zoom_back(): void
    {
        Queue::fake();
        $consult = $this->consult(['meet_id' => '91000060']);
        $meeting = $this->meeting(['meet_id' => '92000060']);

        $this->webhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '91000060']]])->assertOk();
        $this->webhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '92000060']]])->assertOk();

        $this->assertSame('منتهية', $consult->fresh()->session);
        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        Queue::assertNotPushed(EndZoomMeetingJob::class);
    }

    // ── الوظيفة تنادي Zoom بالمعرّف الصحيح ──

    public function test_the_job_ends_the_room_by_the_models_meet_id_field(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/status' => Http::response('', 204),
        ]);
        $meeting = $this->meeting(['meet_id' => '92000070']);

        // المعرّف الذي يحمله الحدث هو عمود `meet_id` نفسه (لا `zoom_uuid`)
        [$event] = SessionEndedInSystem::forEnd($meeting->meet_id, $meeting->ref, []);
        (new EndZoomMeetingJob($event->meetId, $event->ref))->handle(app(ZoomService::class));

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.zoom.us/v2/meetings/92000070/status'
            && $r['action'] === 'end');
    }
}
