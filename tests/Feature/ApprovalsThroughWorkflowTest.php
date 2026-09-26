<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **شاشة «بانتظار اعتمادك» تمرّ بمحرّك الرحلة** — رفضاً واستبعاداً.
 *
 * كان `ApprovalsController` يكتب حالات التذكرة والملخّص والاستشارة مباشرةً — بلا فحصٍ للحالة
 * ولا قيدِ انتقال — وكان خارج قائمة الكتّاب القدامى فلم يره الحارس (جرد 2026-09-18). السلوك
 * المرئيّ محفوظٌ في `AdminApprovalsOperationsTest`؛ وهنا ما أضافه المرور بالمحرّك. والحارس في
 * الاختبارات «يرفض» الآن، فأيّ كتابةٍ مباشرةٍ باقية في المتحكّم تُسقط هذه الاختبارات.
 */
class ApprovalsThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    private function proposal(string $status = 'بانتظار اعتماد الإدارة للمسار'): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'number' => 'TK-WF-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => $status,
            'proposed_track' => TicketOutcomeTrack::Case->value, 'proposed_track_reason' => 'اكتمال السندات',
            'proposed_by_id' => $this->lawyer->id, 'proposed_at' => now(),
        ]);
    }

    private function transitions(string $name): int
    {
        return JourneyTransition::where('transition', $name)->count();
    }

    public function test_rejecting_a_track_is_a_recorded_transition_with_its_reason(): void
    {
        $ticket = $this->proposal();

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'track', 'ref' => $ticket->number, 'reason' => 'استكمل الحلّ الودّيّ أوّلاً'])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('الرأي القانوني', $ticket->status);
        $this->assertNull($ticket->proposed_track);
        $row = JourneyTransition::where('transition', 'ticket.reject_outcome_track')->sole();
        $this->assertSame('بانتظار اعتماد الإدارة للمسار', $row->from_state);
        $this->assertSame('استكمل الحلّ الودّيّ أوّلاً', $row->reason);
        $this->assertTrue(UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', '%رفض مقترح المسار%')->exists(), 'المقترِح يُبلَّغ كما كان');
    }

    /** الرفض يعيد التذكرة للمستشار مع قيد الانتقال (الاستبعاد بلا سبب أُزيل — لا مسار التفاف على الرفض المسبَّب). */
    public function test_returning_a_summary_goes_through_the_engine(): void
    {
        $reject = Ticket::create(['user_id' => $this->client->id, 'number' => 'TK-WF-R', 'type' => 'عقاري', 'status' => 'بانتظار اعتماد الإدارة للملخّص']);
        $rs = TicketSummary::create(['ticket_id' => $reject->id, 'status' => 'awaiting_admin', 'lawyer_id' => $this->lawyer->id, 'lawyer_approved_at' => now()]);

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'summary', 'ref' => 'TK-WF-R', 'reason' => 'دقّق الشرط الجزائيّ'])->assertRedirect();

        $this->assertSame('awaiting_lawyer', $rs->fresh()->status);
        $this->assertSame('بانتظار اعتماد المستشار', $reject->fresh()->status);
        $this->assertSame(1, $this->transitions('ticket_summary.return_to_lawyer'));
    }

    /** التذكرة المحوّلة قضيّةً لا تُحيا «بانتظار اعتماد المستشار» بإعادة ملخّصها. */
    public function test_returning_a_summary_never_reopens_a_converted_ticket(): void
    {
        $ticket = Ticket::create(['user_id' => $this->client->id, 'number' => 'TK-WF-C', 'type' => 'عقاري', 'status' => 'محولة إلى قضية']);
        TicketSummary::create(['ticket_id' => $ticket->id, 'status' => 'awaiting_admin', 'lawyer_id' => $this->lawyer->id]);

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'summary', 'ref' => 'TK-WF-C', 'reason' => 'مراجعة'])->assertRedirect();

        $this->assertSame('محولة إلى قضية', $ticket->fresh()->status);
    }

    public function test_rejecting_a_proposed_appointment_clears_the_link_and_logs_why(): void
    {
        $consult = Consult::create(['user_id' => $this->client->id, 'ref' => 'CN-WF-1', 'subject' => 'استشارة', 'channel' => 'video', 'lawyer' => $this->lawyer->name, 'status' => 'بانتظار اعتماد الموعد']);
        $appointment = Appointment::create(['user_id' => $this->client->id, 'ext_id' => 'AP-WF-1', 'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'م', 'day' => '2026-10-01', 'time' => '10:00', 'place' => 'مرئية', 'status' => 'بانتظار الاعتماد', 'tone' => 'b-amber', 'when_kind' => 'up']);
        $consult->update(['appointment_id' => $appointment->id]);

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'appointment', 'ref' => 'CN-WF-1', 'reason' => 'يتعارض مع جلسة محكمة'])->assertRedirect();

        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNull($consult->appointment_id, 'لا إشارة لصفٍّ محذوف');
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $this->assertStringContainsString('يتعارض مع جلسة محكمة', (string) json_encode($consult->audit, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->transitions('consult.reject_proposed_appointment'));
    }

    /** لا يُعاد فتح استشارةٍ تجاوزت الاعتماد بمسار «رفض الموعد» — والموعد القائم لا يُحذف. */
    public function test_an_appointment_past_approval_cannot_be_rejected_from_here(): void
    {
        $consult = Consult::create(['user_id' => $this->client->id, 'ref' => 'CN-WF-2', 'subject' => 'استشارة', 'channel' => 'video', 'lawyer' => $this->lawyer->name, 'status' => 'منتهية']);
        $appointment = Appointment::create(['user_id' => $this->client->id, 'ext_id' => 'AP-WF-2', 'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'م', 'day' => '2026-10-01', 'time' => '10:00', 'place' => 'مرئية', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'past']);
        $consult->update(['appointment_id' => $appointment->id]);

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'appointment', 'ref' => 'CN-WF-2', 'reason' => 'خطأ'])->assertStatus(422);

        $this->assertSame('منتهية', $consult->fresh()->status);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_rejecting_a_result_is_recorded_on_the_summary(): void
    {
        $ticket = Ticket::create(['user_id' => $this->client->id, 'number' => 'TK-WF-H', 'type' => 'تجاري', 'status' => 'مكتملة']);
        $summary = TicketSummary::create(['ticket_id' => $ticket->id, 'status' => 'approved', 'result_status' => 'approved']);

        $this->actingAs($this->admin)->post('/admin/approvals/reject', ['type' => 'history', 'ref' => 'TK-WF-H', 'reason' => 'صياغةٌ غير واضحة'])->assertRedirect();

        $this->assertSame('rejected', $summary->fresh()->result_status);
        $this->assertSame('مكتملة', $ticket->fresh()->status, 'النتيجة وحدها كما كان');
        $this->assertSame('صياغةٌ غير واضحة', JourneyTransition::where('transition', 'ticket_summary.reject_result')->value('reason'));
    }
}
