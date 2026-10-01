<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مرشّحات الشاشات تطابق ما يكتبه الخادم** (تدقيق اللوحات ٣، ٤، ٥، ٦، ٨، ١٧، ١٨).
 *
 * هذه عيوبٌ في الواجهة وحدها: قائمةٌ مكتوبةٌ باليد تُسقط حالةً جديدة، أو عدّادٌ لا يعدّ ما يعرضه
 * تبويبه. والحارس يمسح الشاشة بعد نزع التعليقات فيمنع عودة القائمة اليدويّة.
 */
class DashboardFilterScreenGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function screen(string $path): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/'.$path))
        );
    }

    /** (١٧) «بحاجة إجراء» عند الموظّف: القائمة والعدّاد من مجموعة الخادم نفسها. */
    public function test_employee_need_action_tab_uses_the_servers_awaiting_others(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->get(route('employee.tickets'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('awaitingOthers', TicketJourney::AWAITING_OTHERS));

        $code = $this->screen('pages/employee/tickets.tsx');
        $this->assertStringContainsString('awaitingOthers', $code);
        $this->assertStringNotContainsString("'بانتظار اعتماد المستشار', 'بانتظار اعتماد الإدارة']", $code);
    }

    /**
     * (١٨) طلبات ما قبل الجلسة عند الموظّف تشمل «بانتظار اعتماد الموعد».
     *
     * كانت الشاشة تقارن النصّ بقائمة `CONSULT_BOOKING_STATUSES`، ثمّ صارت (2026-09-27) تقرأ مرحلة
     * الحجز وسبب تعذّر الإسناد **من الخادم** (`bookingStage` · `assignBlocker` في `Consult::toCard`)
     * — والخادم يشتقّهما من `ConsultStatus::isPreSession` الذي يشمل الحالات الأربع. فلا قائمةٌ منسوخة
     * تنقص حالة، ولا نصٌّ عربيّ في شرط (CLAUDE.md ق٢).
     */
    public function test_employee_consults_use_the_shared_booking_statuses(): void
    {
        $code = $this->screen('pages/employee/consults.tsx');

        $this->assertStringNotContainsString("['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد']", $code);
        $this->assertStringNotContainsString('CONSULT_BOOKING_STATUSES.includes(', $code);
        $this->assertGreaterThanOrEqual(3, substr_count($code, 'bookingStage'));
        $this->assertGreaterThanOrEqual(3, substr_count($code, 'assignBlocker'));
    }

    /** (١٨) لوحة المواعيد: لا «إعادة جدولة» ولا «لم يحضر» لموعدٍ لم يُعتمد، ومرشّحٌ له. */
    public function test_schedule_board_handles_unapproved_appointments(): void
    {
        $code = $this->screen('pages/employee/schedule.tsx');

        $this->assertStringContainsString('<option value="pending">', $code);
        $this->assertStringContainsString("filterStatus === 'pending' && a.status !== APPT_PENDING", $code);
        $this->assertStringContainsString('selectedAppt.consultId && selectedAppt.status !== APPT_PENDING', $code);
    }

    /** (٤) عدّاد «بانتظار الاعتماد» في صفحة الاجتماعات يطابق عدّاد اللوحة (ما يُعتمد فعلاً). */
    public function test_admin_meetings_pending_count_matches_the_dashboard(): void
    {
        $code = $this->screen('pages/admin/meetings.tsx');

        // حكم الخادم نفسه (`canApprove` ← `Meeting::approvalBlocker`) الذي يعدّه رادار اللوحة
        // (`Meeting::awaitingApprovalCount`) — لا «منتهٍ غير معتمد» ومنه ما لا مخرجات له (قرار المالك 2026-10-01)
        $this->assertStringContainsString('meetings.filter((m) => m.canApprove).length', $code);
        $this->assertStringNotContainsString("!m.approved && m.statusKey === 'ended'", $code);
    }

    /** (٥) ملخّصٌ رفعه المحامي للإدارة له تسميةٌ وخيار مرشّح عنده. */
    public function test_lawyer_tickets_know_the_awaiting_admin_summary(): void
    {
        $code = $this->screen('pages/lawyer/tickets.tsx');

        $this->assertStringContainsString("t.summaryStatus === 'awaiting_admin'", $code);
        $this->assertStringContainsString('<option value="awaiting_admin">', $code);
        $this->assertStringContainsString("filterAi === 'awaiting_admin' && t.summaryStatus !== 'awaiting_admin'", $code);
    }

    /** (٦) قائمة ملخّصات المحامي تفصل ما رفعه للإدارة عمّا ينتظر اعتماده. */
    public function test_lawyer_summaries_separate_those_awaiting_admin(): void
    {
        $code = $this->screen('pages/lawyer/summaries.tsx');

        $this->assertStringContainsString('!s.approved && !s.lawyerApproved', $code);
        $this->assertStringContainsString('!s.approved && s.lawyerApproved', $code);
    }

    /** (٨) ملخّص جلسةٍ اعتمده المحامي لا يبقى عنده «بانتظار إعداد التقرير». */
    public function test_lawyer_consults_do_not_keep_lawyer_approved_summaries_in_drafting(): void
    {
        $code = $this->screen('pages/lawyer/consults.tsx');

        $this->assertStringContainsString("c.summaryApproved || c.summaryLawyerApproved ? 'completed' : 'drafting'", $code);
        $this->assertStringContainsString("c.session !== 'منتهية' || c.summaryApproved || c.summaryLawyerApproved", $code);
    }

    /** (٣) بند «مواعيد حجزها موظّف» يفتح درج الطلب نفسه. */
    public function test_approvals_open_the_consult_request_drawer_by_ref(): void
    {
        $approvals = $this->screen('pages/admin/approvals.tsx');
        $this->assertStringContainsString('/admin/consult-requests?ref=${encodeURIComponent(a.ref)}', $approvals);

        $requests = $this->screen('pages/admin/consult-requests.tsx');
        $this->assertStringContainsString("new URLSearchParams(window.location.search).get('ref')", $requests);
    }
}
