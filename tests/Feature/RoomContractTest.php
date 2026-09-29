<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\RoomStateChanged;
use App\Events\StaffPresenceChanged;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use App\Models\User;
use App\Support\ChannelAccess;
use App\Support\RoomDetails;
use App\Support\RoomPresence;
use App\Support\SessionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * **عقد غرفة الجلسة — بانٍ واحد (`RoomDetails`) للغرف الأربع، وبثٌّ بمفاتيحه (`RoomStateChanged`).**
 *
 * يحرس ما تعتمد عليه الواجهة حرفاً: شكل `room`، و«المحامي» لا «المستشار» ولا صفّ «المدّة»، وأنّ
 * العميل لا يُخبَر بالتسجيل لا في الصفحة ولا في قناته، وأنّ أسباب الرفض حقيقيّة ونصّها واحد، وأنّ
 * أحداث Zoom اللحظيّة تصل الغرفة.
 */
class RoomContractTest extends TestCase
{
    use RefreshDatabase;

    private const WH_SECRET = 'whsec_room';

    private const KEYS = [
        'kind', 'ref', 'title', 'statusLabel', 'live', 'ended', 'rows', 'recording',
        'measuredDuration', 'endAction', 'summaryHref', 'back', 'channel', 'staffChannel', 'hostUrl',
    ];

