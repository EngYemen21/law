<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقد الوصلة بين واجهة «تحويل التذكرة إلى قضية» وخادمها.
 *
 * CaseConversionTest يغطّي سلوك الخادم؛ وهذا الملف يغطّي ما بينهما — وكان بلا تغطية:
 * الرايات الثلاث التي تقرّر ظهور الزرّ (`ticket.caseRef` · `ticket.summaryApproved` ·
 * `converted`) لم يفحصها اختبار واحد، فكان يمكن أن يسقط أيّ منها من حمولة Inertia
 * فيختفي الزرّ (أو يظهر زرّ يُرفض) والحزمة كلّها خضراء.
 *
 * ويثبّت أيضاً شكل المسار الذي تبنيه الواجهة بنفسها:
 * TicketActionsPanel.tsx يركّب `/${role}/tickets/${encodeURIComponent(ticketNo)}/convert`
 * من **رقم** التذكرة لا معرّفها — وهو يعمل لأن Ticket::getRouteKeyName() يعيد 'number'.
 * تغيير مفتاح الربط إلى id يكسر الزرّ بـ404 بلا أن يسقط أي اختبار خادميّ.
 */
class TicketConvertContractTest extends TestCase
{
    use RefreshDatabase;

    private function completedTicket(User $client, ?User $lawyer = null): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-7700',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);
    }

    private function approveSummary(Ticket $ticket): void
    {
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص معتمد', 'status' => 'approved',
            'approved_at' => now(), 'ai_generated' => true,
        ]);
    }

    /** المسار الذي تركّبه الواجهة من رقم التذكرة يصل فعلاً إلى المتحكّم (ربط بـnumber لا id). */
    public function test_the_url_the_frontend_builds_from_the_ticket_number_converts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        // نفس التركيب الحرفي في TicketActionsPanel.convertToCase (encodeURIComponent ≈ rawurlencode)
        $endpoint = '/lawyer/tickets/'.rawurlencode($ticket->number).'/convert';

        $this->actingAs($lawyer)->post($endpoint)->assertRedirect();

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** صفحة المحامي تمنح الواجهة الرايتين اللتين تُظهران الزرّ، ثم تقلبهما بعد التحويل. */
    public function test_lawyer_page_flags_flip_from_button_to_case_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        // قبل التحويل: canConvert في الواجهة = status==='مكتملة' && !converted
        $this->actingAs($lawyer)->get(route('lawyer.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->component('lawyer/ticketchat')
                ->where('ticket.no', $ticket->number)
                ->where('ticket.status', 'مكتملة')
                ->where('ticket.caseRef', null)
                ->where('converted', false)
                ->where('base', '/lawyer'));

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        // بعد التحويل: caseRef يحمل رقم القضية فيصير الزرّ رابط «عرض ملف القضية»
        $this->actingAs($lawyer)->get(route('lawyer.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('ticket.caseRef', $case->number)
                ->where('converted', true));
    }

    /** وصفحة الموظف تحمل summaryApproved — الراية التي تُخفي الزرّ قبل اعتماد المحامي. */
    public function test_employee_page_exposes_summary_approval_flag(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->completedTicket($client, $lawyer);

        // بلا اعتماد: الراية false ⇒ mayConvert=false ⇒ لا زرّ (بدل زرّ يرتدّ برسالة رفض)
        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->component('employee/ticketchat')
                ->where('ticket.summaryApproved', false)
                ->where('ticket.caseRef', null));

        $this->approveSummary($ticket);

        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('ticket.summaryApproved', true));

        // والزرّ الظاهر الآن ينجح فعلاً على المسار الذي تبنيه الواجهة لدور employee
        $this->actingAs($employee)->post('/employee/tickets/'.rawurlencode($ticket->number).'/convert')
            ->assertRedirect();
        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /**
     * الإدارة تفتح شاشة المحامي نفسها، والزرّ يبني مساره من `base`.
     * لو عاد base بـ'/lawyer' للإدارة لأرسل الزرّ إلى مسار المحامي فارتدّ 403 عند غير المسنَد.
     */
    public function test_admin_page_base_points_the_button_at_the_admin_route(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->get(route('admin.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('base', '/admin'));

        $this->actingAs($admin)->post('/admin/tickets/'.rawurlencode($ticket->number).'/convert')
            ->assertRedirect();
        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** ورسالة الرفض تصل الواجهة تحت المفتاح 'ticket' الذي يقرأه onError في اللوحة. */
    public function test_rejection_reaches_the_panel_under_the_ticket_key(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);
        $ticket->update(['status' => 'الرأي القانوني']);

        // Object.values(errors)[0] في onError يقرأ أوّل قيمة — فالمفتاح لا بدّ أن يكون 'ticket'
        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))
            ->assertSessionHasErrors(['ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اكتمالها.']);

        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }
}
