<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دورة حياة التنفيذ في التبويب الموحّد: محادثة العميل↔المكتب + إجراءات المحامي (التقاط/قبول/تسعير)
 * + إغلاق التنفيذات القديمة + إشراف الموظف.
 */
class ExecutionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function execFor(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-2026-0001',
            'subject' => 'تنفيذ حكم مالي',
            'assigned_lawyer' => 'أ. خالد المالكي',
            'status' => 'جديد',
            'tone' => 'b-blue',
            'last_action' => 'فتح الطلب',
        ], $attrs));
    }

    public function test_client_message_reaches_office_without_ai_reply(): void
    {
        // التبويب الموحّد: رسالة العميل تُخزَّن وتصل للمكتب بلا ردّ AI (نظير exConvSend المرجعي)
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);

        $this->actingAs($client)->post(route('exec-flow.messages.store', $exec), ['body' => 'ما مستجدات التنفيذ؟'])->assertNoContent();

        $msgs = $exec->messages()->get();
        $this->assertSame('ما مستجدات التنفيذ؟', $msgs->last()->body);
        $this->assertSame('client', $msgs->last()->who);
        $this->assertFalse($msgs->contains(fn ($m) => $m->who === 'ai')); // لا ردّ آليّ
    }

    public function test_client_message_blocked_on_closed_execution(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client, ['status' => 'مغلق']);

        $this->actingAs($client)->post(route('exec-flow.messages.store', $exec), ['body' => 'رسالة'])->assertStatus(422);
    }

    public function test_lawyer_picks_up_and_prices_in_unified_tab(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        // طلب تدفّق قيد الدراسة غير مسند → المحامي يلتقطه بقبوله (يُختَم باسمه)
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-F', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertRedirect();
        $exec->refresh();
        $this->assertSame(3, $exec->stage);
        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'saveFee', 'fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة'])->assertRedirect();
        $this->assertSame(4, $exec->fresh()->stage);
    }

    public function test_lawyer_cannot_act_on_another_lawyers_file(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        // مسند لزميل آخر — لا في القائمة ولا في التصرّف
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-OT', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'assigned_lawyer_id' => $other->id]);

        $this->actingAs($mine)->get(route('lawyer.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')->has('execs', 0));
        $this->actingAs($mine)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertForbidden();
    }

    public function test_flow_stage_guard_blocks_out_of_order_close(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        // لا يمكن إغلاق ملفّ تدفّق في مرحلة مبكّرة (تسعير) — حارس المرحلة (ValidationException)
        $flow = Execution::create(['user_id' => $client->id, 'number' => 'EXE-EARLY', 'subject' => 'تدفّق', 'status' => 'تحديد الأتعاب', 'tone' => 'b-blue', 'stage' => 3]);
        $this->actingAs($admin)->from('/admin/execs')->post(route('exec-flow.act', $flow), ['action' => 'close'])->assertSessionHasErrors('stage');
        $this->assertSame(3, $flow->fresh()->stage);
    }

    public function test_employee_oversees_and_replies_in_unified_tab(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->get(route('employee.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')->where('role', 'employee')->has('execs', 1));
        // الموظف يراسل العميل بصفة «خدمة العملاء» عبر التبويب الموحّد
        $this->actingAs($employee)->post(route('exec-flow.messages.store', $exec), ['body' => 'نتابع التنفيذ.'])->assertNoContent();
        $this->assertTrue($exec->messages->contains(fn ($m) => $m->who === 'staff'));
    }

    public function test_employee_intake_requests_docs_and_refers_flow_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // طلب تدفّق في مرحلة الاستقبال (تحليل ذكي، stage 1) بفرع الموظف
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-INT', 'subject' => 'تنفيذ', 'status' => 'تحليل ذكي', 'tone' => 'b-blue', 'stage' => 1]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        // استقبال: طلب مستندات من العميل
        $this->actingAs($employee)->post(route('exec-flow.act', $exec), ['action' => 'requestDocs'])->assertRedirect();
        $this->assertGreaterThan(0, $exec->documents()->count());

        // إحالة لقسم التنفيذ (المحامي) → قيد الدراسة (stage 2)
        $this->actingAs($employee)->post(route('exec-flow.act', $exec), ['action' => 'refer'])->assertRedirect();
        $this->assertSame(2, $exec->fresh()->stage);
    }
}