    private function consult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-RC-'.uniqid(),
            'subject' => 'نزاع تجاري', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة القحطاني',
            'day' => 'اليوم', 'time' => '10:00', 'when_label' => 'اليوم · 10:00',
            'status' => 'قيد الاستشارة', 'session' => 'جلسة جارية',
            'meet_id' => '93000001', 'starts_at' => now()->subMinutes(10),
        ], $extra));
    }

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-RC-'.uniqid(), 'title' => 'اجتماع المراجعة', 'when_label' => 'اليوم · 10:00',
            'status' => 'قادم', 'starts_at' => now()->addHours(3), 'meet_id' => '94000001',
            'client_name' => 'شركة المثال', 'participants' => 'أ. خالد',
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

    /** @param  array<int, array{label: string, value: string}>  $rows */
    private function labels(array $rows): array
    {
        return array_column($rows, 'label');
    }

    // ── شكل العقد ──

    public function test_the_client_consult_room_gets_the_contract_without_recording_or_staff_parts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client, ['host_link' => 'https://zoom.us/s/93000001?zak=secret']);

        $this->actingAs($client)->get('/consults/room?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) use ($consult) {
                $p->component('videoroom')->has('consult'); // الخاصيّة القديمة باقية حتى تنتقل الواجهة
                $room = $p->toArray()['props']['room'];
                $this->assertSame(self::KEYS, array_keys($room));
                $this->assertSame('consult', $room['kind']);
                $this->assertSame($consult->ref, $room['ref']);
                $this->assertTrue($room['live']);
                $this->assertFalse($room['ended']);
                $this->assertFalse($room['recording'], 'العميل لا يُخبَر بالتسجيل');
                $this->assertNull($room['endAction']);
                $this->assertNull($room['staffChannel']);
                $this->assertNull($room['hostUrl'], 'رابط المضيف لا يصل العميل');
                $this->assertSame('room.consult.'.$consult->id, $room['channel']);
                $this->assertSame(['المحامي', 'الموعد', 'المرجع', 'القناة'], $this->labels($room['rows']));
            });
    }

    public function test_the_staff_consult_room_gets_an_enabled_end_action_and_the_recording_flag(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult($client, ['assigned_lawyer_id' => $lawyer->id, 'host_link' => 'https://zoom.us/s/93000001?zak=h']);

        $this->actingAs($lawyer)->get('/lawyer/videoroom?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) use ($consult, $lawyer) {
                $room = $p->toArray()['props']['room'];
                $this->assertSame(self::KEYS, array_keys($room));
                $this->assertTrue($room['recording'], 'تسجيلٌ سحابيّ آليّ لغرفةٍ جارية لها اجتماع Zoom');
                $this->assertSame([
                    'url' => '/lawyer/consults/'.$consult->id.'/end',
                    'label' => 'إنهاء الجلسة وكتابة الملخّص',
                    'enabled' => true,
                    'redirect' => '/lawyer/consult?ref='.$consult->ref,
                    'placeholder' => 'ملاحظات الجلسة (اختياريّة) — تُبنى عليها مسوّدة الملخّص',
                ], $room['endAction']);
                $this->assertSame('room.consult.'.$consult->id.'.staff', $room['staffChannel']);
                $this->assertSame('https://zoom.us/s/93000001?zak=h', $room['hostUrl']);
                $labels = $this->labels($room['rows']);
                $this->assertContains('العميل', $labels);
                $this->assertNotContains('المدّة', $labels);
                $this->assertNotContains('المستشار', $labels);
                $this->assertSame($lawyer->name, $room['rows'][0]['value']);
            });
    }

    public function test_a_staff_meeting_room_before_its_window_opens_with_a_disabled_end_action(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting();

        $this->actingAs($admin)->get('/admin/meetingroom?ref='.$meeting->ref)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $room = $p->toArray()['props']['room'];
                $this->assertFalse($room['live']);
                $this->assertFalse($room['recording']);
                $this->assertSame('بانتظار البدء', $room['statusLabel']);
                $this->assertFalse($room['endAction']['enabled'], 'الزرّ يتبع حارس الخادم: لا يُنهى إلّا جارٍ');
                $this->assertSame('إنهاء الاجتماع', $room['endAction']['label']);
                // بلا محامٍ مسنَد ⇒ لا صفّ فارغ له؛ ولا «المدّة» (الاجتماع ينتهي حين يُنهى)
                $this->assertSame(['العميل', 'الموعد', 'المرجع', 'المشاركون'], $this->labels($room['rows']));
            });
    }

    public function test_measured_duration_comes_from_zoom_after_the_end(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client, ['session' => 'منتهية', 'status' => 'منتهية', 'duration_sec' => 5400]);

        $state = RoomDetails::state($consult, false);
        $this->assertTrue($state['ended']);
        $this->assertSame('ساعة ونصف', $state['measuredDuration']);
        $this->assertSame('انتهت الجلسة', $state['statusLabel']);
        $this->assertArrayNotHasKey('recording', $state);

        $this->assertNull(RoomDetails::state($this->consult($client), false)['measuredDuration'], 'لا مدّة قبل النهاية');
    }

    // ── أسباب الرفض الحقيقيّة ──

    public function test_rooms_refuse_with_the_real_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ended = $this->consult($client, ['session' => 'منتهية', 'status' => 'منتهية']);
        $this->actingAs($admin)->getJson('/admin/videoroom?ref='.$ended->ref)
            ->assertStatus(422)->assertJsonPath('message', SessionWindow::REFUSE_ENDED);
        $this->actingAs($client)->getJson('/consults/room?ref='.$ended->ref)
            ->assertStatus(403)->assertJsonPath('message', SessionWindow::REFUSE_ENDED);

        $missed = $this->consult($client, ['session' => 'بانتظار الجلسة', 'status' => 'جديدة', 'starts_at' => now()->subHours(3), 'link_released_at' => now()->subHours(4)]);
        $this->actingAs($client)->getJson('/consults/room?ref='.$missed->ref)
            ->assertStatus(403)->assertJsonPath('message', SessionWindow::REFUSE_MISSED);

        $upcoming = $this->meeting(['user_id' => $client->id]);
        $this->actingAs($client)->getJson('/meetingroom?ref='.$upcoming->ref)
            ->assertStatus(403)->assertJsonPath('message', SessionWindow::refuseNotOpen());

        $cancelled = $this->meeting(['status' => 'ملغى']);
        $this->actingAs($admin)->getJson('/admin/meetingroom?ref='.$cancelled->ref)
            ->assertStatus(422)->assertJsonPath('message', SessionWindow::REFUSE_CANCELLED);

        // مرجعٌ مجهول ⇒ 404 (كانت غرفة الطاقم تُفتح فارغة)
        $this->actingAs($admin)->get('/admin/videoroom?ref=CN-NOPE')->assertNotFound();
    }

    /**
     * **فتحُ رابط الغرفة مباشرةً لا يُسقط على صفحة خطأٍ تقنيّة** (رُصد في المتصفّح 2026-09-26): الزيارة
     * تعود إلى صفحة الجلسة وسببُ الرفض إشعارٌ — للطاقم وللعميل، وللنوعين.
     */
    public function test_opening_a_closed_room_link_returns_to_the_session_with_the_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ended = $this->consult($client, ['session' => 'منتهية', 'status' => 'منتهية']);
        $cancelled = $this->meeting(['status' => 'ملغى']);

        $this->actingAs($admin)->get('/admin/videoroom?ref='.$ended->ref)
            ->assertRedirect('/admin/consult?ref='.$ended->ref)
            ->assertSessionHas('error', SessionWindow::REFUSE_ENDED);

        $this->actingAs($client)->get('/consults/room?ref='.$ended->ref)
            ->assertRedirect('/myconsults')
            ->assertSessionHas('error', SessionWindow::REFUSE_ENDED);

        $this->actingAs($admin)->get('/admin/meetingroom?ref='.$cancelled->ref)
            ->assertRedirect('/admin/meeting?id='.$cancelled->ref)
            ->assertSessionHas('error', SessionWindow::REFUSE_CANCELLED);
    }

    public function test_the_signature_endpoint_returns_the_real_reason(): void
    {
        config(['services.zoom.sdk_key' => 'K', 'services.zoom.sdk_secret' => 'S']);
        $client = User::factory()->create(['role' => Role::Client]);

        $notOpen = $this->consult($client, ['session' => 'بانتظار الجلسة', 'status' => 'جديدة', 'starts_at' => now()->addDay()]);
        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $notOpen->ref])
            ->assertForbidden()->assertJsonPath('message', SessionWindow::refuseNotOpen());

        $ended = $this->consult($client, ['session' => 'منتهية', 'status' => 'منتهية']);
        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $ended->ref])
            ->assertForbidden()->assertJsonPath('message', SessionWindow::REFUSE_ENDED);

        $missedMeeting = $this->meeting(['user_id' => $client->id, 'starts_at' => now()->subHours(3)]);
        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $missedMeeting->ref, 'kind' => 'meeting'])
            ->assertForbidden()->assertJsonPath('message', SessionWindow::REFUSE_MISSED);

        $phone = $this->consult($client, ['channel' => 'هاتفية']);
        $this->actingAs($client)->postJson(route('zoom.signature'), ['ref' => $phone->ref])
            ->assertStatus(422)->assertJsonPath('message', SessionWindow::REFUSE_NOT_VIDEO);
    }

    // ── البثّ: قناتان، والتسجيل للطاقم وحده ──

    public function test_the_room_event_never_carries_recording_to_the_client_channel(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client);

        [$shared, $staff] = RoomStateChanged::both($consult);

        $this->assertSame('room.state', $shared->broadcastAs());
        $this->assertSame('private-room.consult.'.$consult->id, $shared->broadcastOn()[0]->name);
        $this->assertSame('private-room.consult.'.$consult->id.'.staff', $staff->broadcastOn()[0]->name);
        $this->assertSame(['live', 'ended', 'statusLabel', 'measuredDuration', 'participants'], array_keys($shared->broadcastWith()));
        $this->assertArrayHasKey('recording', $staff->broadcastWith());
    }

    public function test_room_channels_admit_the_owner_to_the_shared_channel_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $stranger = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult($client);

        $this->assertTrue(ChannelAccess::roomMember($client, $consult));
        $this->assertFalse(ChannelAccess::roomStaff($client, $consult), 'قناة الطاقم تحمل التسجيل');
        $this->assertFalse(ChannelAccess::roomMember($stranger, $consult));
        $this->assertTrue(ChannelAccess::roomStaff($admin, $consult));
        $this->assertFalse(ChannelAccess::roomMember($otherLawyer, $consult), 'محامٍ غير مسنَد');
    }

    public function test_ending_broadcasts_the_room_state_after_commit(): void
    {
        Queue::fake();
        Event::fake([RoomStateChanged::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult($client);

        $this->actingAs($admin)->post(route('admin.consults.end', $consult))->assertRedirect();

        Event::assertDispatched(RoomStateChanged::class, fn (RoomStateChanged $e) => ! $e->staff && $e->broadcastWith()['ended'] === true);
        Event::assertDispatched(RoomStateChanged::class, fn (RoomStateChanged $e) => $e->staff);
    }

    // ── أحداث Zoom اللحظيّة ──

    public function test_zoom_participant_and_recording_events_update_and_broadcast_the_room(): void
    {
        Event::fake([RoomStateChanged::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client, ['meet_id' => '93000100']);
        $participant = fn (string $uuid, string $event) => $this->webhook(['event' => $event, 'payload' => ['object' => [
            'id' => '93000100',
            'participant' => ['participant_uuid' => $uuid, 'join_time' => now()->toIso8601String(), 'leave_time' => now()->toIso8601String()],
        ]]])->assertOk();

        $participant('A', 'meeting.participant_joined');
        $participant('B', 'meeting.participant_joined');
        $participant('A', 'meeting.participant_joined'); // تكرارٌ لا يُضخّم العدد
        $this->assertSame(2, RoomPresence::participants($consult->fresh()));
        $participant('A', 'meeting.participant_left');
        $this->assertSame(1, RoomDetails::state($consult->fresh(), false)['participants']);

        $this->webhook(['event' => 'recording.stopped', 'payload' => ['object' => ['id' => '93000100']]])->assertOk();
        $this->assertFalse(RoomDetails::state($consult->fresh(), true)['recording']);
        $this->webhook(['event' => 'recording.started', 'payload' => ['object' => ['id' => '93000100']]])->assertOk();
        $this->assertTrue(RoomDetails::state($consult->fresh(), true)['recording']);

        Event::assertDispatched(RoomStateChanged::class, fn (RoomStateChanged $e) => $e->staff);
        Event::assertDispatched(RoomStateChanged::class, fn (RoomStateChanged $e) => ! $e->staff);
    }

    public function test_zoom_meeting_started_goes_through_the_start_transition(): void
    {
        Event::fake([RoomStateChanged::class]);
        $meeting = $this->meeting(['meet_id' => '94000100']);

        $this->webhook(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '94000100']]])->assertOk();

        $this->assertSame('جارٍ', $meeting->fresh()->status);
        $row = JourneyTransition::where('entity_type', 'Meeting')->where('entity_id', $meeting->id)->where('transition', 'meeting.start')->firstOrFail();
        $this->assertSame('zoom', $row->payload['source']);
        Event::assertDispatched(RoomStateChanged::class, fn (RoomStateChanged $e) => $e->broadcastWith()['live'] === true);

        // حدثٌ متأخّر لا يُحيي ملغى
        $cancelled = $this->meeting(['status' => 'ملغى', 'meet_id' => '94000101']);
        $this->webhook(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '94000101']]])->assertOk();
        $this->assertSame('ملغى', $cancelled->fresh()->status);
    }

    public function test_staff_meeting_start_and_invitation_start_go_through_the_engine(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting(['starts_at' => now()->addMinutes(2)]);

        $this->actingAs($admin)->post(route('admin.meetings.start', $meeting))->assertRedirect();
        $this->assertSame('جارٍ', $meeting->fresh()->status);
        $this->assertDatabaseHas('journey_transitions', ['entity_type' => 'Meeting', 'entity_id' => $meeting->id, 'transition' => 'meeting.start', 'actor_id' => $admin->id]);

        // «لم ينعقد» لا يُبدأ بزرّ — يقال لماذا
        $missed = $this->meeting(['status' => 'لم ينعقد', 'starts_at' => now()->subHours(3)]);
        $this->actingAs($admin)->postJson(route('admin.meetings.start', $missed))->assertStatus(422);
        $this->assertSame('لم ينعقد', $missed->fresh()->status);
    }

    /**
     * **حالة المحامي الحيّة من أحداث Zoom** (قرار المالك 2026-09-29): دخوله أيّ غرفةٍ يجعله «في جلسة»،
     * وخروجه منها وهو في أخرى يُبقيه فيها، وإغلاق الغرفة يُخرج من بقي. يُعرف بـ`customer_key` = `u{id}`.
     */
    public function test_a_lawyer_is_in_session_while_inside_any_room_and_free_after_leaving_all(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client, ['meet_id' => '93000200']);
        $meeting = $this->meeting(['meet_id' => '94000200']);
        $event = fn (string $room, string $event, string $uuid, ?string $key) => $this->webhook(['event' => $event, 'payload' => ['object' => [
            'id' => $room,
            'participant' => array_filter(['participant_uuid' => $uuid, 'customer_key' => $key]),
        ]]])->assertOk();

        // الاستشارة ثمّ الاجتماع قبل الخروج منها
        $event('93000200', 'meeting.participant_joined', 'L1', "u{$lawyer->id}");
        $this->assertSame([$lawyer->id => $consult->ref], RoomPresence::staffInSession());
        $event('94000200', 'meeting.participant_joined', 'L2', "u{$lawyer->id}");
        $this->assertSame([$lawyer->id => $meeting->ref], RoomPresence::staffInSession(), 'آخر جلسةٍ دخلها');

        // يخرج من الاجتماع وهو ما زال في الاستشارة ⇒ في جلسة
        $event('94000200', 'meeting.participant_left', 'L2', "u{$lawyer->id}");
        $this->assertSame([$lawyer->id => $consult->ref], RoomPresence::staffInSession());

        // العميل ومن بلا مفتاح ومفتاحٌ لحساب عميل: لا يُحسبون طاقماً
        $event('93000200', 'meeting.participant_joined', 'C1', "u{$client->id}");
        $event('93000200', 'meeting.participant_joined', 'X1', null);
        $this->assertSame([$lawyer->id], array_keys(RoomPresence::staffInSession()));

        // إغلاق الغرفة (خروجٌ لم يصل حدثه) ⇒ متاح
        $this->webhook(['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '93000200']]])->assertOk();
        $this->assertSame([], RoomPresence::staffInSession());
    }

    public function test_the_same_lawyer_on_two_devices_stays_in_session_until_both_leave(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->meeting(['meet_id' => '94000300']);
        $event = fn (string $event, string $uuid) => $this->webhook(['event' => $event, 'payload' => ['object' => [
            'id' => '94000300', 'participant' => ['participant_uuid' => $uuid, 'customer_key' => "u{$lawyer->id}"],
        ]]])->assertOk();

        $event('meeting.participant_joined', 'PC');
        $event('meeting.participant_joined', 'PHONE');
        $event('meeting.participant_left', 'PC');
        $this->assertArrayHasKey($lawyer->id, RoomPresence::staffInSession());
        $event('meeting.participant_left', 'PHONE');
        $this->assertSame([], RoomPresence::staffInSession());
    }

    public function test_the_sdk_join_identifies_staff_to_zoom_and_pages_share_who_is_in_session(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $zoom = (string) file_get_contents(app_path('Http/Controllers/ZoomController.php'));
        $this->assertStringContainsString("'customerKey' => \$isStaff ? 'u'.\$user->id : null", $zoom);
        $this->assertStringContainsString('customerKey: data.customerKey || undefined', (string) file_get_contents(resource_path('js/lib/room-session.ts')));

        $meeting = $this->meeting(['meet_id' => '94000400']);
        RoomPresence::participantJoined($meeting, 'L', $lawyer->id);
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->get(route('admin.meetmgmt'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('inSession.'.$lawyer->id, $meeting->ref));
        $this->actingAs(User::factory()->create(['role' => Role::Client]))->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('inSession', null));
    }

    public function test_presence_changes_are_broadcast_live_to_staff_only(): void
    {
        Event::fake([StaffPresenceChanged::class]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $meeting = $this->meeting(['meet_id' => '94000500']);

        RoomPresence::participantJoined($meeting, 'L', $lawyer->id);
        Event::assertDispatched(StaffPresenceChanged::class, fn (StaffPresenceChanged $e) => $e->inSession === [$lawyer->id => $meeting->ref]
            && $e->broadcastOn()[0]->name === 'private-'.StaffPresenceChanged::CHANNEL);
        // دخولٌ مكرّر لا يغيّر ما يُعرض ⇒ لا بثّ؛ والخروج يبثّ الخريطة الفارغة
        RoomPresence::participantJoined($meeting, 'L', $lawyer->id);
        Event::assertDispatchedTimes(StaffPresenceChanged::class, 1);
        RoomPresence::participantLeft($meeting, 'L');
        Event::assertDispatched(StaffPresenceChanged::class, fn (StaffPresenceChanged $e) => $e->inSession === []);

        // القناة للطاقم وحده
        $authorize = Broadcast::driver()->getChannels()->get(StaffPresenceChanged::CHANNEL);
        $this->assertTrue($authorize($lawyer));
        $this->assertFalse($authorize(User::factory()->create(['role' => Role::Client])));
    }
}
