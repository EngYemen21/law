<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تحقّق من رحلة الإحالة → تجهيز ملخص الملف للمستشار → اعتماد المحامي → وصوله لمحادثة العميل.
 */
class LawyerSummaryFlowTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    private function lawyer(): User
    {
        // القسم مطابق لقسم التذكرة: الإسناد الأوّل يشترط التخصّص الآن (LawyerAssignmentPolicyTest)
        return User::factory()->create([
            'role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني', 'department' => 'القسم التجاري',
        ]);
    }

    /** يفتح تذكرة، يرفق مستنداً، ثم يحيلها — فيُجهَّز الملخص ويُسنَد المحامي. */
    private function referredTicket(User $client, User $employee, User $lawyer): Ticket
    {
        Storage::fake('local');
        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري', 'department' => 'القسم التجاري']);
        $ticket = Ticket::firstOrFail();

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 80),
        ])->assertNoContent();

        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        return $ticket->fresh();
    }

    public function test_referral_generates_summary_and_assigns_lawyer(): void
    {
        $lawyer = $this->lawyer();
        $ticket = $this->referredTicket($this->client(), $this->employee(), $lawyer);

        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame('أ. سارة القحطاني', $ticket->assigned_lawyer);

        $summary = $ticket->summary;
        $this->assertNotNull($summary);
        $this->assertSame('awaiting_lawyer', $summary->status);
        $this->assertSame($lawyer->id, $summary->lawyer_id);
        // الملخص الرباعي مُعبّأ
        $this->assertNotEmpty($summary->case_summary);
        $this->assertNotEmpty($summary->attachments_summary);
        $this->assertNotEmpty($summary->facts);
        $this->assertNotEmpty($summary->key_points);

        // رسالة إحالة من خدمة العملاء ظهرت — وُسِمت `ai` لا `staff`: قالبٌ آليّ لم
        // يكتبه موظّف، ونسبتُه إلى بشرٍ تُخالف صدق المصدر
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'ai' && $m->role === 'إحالة'));
    }

    public function test_employee_cannot_skip_lawyer_approval(): void
    {
        $ticket = $this->referredTicket($this->client(), $this->employee(), $this->lawyer());

        // محاولة الموظف التقدّم بينما الدور على المحامي → لا تغيير
        $this->actingAs($this->employee())->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
    }

    public function test_lawyer_sees_referred_ticket_and_pending_summary(): void
    {
        $lawyer = $this->lawyer();
        $ticket = $this->referredTicket($this->client(), $this->employee(), $lawyer);

        $this->actingAs($lawyer)->get(route('lawyer.tickets'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/tickets')->has('tickets', 1));

        $this->actingAs($lawyer)->get(route('lawyer.summaries'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/summaries')->has('summaries', 1));

        $this->actingAs($lawyer)->get(route('lawyer.summary', $ticket))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/summary')->where('summary.status', 'awaiting_lawyer'));
    }

    public function test_lawyer_approval_reaches_client_conversation(): void
    {
        $lawyer = $this->lawyer();
        $client = $this->client();
        $ticket = $this->referredTicket($client, $this->employee(), $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.summary.approve', $ticket), ['key_points' => '• توجيه إنذار رسمي.'])
            ->assertRedirect(route('lawyer.summaries'));

        $ticket->refresh();
        $this->assertSame('approved', $ticket->summary->status);
        $this->assertNotNull($ticket->summary->approved_at);
        $this->assertSame('الرأي القانوني', $ticket->status);

        // رسالة المحامي ظهرت في المحادثة ويراها العميل
        $lawyerMsg = $ticket->messages()->where('who', 'lawyer')->first();
        $this->assertNotNull($lawyerMsg);
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->contains(fn ($x) => $x['who'] === 'lawyer')));

        // إشعار وصل العميل
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_admin_oversees_all_tickets_and_summaries(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->referredTicket($this->client(), $this->employee(), $this->lawyer());

        $this->actingAs($admin)->get(route('admin.tickets'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/tickets')->has('tickets.data', 1));

        $this->actingAs($admin)->get(route('admin.summaries'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/summaries')->has('summaries', 1));
    }
}
