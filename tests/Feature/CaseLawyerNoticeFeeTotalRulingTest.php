<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ApprovesTicketSummary;
use Tests\TestCase;

/**
 * **عيوبٌ ثبتت في اختبار المتصفّح الحقيقيّ (2026-09-30):**
 *
 * - T4: تحويل التذكرة لقضيّة يُشعر العميل وحده — المحامي المسنَد لا يعلم (وفتح التنفيذ يُشعره).
 * - C1: زرّ «سداد» في قائمة قضايا العميل يعرض الأتعاب قبل الضريبة (20,000) والفاتورة 23,000.
 * - C2: صفحة القضيّة عند الموظّف بلا منطوق الحكم، فتختفي بطاقته (عرضاً وتصحيحاً) بعد صدوره.
 */
class CaseLawyerNoticeFeeTotalRulingTest extends TestCase
{
    use ApprovesTicketSummary;
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function convertedCase(): LegalCase
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CLN-'.uniqid(), 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'مكتملة', 'tone' => 'b-green',
        ]);
        $this->approveTicketSummary($ticket);

        $this->actingAs($this->admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لتحويل التذكرة إلى ملف قضية رسمي.',
        ])->assertRedirect();

        return LegalCase::where('ticket_id', $ticket->id)->sole();
    }

    /** T4 */
    public function test_the_assigned_lawyer_is_notified_when_the_ticket_becomes_a_case(): void
    {
        $case = $this->convertedCase();

        $notice = UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', "%{$case->number}%")->sole();
        $this->assertStringContainsString('أُسندت إليك القضية', $notice->body);
    }

    /** C1 */
    public function test_the_client_pay_button_shows_the_invoice_total_with_vat(): void
    {
        $case = $this->convertedCase();
        $this->actingAs($this->admin)->post(route('admin.cases.fee', $case), ['fee' => 20000])->assertRedirect();
        $invoice = Invoice::where('case_id', $case->id)->sole();
        $this->assertGreaterThan(20000, $invoice->amount, 'الفاتورة شاملةٌ الضريبة');

        $this->actingAs($this->client)->get('/cases')
            ->assertInertia(fn ($p) => $p->where('cases.0.no', $case->number)->where('cases.0.amountDue', $invoice->amount));

        // بلا أتعابٍ مستحقّة لا مبلغ
        $case->update(['fee_status' => 'paid']);
        $this->actingAs($this->client)->get('/cases')->assertInertia(fn ($p) => $p->where('cases.0.amountDue', null));
    }

    /** C2 */
    public function test_the_employee_case_page_carries_the_ruling(): void
    {
        $case = $this->convertedCase();
        $case->update(['status' => 'صدر الحكم', 'ruling' => 'حكمت المحكمة بإلزام المدّعى عليه بدفع 150,000 ريال.']);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->get(route('employee.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('case.ruling', 'حكمت المحكمة بإلزام المدّعى عليه بدفع 150,000 ريال.'));
    }
}
