<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Mail\MeetingEventMail;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **قواعد إعادة جدولة الاجتماع** — ما كانت تقبله بصمت:
 *
 * - بلا سبب: يصل العميلَ «أُعيدت جدولة اجتماعك» ولا أثر يقول لماذا.
 * - إلى موعدٍ مضى: فيغلقه المجدول «لم ينعقد» في دورته التالية.
 * - لاجتماعٍ انعقد وحالته لم تُحدَّث: فيُمحى تسجيله ودليل حضوره.
 */
class MeetingRescheduleRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-RS-'.uniqid(), 'title' => 'اجتماع مراجعة', 'type' => 'اجتماع مع عميل',
            'when_label' => 'قريباً', 'status' => MeetingStatus::Upcoming->value,
            'starts_at' => now()->addDay(),
        ], $extra));
    }

    private function futureDay(): string
    {
        return now()->addWeek()->toDateString();
    }

    public function test_the_reason_is_required_on_both_paths(): void
    {
        Http::fake();
        $meeting = $this->meeting();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.meetings.reschedule', $meeting), ['day' => $this->futureDay(), 'time' => '11:00'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($admin)
            ->post(route('admin.meetings.reschedule', $meeting), ['postpone' => true])
            ->assertSessionHasErrors('reason');
        // «سببٌ آخر» بلا شرح لا يفيد أحداً
        $this->actingAs($admin)
            ->post(route('admin.meetings.reschedule', $meeting), ['day' => $this->futureDay(), 'time' => '11:00', 'reason' => 'other'])
            ->assertSessionHasErrors('note');
        // وسببٌ لا يخصّ الاجتماعات (قرار المحكمة) يُرفض
        $this->actingAs($admin)
            ->post(route('admin.meetings.reschedule', $meeting), ['day' => $this->futureDay(), 'time' => '11:00', 'reason' => 'court_decision'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(MeetingStatus::Upcoming->value, $meeting->fresh()->status, 'لم يتحرّك شيء');
    }

    public function test_a_past_time_is_refused(): void
    {
        Http::fake();
        $meeting = $this->meeting();
        $before = $meeting->starts_at;

        $this->actingAs($this->admin())
            ->post(route('admin.meetings.reschedule', $meeting), [
                'day' => now()->subDay()->toDateString(), 'time' => '10:00', 'reason' => 'client_request',
            ])
            ->assertStatus(422);

        $this->assertEquals($before, $meeting->fresh()->starts_at, 'الموعد القديم يبقى');
        Http::assertNothingSent();
    }

    public function test_a_held_meeting_is_refused_and_keeps_its_recording(): void
    {
        Http::fake();
        // انعقد فعلاً (دخولٌ مسجَّل وتسجيلٌ محفوظ) وحالته ما زالت «قادم» — الفجوة قبل المجدول
        $meeting = $this->meeting([
            'starts_at' => now()->subMinutes(20),
            'join_time' => now()->subMinutes(20),
            'recording_url' => 'https://zoom.example/rec/1',
            'duration_sec' => 900,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.meetings.reschedule', $meeting), [
                'day' => $this->futureDay(), 'time' => '11:00', 'reason' => 'client_request',
            ])
            ->assertStatus(422);

        $fresh = $meeting->fresh();
        $this->assertSame('https://zoom.example/rec/1', $fresh->recording_url, 'التسجيل لا يُمحى');
        $this->assertNotNull($fresh->join_time);
        $this->assertSame(900, $fresh->duration_sec);
        $this->assertSame(MeetingStatus::Upcoming->value, $fresh->status);
        // والواجهة لا تعرض زرّاً يُردّ
        $this->assertFalse($fresh->toFullCard()['reschedulable']);
    }

    /** والتسجيل وحده شاهدٌ كافٍ — حتى لو لم يصل ويبهوك الدخول. */
    public function test_a_recording_alone_marks_the_meeting_as_held(): void
    {
        Http::fake();
        $meeting = $this->meeting(['recording_url' => 'https://zoom.example/rec/2']);

        $this->actingAs($this->admin())
            ->post(route('admin.meetings.reschedule', $meeting), ['postpone' => true, 'reason' => 'technical'])
            ->assertStatus(422);

        $this->assertSame('https://zoom.example/rec/2', $meeting->fresh()->recording_url);
    }

    public function test_the_reason_is_audited_and_reaches_the_client(): void
    {
        Http::fake();
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'client@example.com']);
        $meeting = $this->meeting(['user_id' => $client->id, 'when_label' => 'الموعد القديم']);
        $day = $this->futureDay();

        $this->actingAs($this->admin())
            ->post(route('admin.meetings.reschedule', $meeting), [
                'day' => $day, 'time' => '11:00', 'reason' => 'lawyer_unavailable', 'note' => 'سفرٌ طارئ',
            ])
            ->assertRedirect();

        $this->assertTrue($meeting->fresh()->toFullCard()['reschedulable'], 'ما لم ينعقد يبقى قابلاً للنقل');

        $log = AuditLog::where('action', 'إعادة جدولة اجتماع')->latest('id')->first();
        $this->assertNotNull($log, 'إعادة الجدولة تترك قيد تدقيق');
        $this->assertStringContainsString('اعتذار المحامي — سفرٌ طارئ', (string) $log->description);
        $this->assertSame('الموعد القديم', $log->before_state['الموعد'] ?? null);
        $this->assertSame($day.' · 11:00', $log->after_state['الموعد'] ?? null);
        $this->assertSame('اعتذار المحامي — سفرٌ طارئ', $log->after_state['السبب'] ?? null);

        $note = UserNotification::where('user_id', $client->id)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('اعتذار المحامي — سفرٌ طارئ', (string) $note->body);

        Mail::assertQueued(MeetingEventMail::class, fn (MeetingEventMail $m) => $m->hasTo('client@example.com')
            && $m->reason === 'اعتذار المحامي — سفرٌ طارئ');
    }

    /** والبريد يعرض «السبب» — والتأجيل لا يَعِد بموعدٍ جديد لم يُحدَّد. */
    public function test_the_mail_shows_the_reason_and_stays_honest_when_postponed(): void
    {
        $meeting = $this->meeting(['starts_at' => null, 'when_label' => 'يُحدَّد لاحقاً', 'status' => MeetingStatus::Postponed->value]);

        $html = (new MeetingEventMail($meeting, 'rescheduled', 'بطلب العميل'))->render();

        $this->assertStringContainsString('السبب', $html);
        $this->assertStringContainsString('بطلب العميل', $html);
        $this->assertStringContainsString('أُجّل اجتماعك', $html);
        $this->assertStringNotContainsString('تم اعتماد الموعد الجديد', $html);
    }

    public function test_postponing_is_audited_with_its_reason(): void
    {
        Http::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = $this->meeting(['user_id' => $client->id]);

        $this->actingAs($this->admin())
            ->post(route('admin.meetings.reschedule', $meeting), ['postpone' => true, 'reason' => 'client_request'])
            ->assertRedirect();

        $this->assertSame(MeetingStatus::Postponed->value, $meeting->fresh()->status);
        $log = AuditLog::where('action', 'تأجيل اجتماع')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('بطلب العميل', $log->after_state['السبب'] ?? null);
        $this->assertStringContainsString('بطلب العميل', (string) UserNotification::where('user_id', $client->id)->latest('id')->value('body'));
    }

    public function test_it_refuses_a_time_the_responsible_lawyer_or_a_participant_is_busy_but_not_its_own(): void
    {
        Http::fake();
        $owner = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'المسؤول']);
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'محامٍ آخر']);
        $participant = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'المشارك']);
        $day = now()->addDays(2)->toDateString();
        $at = fn (string $time) => $this->meeting(['assigned_lawyer_id' => $owner->id, 'starts_at' => "{$day} {$time}"]);

        $busy = $at('10:00');
        $busy->participantUsers()->sync([$participant->id]);
        $sameLawyer = $at('12:00');
        $sharedParticipant = $this->meeting(['assigned_lawyer_id' => $other->id, 'starts_at' => "{$day} 14:00"]);
        $sharedParticipant->participantUsers()->sync([$participant->id]);

        $move = fn (Meeting $m, string $time) => $this->actingAs($this->admin())->post(route('admin.meetings.reschedule', $m), [
            'day' => $day, 'time' => $time, 'reason' => 'client_request',
        ]);

        // ثبت قبل الإصلاح: الحالتان كانتا تُنقلان إلى 10:00 فوق الاجتماع الأوّل
        $move($sameLawyer, '10:00')->assertSessionHasErrors(['time' => 'مشغولٌ في هذا الوقت: المسؤول — اختر وقتاً آخر.']);
        $move($sharedParticipant, '10:00')->assertSessionHasErrors(['time' => 'مشغولٌ في هذا الوقت: المشارك — اختر وقتاً آخر.']);
        $this->assertSame('12:00', $sameLawyer->fresh()->starts_at->format('H:i'));
        $this->assertSame('14:00', $sharedParticipant->fresh()->starts_at->format('H:i'));

        // والاجتماع لا يحجب نفسه: نقله ربع ساعة مقبول
        $move($busy, '10:15')->assertSessionHasNoErrors();
        $this->assertSame('10:15', $busy->fresh()->starts_at->format('H:i'));
    }

    public function test_the_reschedule_grid_shows_the_same_busy_times_the_guard_refuses(): void
    {
        $owner = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $participant = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $outsider = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $day = now()->addDays(2)->toDateString();

        $moving = $this->meeting(['assigned_lawyer_id' => $owner->id, 'starts_at' => "{$day} 12:00"]);
        $moving->participantUsers()->sync([$participant->id]);
        // المشارك مشغولٌ 10:00 باجتماعٍ لا يخصّ المسؤول
        $this->meeting(['assigned_lawyer_id' => $outsider->id, 'starts_at' => "{$day} 10:00"])->participantUsers()->sync([$participant->id]);

        $busy = $this->actingAs($this->admin())
            ->getJson(route('admin.meetreqs.availability', ['meeting_id' => $moving->id, 'day' => $day]))
            ->assertOk()->json('busy');
        $starts = array_column($busy, 0);
        $this->assertContains('10:00', $starts, 'انشغال المشارك لا يظهر في شبكة إعادة الجدولة');
        $this->assertNotContains('12:00', $starts, 'الاجتماع يحجب موعده هو');

        // المحامي لا يطّلع على انشغال اجتماعٍ لا صلة له به
        $this->actingAs($outsider)
            ->getJson(route('lawyer.meetreqs.availability', ['meeting_id' => $moving->id, 'day' => $day]))
            ->assertForbidden();
        $this->actingAs($participant)
            ->getJson(route('lawyer.meetreqs.availability', ['meeting_id' => $moving->id, 'day' => $day]))
            ->assertOk();
    }
}
