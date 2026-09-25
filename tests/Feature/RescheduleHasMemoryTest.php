<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Enums\Role;
use App\Jobs\DropZoomMeetingJob;
use App\Mail\ConsultRescheduledMail;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **إعادة الجدولة دورةٌ لها ذاكرة.** (قرار المالك 2026-09-25)
 *
 * كلّ اختبارٍ هنا عطلٌ رُصد في دراسة إعادة الجدولة، ويمرّ بالرحلة الحقيقيّة: رأيٌ معتمد ←
 * طلبُ استشارة ← تسعير وسداد ← نشرُ موعد ← إعادة جدولة ← موعدٌ جديد.
 */
class RescheduleHasMemoryTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => Role::Client, 'email' => 'client@example.test']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني']);
    }

    /** استشارةٌ حضوريّة منشورٌ موعدها (لا Zoom — يُختبر الحذف في اختبارٍ منفصل). */
    private function scheduledConsult(string $day = '+2 days'): Consult
    {
        $ticket = $this->ticketWithApprovedOpinion($this->client, ['status' => 'بانتظار حجز الاستشارة']);
        $consult = $this->requestPricedAndPaid($this->client, $ticket, 'office');

        $this->adminPublishes($consult, [
            'lawyer_id' => $this->lawyer->id,
            'date' => now()->modify($day)->toDateString(),
            'time' => '11:00',
            'type' => 'office',
        ])->assertSuccessful();

        return $consult->fresh();
    }

    private function reschedule(Consult $consult, ?User $as = null, array $data = ['reason' => 'lawyer_unavailable']): TestResponse
    {
        return $this->actingAs($as ?? $this->journeyAdmin())
            ->post(route('admin.consults.reschedule', $consult), $data);
    }

    // ── ١ · السبب ─────────────────────────────────────────────────────────────

    public function test_a_reason_is_required(): void
    {
        $consult = $this->scheduledConsult();

        $this->reschedule($consult, data: [])->assertSessionHasErrors('reason');
        $this->assertSame('جديدة', $consult->fresh()->status, 'أُعيدت الجدولة بلا سبب.');
    }

    public function test_other_requires_an_explanation_in_arabic(): void
    {
        $consult = $this->scheduledConsult();

        $this->reschedule($consult, data: ['reason' => 'other'])->assertSessionHasErrors('note');

        $message = session('errors')->first('note');
        $this->assertStringNotContainsString('other', $message, 'رسالة التحقّق تطبع القيمة البرمجيّة.');
        $this->assertStringContainsString('سببٌ آخر', $message);
    }

    public function test_the_reason_is_kept_in_the_journey_and_on_the_cancelled_appointment(): void
    {
        $consult = $this->scheduledConsult();
        $old = $consult->appointment;

        $this->reschedule($consult, data: ['reason' => 'other', 'note' => 'انقطاع الكهرباء في المكتب'])->assertRedirect();

        $row = JourneyTransition::where('entity_ref', $consult->ref)->where('transition', 'consult.reschedule')->sole();
        $this->assertSame('سببٌ آخر — انقطاع الكهرباء في المكتب', $row->reason);

        $old->refresh();
        $this->assertSame('ملغي', $old->status);
        $this->assertSame('سببٌ آخر — انقطاع الكهرباء في المكتب', $old->cancel_reason);
        $this->assertNotNull($old->cancelled_at);
    }

    // ── ٢ · التذكرة تتحرّك بانتقالها هي ──────────────────────────────────────

    /** رُصد حيّاً على SB-2026-3286: تغيّرت حالة التذكرة وسجلُّ رحلتها فارغ. */
    public function test_every_ticket_move_in_the_booking_cycle_leaves_a_journey_line(): void
    {
        $consult = $this->scheduledConsult();
        $ref = $consult->ticket->number;

        $this->reschedule($consult)->assertRedirect();

        $moves = JourneyTransition::where('entity_ref', $ref)->orderBy('id')->pluck('transition')->all();

        // السداد ← الانتظار · النشر ← «موعد مؤكد» · الإعادة ← الانتظار من جديد
        $this->assertContains('ticket.awaits_schedule', $moves);
        $this->assertContains('ticket.scheduled', $moves);
        $this->assertSame(2, collect($moves)->filter(fn ($t) => $t === 'ticket.awaits_schedule')->count(), 'إحدى حركات التذكرة بلا سطر.');
        $this->assertSame('بانتظار تحديد الموعد', $consult->ticket->fresh()->status);
    }

    // ── ٣ · الذاكرة: الموعد الملغى لا يُنسى، والفرز لا يضيع ─────────────────

    public function test_the_cancelled_appointment_survives_the_next_booking(): void
    {
        $consult = $this->scheduledConsult();
        $first = $consult->appointment_id;

        $this->reschedule($consult)->assertRedirect();
        $this->adminPublishes($consult->fresh(), [
            'lawyer_id' => $this->lawyer->id, 'date' => now()->addDays(4)->toDateString(), 'time' => '12:00', 'type' => 'office',
        ])->assertSuccessful();

        $consult->refresh();
        $this->assertNotSame($first, $consult->appointment_id, 'لم يُنشأ موعدٌ جديد.');

        // كانت الصلة الوحيدة `consults.appointment_id` فتُستبدل — والآن السلسلة كاملة
        $chain = $consult->appointments()->pluck('status', 'id')->all();
        $this->assertCount(2, $chain);
        $this->assertSame('ملغي', $chain[$first]);
        $this->assertSame('مؤكد', $chain[$consult->appointment_id]);
    }

    public function test_the_file_returns_to_where_it_was_after_the_new_booking(): void
    {
        $consult = $this->scheduledConsult();
        $consult->forceFill(['status' => 'محالة للمحامي'])->saveQuietly();

        $this->reschedule($consult)->assertRedirect();
        $this->assertSame('محالة للمحامي', $consult->fresh()->resume_status);

        $this->adminPublishes($consult->fresh(), [
            'lawyer_id' => $this->lawyer->id, 'date' => now()->addDays(4)->toDateString(), 'time' => '12:00', 'type' => 'office',
        ])->assertSuccessful();

        $consult->refresh();
        $this->assertSame('محالة للمحامي', $consult->status, 'عادت الاستشارة «جديدة» فيُعاد فرزها من أوّله.');
        $this->assertNull($consult->resume_status, 'بقيت الحالة المحفوظة بعد قضائها.');
    }

    public function test_a_no_show_starts_fresh_on_its_new_booking(): void
    {
        $consult = $this->scheduledConsult();
        $consult->forceFill(['status' => 'لم يحضر', 'session' => 'لم تُعقد'])->saveQuietly();

        $this->reschedule($consult, data: ['reason' => 'client_absent'])->assertRedirect();

        $this->assertNull($consult->fresh()->resume_status, '«لم يحضر» ليست حالة فرزٍ تُستعاد.');
    }

    // ── ٤ · السقف ───────────────────────────────────────────────────────────

    public function test_the_third_reschedule_belongs_to_senior_management(): void
    {
        $consult = $this->scheduledConsult();
        $consult->forceFill(['reschedule_count' => RescheduleConsult::LIMIT, 'assigned_lawyer_id' => $this->lawyer->id])->saveQuietly();

        $this->actingAs($this->lawyer)
            ->post(route('lawyer.consults.reschedule', $consult), ['reason' => 'lawyer_unavailable'])
            ->assertForbidden();
        $this->assertSame('جديدة', $consult->fresh()->status);

        $this->reschedule($consult)->assertRedirect();
        $this->assertSame(RescheduleConsult::LIMIT + 1, $consult->fresh()->reschedule_count);
    }

    // ── ٥ · لكلّ طرفٍ ما يخصّه ──────────────────────────────────────────────

    public function test_everyone_concerned_hears_of_it_and_the_actor_does_not(): void
    {
        Mail::fake();
        $consult = $this->scheduledConsult();
        $booker = $this->schedulingEmployee();
        $admin = $this->journeyAdmin();
        $before = UserNotification::count();

        $this->reschedule($consult, $admin)->assertRedirect();

        $to = fn (User $u) => UserNotification::where('user_id', $u->id)->where('id', '>', $before)->count();

        $this->assertSame(1, $to($this->client), 'العميل');
        $this->assertSame(1, $to($booker), 'موظّف الحجز — كان لا يُنبَّه أحدٌ في المكتب.');
        $this->assertSame(1, $to($this->lawyer), 'المحامي المسنَد — أعاد غيرُه جدولة استشارته.');
        $this->assertSame(0, $to($admin), 'لا يُخبَر الفاعل بما فعله للتوّ.');

        Mail::assertQueued(ConsultRescheduledMail::class, fn ($m) => $m->hasTo('client@example.test'));
    }

    /** والنصّ صادق: المكتب يحدّد الموعد — العميل لا يختاره (قرار 2026-09-14). */
    public function test_the_client_is_not_told_to_pick_a_time(): void
    {
        $consult = $this->scheduledConsult();

        $this->reschedule($consult)->assertRedirect();

        $text = (string) UserNotification::where('user_id', $this->client->id)->latest('id')->value('body');
        $this->assertStringContainsString('سيحدّد المكتب', $text);
        $this->assertStringNotContainsString('اختر', $text);
    }

    // ── ٦ · Zoom في الخلفيّة ────────────────────────────────────────────────

    /** كان الحذف داخل طلب المستخدم: حتى ٤٥ ثانية على الزرّ حين يتعثّر Zoom (رُصد حيّاً). */
    public function test_the_zoom_meeting_is_dropped_in_the_background(): void
    {
        Queue::fake([DropZoomMeetingJob::class]);
        $consult = $this->scheduledConsult();
        $consult->forceFill(['meet_id' => '555000111'])->saveQuietly();

        $this->reschedule($consult)->assertRedirect();

        Queue::assertPushed(DropZoomMeetingJob::class, fn ($job) => $job->meetId === '555000111');
        $this->assertNull($consult->fresh()->meet_id);
    }
}
