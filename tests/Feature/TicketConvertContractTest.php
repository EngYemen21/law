<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ApprovesTicketSummary;
use Tests\TestCase;

/**
 * عقد الوصلة بين واجهة «تحويل التذكرة إلى قضية» وخادمها.
 *
 * CaseConversionTest يغطّي سلوك الخادم؛ وهذا الملف يغطّي ما بينهما — وكان بلا تغطية:
 * الرايات الثلاث التي تقرّر ظهور الزرّ (`ticket.caseRef` · `ticket.summaryApproved` ·
 * `converted`) لم يفحصها اختبار واحد، فكان يمكن أن يسقط أيّ منها من حمولة Inertia
 * فيختفي الزرّ (أو يظهر زرّ يُرفض) والحزمة كلّها خضراء.
 *
 * ويثبّت أيضاً شكل المسار المركَّب من **رقم** التذكرة لا معرّفها — وهو يعمل لأن
 * Ticket::getRouteKeyName() يعيد 'number'. تغيير مفتاح الربط إلى id يكسره بـ404 بلا أن
 * يسقط أي اختبار خادميّ.
 */
class TicketConvertContractTest extends TestCase
{
    use ApprovesTicketSummary;
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

    /** المسار الحوكمي الرسمي الذي تركّبه الواجهة من رقم التذكرة يصل فعلاً إلى المتحكّم (ربط بـnumber لا id). */
    public function test_the_url_the_frontend_builds_from_the_ticket_number_converts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);
        // تذكرةٌ «مكتملة» ملخّصها معتمد كما في التدفّق الواقعيّ — شرط قرار المآل (ث٥)
        $this->approveTicketSummary($ticket);

        $endpoint = '/admin/tickets/'.rawurlencode($ticket->number).'/track/approve';

        $this->actingAs($admin)->post($endpoint, [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد المسار برقم التذكرة المرمّز.',
        ])->assertRedirect();

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** صفحة المحامي تمنح الواجهة الرايتين اللتين تُظهران الزرّ، ثم تقلبهما بعد التحويل. */
    public function test_lawyer_page_flags_flip_from_button_to_case_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);
        // تذكرةٌ «مكتملة» ملخّصها معتمد كما في التدفّق الواقعيّ — شرط قرار المآل (ث٥)
        $this->approveTicketSummary($ticket);

        // قبل التحويل: canConvert في الواجهة = status==='مكتملة' && !converted
        $this->actingAs($lawyer)->get(route('lawyer.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->component('lawyer/ticketchat')
                ->where('ticket.no', $ticket->number)
                ->where('ticket.status', 'مكتملة')
                ->where('ticket.caseRef', null)
                ->where('converted', false)
                ->where('base', '/lawyer'));

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة لمسار القضية.',
        ])->assertRedirect();
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
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        // بلا اعتماد: الراية false ⇒ mayConvert=false ⇒ لا زرّ (بدل زرّ يرتدّ برسالة رفض)
        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->component('employee/ticketchat')
                ->where('ticket.summaryApproved', false)
                ->where('ticket.caseRef', null));

        $this->approveTicketSummary($ticket);

        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('ticket.summaryApproved', true));

        // والتحويل الحوكمي الرسمي ينجح عبر مسار الإدارة برقم التذكرة
        $this->actingAs($admin)->post('/admin/tickets/'.rawurlencode($ticket->number).'/track/approve', [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية.',
        ])->assertRedirect();
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
        // تذكرةٌ «مكتملة» ملخّصها معتمد كما في التدفّق الواقعيّ — شرط قرار المآل (ث٥)
        $this->approveTicketSummary($ticket);

        $this->actingAs($admin)->get(route('admin.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('base', '/admin'));

        $this->actingAs($admin)->post('/admin/tickets/'.rawurlencode($ticket->number).'/track/approve', [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية.',
        ])->assertRedirect();
        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** تأكيد غياب مسارات التحويل المباشر القديمة نهائياً من سجل المسارات (ADR-009). */
    public function test_legacy_convert_routes_are_completely_absent(): void
    {
        $this->assertFalse(Route::has('lawyer.tickets.convert'));
        $this->assertFalse(Route::has('employee.tickets.convert'));
        $this->assertFalse(Route::has('admin.tickets.convert'));
        $this->assertFalse(Route::has('tickets.convert'));
    }
}
