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
}
