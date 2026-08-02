<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CaseFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من دورة حياة القضية النشطة: تفعيل → خطة/لائحة → اعتماد المحامي → جلسات → حكم → إغلاق الإدارة.
 */
class CaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $client, array $attrs = []): LegalCase
    {
        return LegalCase::create(array_merge([
            'user_id' => $client->id,
            'number' => 'CASE-2026-0001',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة القحطاني',
            'status' => 'قيد التحضير',
            'tone' => 'b-blue',
            'fee' => 10000,
            'fee_status' => 'paid',
            'pleading_status' => 'pending_lawyer',
        ], $attrs));
    }

    public function test_payment_activates_case_with_plan_and_pleading(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseFor($client, ['status' => 'بانتظار سداد الأتعاب', 'fee_status' => 'pending_payment', 'pleading_status' => 'none']);

        CaseFee::markPaid($case->fresh());

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertSame('pending_lawyer', $case->pleading_status);
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'خطة العمل'));
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'مسودة اللائحة'));
    }

    public function test_lawyer_approves_pleading_makes_case_active(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();

        $case->refresh();
        $this->assertSame('منظورة', $case->status);
        $this->assertSame('approved', $case->pleading_status);
    }

    public function test_lawyer_schedules_and_records_hearing(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseFor($client, ['status' => 'منظورة', 'pleading_status' => 'approved', 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'الجلسة الأولى', 'day' => 'الخميس 02 يوليو', 'time' => '10:00 ص', 'court' => 'الدائرة التجارية',
        ])->assertRedirect();

        $case->refresh();
        $hearing = $case->hearings()->firstOrFail();
        $this->assertSame('الخميس 02 يوليو · 10:00 ص', $case->next_hearing);
        $this->assertSame('مجدولة', $hearing->status);

        // العميل يرى الجلسة
        $this->actingAs($client)->get(route('cases.show', $case))
            ->assertInertia(fn ($p) => $p->has('hearings', 1));

        // تسجيل نتيجة الجلسة
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $hearing]), [
            'status' => 'منعقدة', 'outcome' => 'تم تبادل المذكرات وتأجيل النطق بالحكم.',
        ])->assertRedirect();
        $this->assertSame('منعقدة', $hearing->fresh()->status);
    }

    public function test_ruling_then_admin_closes_case(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['status' => 'منظورة', 'pleading_status' => 'approved', 'assigned_lawyer_id' => $lawyer->id]);

        // الحكم
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'إلزام المدّعى عليه بالمبلغ والمصاريف.'])->assertRedirect();
        $case->refresh();
        $this->assertSame('صدر الحكم', $case->status);
        $this->assertNotEmpty($case->ruling);

        // الإدارة تغلق
        $this->actingAs($admin)->post(route('admin.cases.close', $case))->assertRedirect();
        $this->assertSame('مغلقة', $case->fresh()->status);
    }

    public function test_lawyer_cannot_record_ruling_before_pleading_approved(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['assigned_lawyer_id' => $lawyer->id]); // قيد التحضير

        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'نص حكم مبكر.'])->assertStatus(422);
        $this->assertNull($case->fresh()->ruling);
    }

    public function test_lawyer_cannot_record_ruling_twice(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['status' => 'منظورة', 'pleading_status' => 'approved', 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'الحكم الأول.'])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'حكم معدّل.'])->assertStatus(422);
        $this->assertSame('الحكم الأول.', $case->fresh()->ruling);
    }

    public function test_admin_cannot_close_before_ruling(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['status' => 'منظورة']);

        $this->actingAs($admin)->post(route('admin.cases.close', $case))->assertStatus(422);
    }

    public function test_lawyer_cannot_approve_pleading_twice(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]), ['assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertStatus(422);
    }

    public function test_client_message_gets_ai_reply_in_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseFor($client, ['status' => 'منظورة', 'pleading_status' => 'approved']);

        $this->actingAs($client)->post(route('cases.messages.store', $case), ['body' => 'متى الجلسة القادمة؟'])->assertNoContent();

        $msgs = $case->messages()->get();
        $this->assertSame('متى الجلسة القادمة؟', $msgs[$msgs->count() - 2]->body);
        $this->assertSame('ai', $msgs->last()->who); // ردّ الفريق القانوني (الذكاء الاصطناعي/الاحتياط)
    }

    public function test_employee_oversees_and_replies_on_case(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $case = $this->caseFor(User::factory()->create(['role' => Role::Client]));

        $this->actingAs($employee)->get(route('employee.cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('employee/cases')->has('cases', 1));

        $this->actingAs($employee)->post(route('employee.cases.reply', $case), ['body' => 'نتابع معكم تحديثات القضية.'])->assertNoContent();
        $this->assertTrue($case->messages->contains(fn ($m) => $m->who === 'staff'));
    }

    public function test_admin_and_lawyer_case_lists(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // قضية المحامي محوّلة من تذكرة (ticket_id مطلوب)، مسندة إليه (قائمة المحامي تُفلتر بـFK)
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7001', 'type' => 'نزاع تجاري', 'status' => 'مكتملة', 'tone' => 'b-green',
        ]);
        $this->caseFor($client, ['ticket_id' => $ticket->id, 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($admin)->get(route('admin.cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/cases')->has('cases', 1));

        $this->actingAs($lawyer)->get(route('lawyer.cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/cases')->has('cases', 1));
    }
}
