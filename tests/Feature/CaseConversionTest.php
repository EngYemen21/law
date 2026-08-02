<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\CaseFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من تحويل التذكرة المكتملة إلى قضية: المستشار يحوّل → الإدارة تحدّد الأتعاب → العميل يسدّد فتُفعّل.
 */
class CaseConversionTest extends TestCase
{
    use RefreshDatabase;

    private function completedTicket(User $client, ?User $lawyer = null): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9100',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);
    }

    public function test_lawyer_converts_completed_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($client->id, $case->user_id);
        $this->assertSame('نزاع تجاري', $case->type);
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);
        $this->assertMatchesRegularExpression('/^CASE-\d{4}-\d{4}$/', $case->number);

        // إشعار للعميل + رسالة في محادثة التذكرة + ظهور القضية لدى العميل
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'تحويل لقضية'));
        $this->actingAs($client)->get(route('cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('cases', 1));
    }

    public function test_employee_converts_completed_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->completedTicket($client);

        $this->actingAs($employee)->post(route('employee.tickets.convert', $ticket))->assertRedirect();

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);
        // رسالة التحويل من الموظف (staff) في محادثة التذكرة
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'staff' && $m->role === 'تحويل لقضية'));
    }

    public function test_employee_cannot_convert_unless_completed(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]));
        $ticket->update(['status' => 'الرأي القانوني']);

        $this->actingAs($employee)->post(route('employee.tickets.convert', $ticket))->assertStatus(422);
    }

    public function test_cannot_convert_unless_completed(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]), $lawyer);
        $ticket->update(['status' => 'الرأي القانوني']);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertStatus(422);
    }

    public function test_cannot_convert_twice(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertStatus(409);
        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    public function test_admin_sets_fee_then_client_pays_to_activate(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        // الإدارة تحدّد الأتعاب
        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();
        $case->refresh();
        $this->assertSame('بانتظار سداد الأتعاب', $case->status);
        $this->assertSame('pending_payment', $case->fee_status);
        $this->assertSame(10000, $case->fee);

        // العميل يسدّد → القضية تُفعّل وتدخل التحضير
        CaseFee::markPaid($case->fresh());
        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertSame('paid', $case->fee_status);
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'خطة العمل'));
    }

    public function test_admin_sets_lawyer_fee_percentage(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);
        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000, 'lawyer_pct' => 20])->assertRedirect();
        $case->refresh();
        $this->assertSame(20, $case->lawyer_pct);
        $this->assertSame(2000, $case->lawyer_fee);
    }

    public function test_conversion_runs_ai_analysis(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();
        // رسالة التحليل الذكي (cfAnalysis) موجودة + النوع/القسم مُعبّآن
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'تحليل'));
        $this->assertNotEmpty($case->type);
        $this->assertNotEmpty($case->department);
    }

    public function test_setfee_creates_real_invoice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);
        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();
        $inv = Invoice::where('case_id', $case->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame(11500, $inv->amount); // 10000 + 15% ضريبة
        $this->assertFalse($inv->paid);

        // سداد كامل → الفاتورة مدفوعة
        CaseFee::markPaid($case->fresh());
        $this->assertTrue($inv->fresh()->paid);
        $this->assertSame('قيد التحضير', $case->fresh()->status);
    }

    public function test_installment_payment_plan(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-0500', 'type' => 'تجاري',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        // الدفعة الأولى → تفعيل + حالة أقساط
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install'])->assertRedirect();
        $case->refresh();
        $this->assertSame('installments', $case->fee_status);
        $this->assertSame(1, $case->installments_paid);
        $this->assertSame('قيد التحضير', $case->status);

        // الدفعتان التاليتان → اكتمال السداد
        $this->actingAs($client)->post(route('cases.pay-installment', $case))->assertRedirect();
        $this->actingAs($client)->post(route('cases.pay-installment', $case))->assertRedirect();
        $case->refresh();
        $this->assertSame('paid', $case->fee_status);
        $this->assertSame(3, $case->installments_paid);
    }

    public function test_lawyer_closes_ticket_without_case(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.close', $ticket))->assertRedirect();
        $this->assertSame('مغلقة', $ticket->fresh()->status);
        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    public function test_lawyer_requests_additional_documents(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.reqdocs', $ticket))->assertRedirect();
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'lawyer' && $m->role === 'نواقص'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        $this->assertSame('مكتملة', $ticket->fresh()->status); // الحالة لا تتغيّر
    }

    public function test_admin_oversees_case_fees(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket(User::factory()->create(['role' => Role::Client]), $lawyer);
        $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket))->assertRedirect();

        $this->actingAs($admin)->get(route('admin.casefees'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/casefees')->has('cases', 1));
    }
}
