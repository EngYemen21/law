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
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

class AdminApprovalsOperationsTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'المدير العام']);
        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'سعد العمري']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار فهد']);
    }

    public function test_approvals_index_loads_successfully_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.approvals'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/approvals')
            ->has('ticketSummaries')
            ->has('ticketTrackProposals')
            ->has('sessionSummaries')
            ->has('appointments')
            ->has('approvedHistory')
            ->has('counts')
        );
    }

    public function test_admin_can_approve_track_proposal(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-TRK-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'الرأي القانوني',
            'proposed_track' => TicketOutcomeTrack::Case->value,
            'proposed_track_reason' => 'بناءً على اكتمال السندات النظامية نوصي برفع دعوى قضائية عاجلة.',
            'proposed_by_id' => $this->lawyer->id,
            'proposed_at' => now(),
        ]);
        // «الرأي القانوني» لا يُبلَغ إلّا بملخّصٍ معتمد — شرط قرار المآل (ث٥)
        $this->approveOpinionOf($ticket);

        $response = $this->actingAs($this->admin)->post("/admin/tickets/{$ticket->number}/track/approve", [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تم اعتماد المسار القضائي وفق تسبيب المستشار وبموجب الأنظمة المتبعة.',
        ]);

        $response->assertRedirect();
        $ticket->refresh();
        $this->assertSame(TicketOutcomeTrack::Case->value, $ticket->approved_track);
        $this->assertSame($this->admin->id, $ticket->approved_by_id);
    }

    public function test_admin_can_reject_track_proposal(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-TRK-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'الرأي القانوني',
            'proposed_track' => TicketOutcomeTrack::Case->value,
            'proposed_track_reason' => 'مقترح مسار قضية بحاجة لتدقيق ومراجعة.',
            'proposed_by_id' => $this->lawyer->id,
            'proposed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/approvals/reject', [
            'type' => 'track',
            'ref' => $ticket->number,
            'reason' => 'يرجى استكمال المستندات الأصلية وإعادة دراسة إمكانية الحل الودي أولاً.',
        ]);

        $response->assertRedirect();
        $ticket->refresh();
        $this->assertNull($ticket->proposed_track);
        $this->assertNull($ticket->proposed_track_reason);
    }

    public function test_admin_can_reject_summary(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-SUM-'.uniqid(),
            'type' => 'استشارة عقارية',
            'status' => 'بانتظار اعتماد الإدارة للملخّص',
        ]);
        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id,
            'facts' => 'وقائع العقد العقاري',
            'key_points' => 'التوصيات',
            'status' => 'awaiting_admin',
            'lawyer_id' => $this->lawyer->id,
            'lawyer_approved_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/approvals/reject', [
            'type' => 'summary',
            'ref' => $ticket->number,
            'reason' => 'يرجى مراجعة الفقرة الثالثة وتدقيق بنود الشرط الجزائي.',
        ]);

        $response->assertRedirect();
        $summary->refresh();
        $this->assertSame('awaiting_lawyer', $summary->status);
        $this->assertNull($summary->lawyer_approved_at);
    }

    public function test_admin_can_reject_session_summary(): void
    {
        $consult = Consult::create([
            'user_id' => $this->client->id,
            'ref' => 'CN-'.uniqid(),
            'subject' => 'جلسة استشارة عمالية',
            'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name,
            'status' => 'قيد الاستشارة',
            'session' => 'منتهية',
            'summary' => 'تم الاتفاق على التسوية الودية.',
            'summary_lawyer_approved_at' => now(),
            'summary_lawyer_approved_by' => $this->lawyer->id,
        ]);

        // 1. اختبار الرفض
        $response = $this->actingAs($this->admin)->post('/admin/approvals/reject', [
            'type' => 'session',
            'ref' => $consult->ref,
            'reason' => 'يرجى كتابة محضر تفصيلي بالأرقام المتفق عليها في التسوية.',
        ]);
        $response->assertRedirect();
        $consult->refresh();
        $this->assertNull($consult->summary_lawyer_approved_at);

        // 2. الإعادة انتقالٌ مسجَّل بسببه، والمستشار يُبلَّغ (كانت مسحَ عمودٍ صامتاً — `ReturnConsultSummary`)
        $this->assertSame(1, JourneyTransition::where('transition', 'consult.return_summary')->count());
        $this->assertTrue(UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', '%محضر%')->exists());
    }

    public function test_admin_can_reject_and_dismiss_appointment(): void
    {
        $consult = Consult::create([
            'user_id' => $this->client->id,
            'ref' => 'CN-APT-'.uniqid(),
            'subject' => 'استشارة عامة',
            'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name,
            'status' => 'بانتظار اعتماد الموعد',
        ]);
        $appointment = Appointment::create([
            'user_id' => $this->client->id,
            'ext_id' => 'APT-'.uniqid(),
            'type' => 'استشارة مرئية',
            'ico' => 'video',
            'lawyer' => $this->lawyer->name,
            'day' => 'الأحد',
            'time' => '10:00',
            'place' => 'عبر الاتصال المرئي',
            'status' => 'بانتظار التأكيد',
            'tone' => 'b-amber',
            'when_kind' => 'up',
        ]);
        $consult->update(['appointment_id' => $appointment->id]);

        // اختبار الرفض
        $response = $this->actingAs($this->admin)->post('/admin/approvals/reject', [
            'type' => 'appointment',
            'ref' => $consult->ref,
            'reason' => 'الموعد يتعارض مع جلسة محكمة للمستشار، يرجى تقديم موعد بديل.',
        ]);
        $response->assertRedirect();
        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    /**
     * **نافذتا الموافقة والرفض تحملان زرّ التنفيذ.** كانت الدالّتان معرَّفتين ولا يناديهما شيء: أزرار
     * ✓ و✗ في كلّ التبويبات تفتح نافذةً بلا زرّ إلّا الإغلاق (وُجد 2026-09-20). الخادم تحرسه الاختبارات
     * أعلاه؛ وهذا يحرس الوصلة بين الواجهة وبينه.
     */
    public function test_the_approve_and_reject_dialogs_have_their_confirm_buttons(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/admin/approvals.tsx'));

        $this->assertStringContainsString('onClick={handleApprove}', $ui);
        $this->assertStringContainsString('onClick={handleReject}', $ui);
        $this->assertStringContainsString('تأكيد الاعتماد', $ui);
        $this->assertStringContainsString('تأكيد الرفض والإعادة', $ui);
    }

    public function test_admin_can_reject_history_result(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-HIS-'.uniqid(),
            'type' => 'استشارة تجارية',
            'status' => 'مكتملة',
        ]);
        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id,
            'facts' => 'وقائع العقد',
            'key_points' => 'التوصيات',
            'status' => 'approved',
            // مراجعةٌ لاحقة لنتيجةٍ معتمدة — المصدر الوحيد الباقي للرفض (`pending_admin` حُذفت 2026-09-19)
            'result_status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/approvals/reject', [
            'type' => 'history',
            'ref' => $ticket->number,
            'reason' => 'النتيجة غير واضحة ويجب إعادة صياغتها لتكون مفهومة للعميل.',
        ]);

        $response->assertRedirect();
        $summary->refresh();
        $this->assertSame('rejected', $summary->result_status);
    }

    public function test_newest_items_are_always_ordered_first_across_all_tables(): void
    {
        // 1. مقترحات المسار: المقترح الأحدث يجب أن يكون أول عنصر في الجدول
        $olderProposalTicket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-ORD-OLD-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'الرأي القانوني',
            'proposed_track' => TicketOutcomeTrack::Case->value,
            'proposed_track_reason' => 'مسار قديم',
            'proposed_by_id' => $this->lawyer->id,
            'proposed_at' => now()->subHours(2),
        ]);
        $newerProposalTicket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-ORD-NEW-'.uniqid(),
            'type' => 'نزاع عمالي',
            'status' => 'الرأي القانوني',
            'proposed_track' => TicketOutcomeTrack::Consultation->value,
            'proposed_track_reason' => 'مسار جديد جداً',
            'proposed_by_id' => $this->lawyer->id,
            'proposed_at' => now()->subMinutes(5),
        ]);

        // 2. ملخصات التذاكر: الملخص المرفوع أحدث من المستشار يأتي في أول الجدول حتى لو كانت تذكرته قديمة
        $olderTicketNewSummary = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-SUM-OLDTKT-'.uniqid(),
            'type' => 'استشارة عقارية',
            'status' => 'بانتظار اعتماد الإدارة للملخّص',
            'created_at' => now()->subDays(10),
        ]);
        TicketSummary::create([
            'ticket_id' => $olderTicketNewSummary->id,
            'lawyer_id' => $this->lawyer->id,
            'status' => 'awaiting_admin',
            'lawyer_approved_at' => now()->subMinutes(2),
            'facts' => 'ملخص تذكرة قديمة رُفع الآن',
        ]);

        $newerTicketOldSummary = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-SUM-NEWTKT-'.uniqid(),
            'type' => 'استشارة تجارية',
            'status' => 'بانتظار اعتماد الإدارة للملخّص',
            'created_at' => now()->subHours(1),
        ]);
        TicketSummary::create([
            'ticket_id' => $newerTicketOldSummary->id,
            'lawyer_id' => $this->lawyer->id,
            'status' => 'awaiting_admin',
            'lawyer_approved_at' => now()->subHours(1),
            'facts' => 'ملخص رُفع قبل ساعة',
        ]);

        // 3. محاضر الجلسات: المحضر الأحدث اعتماداً في أول الجدول
        $olderSession = Consult::create([
            'user_id' => $this->client->id,
            'ref' => 'CN-SES-OLD-'.uniqid(),
            'subject' => 'جلسة قديمة',
            'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name,
            'status' => 'منتهية',
            'summary' => 'محضر اعتمد قبل ساعتين',
            'summary_lawyer_approved_at' => now()->subHours(2),
        ]);
        $newerSession = Consult::create([
            'user_id' => $this->client->id,
            'ref' => 'CN-SES-NEW-'.uniqid(),
            'subject' => 'جلسة جديدة',
            'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name,
            'status' => 'منتهية',
            'summary' => 'محضر اعتمد الآن',
            'summary_lawyer_approved_at' => now()->subMinutes(1),
        ]);

        // 4. سجل النتائج والملخصات المعتمدة: أحدث عنصر اعتُمد يظهر كأول عنصر في السجل
        $olderHistoryTicket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-HIS-OLD-'.uniqid(),
            'type' => 'قضية سابقة',
            'status' => 'مكتملة',
        ]);
        TicketSummary::create([
            'ticket_id' => $olderHistoryTicket->id,
            'status' => 'approved',
            'approved_at' => now()->subDays(5),
            'result_status' => 'approved',
        ]);

        $newerHistoryTicket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'TK-HIS-NEW-'.uniqid(),
            'type' => 'قضية حديثة',
            'status' => 'مكتملة',
        ]);
        TicketSummary::create([
            'ticket_id' => $newerHistoryTicket->id,
            'status' => 'approved',
            'approved_at' => now(),
            'result_status' => 'approved',
        ]);

        // استدعاء الشاشة والتحقق من الترتيب
        $response = $this->actingAs($this->admin)->get(route('admin.approvals'));
        $response->assertOk();

        $response->assertInertia(function ($page) use (
            $newerProposalTicket,
            $olderTicketNewSummary,
            $newerSession,
            $newerHistoryTicket
        ) {
            $props = $page->toArray()['props'];

            // التحقق أن الجديد يعرض بأول الجدول (Index 0) في كل فئة
            $this->assertSame($newerProposalTicket->number, $props['ticketTrackProposals'][0]['no']);
            $this->assertSame($olderTicketNewSummary->number, $props['ticketSummaries'][0]['no']);
            $this->assertSame($newerSession->ref, $props['sessionSummaries'][0]['ref']);
            $this->assertSame($newerHistoryTicket->number, $props['approvedHistory'][0]['ref']);
        });
    }
}
