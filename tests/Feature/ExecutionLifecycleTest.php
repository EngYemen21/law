<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دورة حياة طلب التنفيذ: تجهيز السند → القيد لدى محكمة التنفيذ → إجراءات → التحصيل → إغلاق الإدارة.
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

    public function test_client_message_gets_ai_reply(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);

        $this->actingAs($client)->post(route('execs.messages.store', $exec), ['body' => 'ما مستجدات التنفيذ؟'])->assertNoContent();

        $msgs = $exec->messages()->get();
        $this->assertSame('ما مستجدات التنفيذ؟', $msgs[$msgs->count() - 2]->body);
        $this->assertSame('ai', $msgs->last()->who);
    }

    public function test_client_creates_direct_execution(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('execs.store'), ['subject' => 'تنفيذ سند لأمر', 'details' => 'سند بمبلغ.'])
            ->assertRedirect();
        $exec = Execution::firstOrFail();
        $this->assertSame($client->id, $exec->user_id);
        $this->assertSame('جديد', $exec->status);
        $this->assertCount(2, $exec->messages); // رسالة العميل + ردّ التنفيذ
    }

    public function test_full_lawyer_lifecycle_then_admin_close(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $lawyer->id]);

        // تجهيز السند
        $this->actingAs($lawyer)->post(route('lawyer.execs.instrument', $exec))->assertRedirect();
        $this->assertSame('تجهيز السند التنفيذي', $exec->fresh()->status);

        // القيد لدى المحكمة
        $this->actingAs($lawyer)->post(route('lawyer.execs.court', $exec), ['court' => 'محكمة التنفيذ بالرياض'])->assertRedirect();
        $exec->refresh();
        $this->assertSame('مقيّد لدى محكمة التنفيذ', $exec->status);
        $this->assertSame('محكمة التنفيذ بالرياض', $exec->court);

        // إضافة إجراء (حجز)
        $this->actingAs($lawyer)->post(route('lawyer.execs.procedures.add', $exec), ['title' => 'حجز تحفظي', 'type' => 'حجز'])->assertRedirect();
        $exec->refresh();
        $this->assertSame('جارٍ', $exec->status);
        $proc = $exec->procedures()->firstOrFail();

        // العميل يرى الإجراء
        $this->actingAs($client)->get(route('execs.show', $exec))
            ->assertInertia(fn ($p) => $p->has('procedures', 1));

        // تسجيل نتيجة الإجراء
        $this->actingAs($lawyer)->post(route('lawyer.execs.procedures.record', [$exec, $proc]), ['status' => 'منفّذ'])->assertRedirect();
        $this->assertSame('منفّذ', $proc->fresh()->status);

        // التحصيل والإكمال
        $this->actingAs($lawyer)->post(route('lawyer.execs.complete', $exec))->assertRedirect();
        $this->assertSame('مكتمل', $exec->fresh()->status);

        // إغلاق الإدارة
        $this->actingAs($admin)->post(route('admin.execs.close', $exec))->assertRedirect();
        $this->assertSame('مغلق', $exec->fresh()->status);
    }

    public function test_role_guards_block_out_of_order_actions(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = $this->execFor(User::factory()->create(['role' => Role::Client]), ['assigned_lawyer_id' => $lawyer->id]);

        // لا يمكن القيد قبل تجهيز السند
        $this->actingAs($lawyer)->post(route('lawyer.execs.court', $exec), ['court' => 'x'])->assertStatus(422);
        // لا يمكن الإغلاق قبل الإكمال
        $this->actingAs($admin)->post(route('admin.execs.close', $exec))->assertStatus(422);
    }

    public function test_employee_oversees_and_replies(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $exec = $this->execFor(User::factory()->create(['role' => Role::Client]));

        $this->actingAs($employee)->get(route('employee.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('employee/execs')->has('execs', 1));
        $this->actingAs($employee)->post(route('employee.execs.reply', $exec), ['body' => 'نتابع التنفيذ.'])->assertNoContent();
        $this->assertTrue($exec->messages->contains(fn ($m) => $m->who === 'staff'));
    }
}
