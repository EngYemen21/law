<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Execution\RecordExecutionCollection;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExecCollectionCeilingAndDeepLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function lawyer(): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        return $lawyer;
    }

    private function employee(): User
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إجراءات المحكمة والجلسات', 'إدارة القضايا والأتعاب'])->get());

        return $employee;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'status' => 'active']);
    }

    private function registeredExec(User $client, User $lawyer, int $amount = 100000, int $collected = 0): Execution
    {
        return Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-C-'.uniqid(),
            'subject' => 'تنفيذ شيك بدون رصيد',
            'sanad' => 'شيك',
            'amount' => $amount,
            'collected' => $collected,
            'stage' => 8,
            'status' => ExecFlow::label(8),
            'tone' => ExecFlow::tone(8),
            'paid' => true,
            'exec_no' => 'EXE-REG-'.uniqid(),
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'registered_at' => now()->subDays(5)->toDateString(),
            'court' => 'محكمة التنفيذ بالرياض',
            'circuit' => 'الدائرة الأولى',
        ]);
    }

    /** 1. التحقق من سقف مبلغ التحصيل عند تجاوزه المتبقي من قيمة المطالبة */
    public function test_collection_amount_cannot_exceed_remaining_claim_balance(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer, amount: 100000, collected: 40000);

        // المتبقي: 60,000 ريال — محاولة تحصيل 70,000 تُرفض بـ 422
        $response = $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'addCollection',
            'amount' => 70000,
        ]);

        $response->assertStatus(422);
        $this->assertSame(40000, (int) $exec->fresh()->collected);
    }

    /** 2. التحقق من منع تسجيل أي تحصيل إضافي عند اكتمال تحصيل كامل المطالبة (100%) */
    public function test_cannot_add_collection_when_file_is_already_fully_collected(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer, amount: 50000, collected: 50000);

        // تم تحصيل كامل المبلغ — أي محاولة تحصيل إضافية تُرفض
        $response = $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'addCollection',
            'amount' => 1000,
        ]);

        $response->assertStatus(422);
        $this->assertSame(50000, (int) $exec->fresh()->collected);
    }

    /** 3. تسجيل تحصيل نظامي ضمن السقف حتى استيفاء كامل المبلغ */
    public function test_valid_partial_and_full_collection_succeeds_up_to_exact_ceiling(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer, amount: 100000, collected: 0);

        // تحصيل جزئي: 60,000 من 100,000
        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'addCollection',
            'amount' => 60000,
            'note' => 'حجز مصرفي جزئي',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(60000, (int) $exec->collected);

        // تحصيل باقي المبلغ بالكامل: 40,000
        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'addCollection',
            'amount' => 40000,
            'note' => 'استيفاء باقي المطالبة',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(100000, (int) $exec->collected);

        // محاولة إضافة ريال واحد إضافي تُرفض الآن بعد اكتمال السقف
        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'addCollection',
            'amount' => 1,
        ])->assertStatus(422);
    }

    /** 4. التحقق من رفض الحارس المباشر في RecordExecutionCollection */
    public function test_record_execution_collection_guard_enforces_ceiling(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer, amount: 80000, collected: 60000);

        $transition = new RecordExecutionCollection;

        // متبقي 20,000 — فحص 25,000
        $whyExceeds = $transition->guard($exec, ['amount' => 25000]);
        $this->assertNotNull($whyExceeds);
        $this->assertStringContainsString('يتجاوز المتبقي', $whyExceeds);

        // متبقي 20,000 — فحص 20,000 سليم
        $this->assertNull($transition->guard($exec, ['amount' => 20000]));

        // بعد استيفاء 80,000 بالكامل
        $exec->collected = 80000;
        $whyFully = $transition->guard($exec, ['amount' => 1000]);
        $this->assertNotNull($whyFully);
        $this->assertStringContainsString('كامل قيمة المطالبة', $whyFully);
    }

    /** 5. التوجيه المباشر بالرابط (Deep Linking) للعميل مع تمرير initialId و initialTab */
    public function test_deep_linking_passes_initial_id_and_tab_for_client(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer);

        $response = $this->actingAs($client)->get(route('execs', [
            'id' => $exec->number,
            'tab' => 'finance',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('execflow')
            ->where('role', 'client')
            ->where('initialId', $exec->number)
            ->where('initialTab', 'finance')
            ->has('execs')
        );
    }

    /** 6. التوجيه المباشر بالرابط (Deep Linking) للمحامي والإدارة والموظف */
    public function test_deep_linking_passes_initial_id_and_tab_for_staff_roles(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();
        $employee = $this->employee();
        $exec = $this->registeredExec($client, $lawyer);

        // المحامي
        $this->actingAs($lawyer)->get(route('lawyer.execs', ['id' => $exec->number, 'tab' => 'docs']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('execflow')
                ->where('role', 'lawyer')
                ->where('initialId', $exec->number)
                ->where('initialTab', 'docs')
            );

        // الإدارة
        $this->actingAs($admin)->get(route('admin.execs', ['id' => $exec->number, 'tab' => 'chat']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('execflow')
                ->where('role', 'admin')
                ->where('initialId', $exec->number)
                ->where('initialTab', 'chat')
            );

        // الموظف
        $this->actingAs($employee)->get(route('employee.execs', ['id' => $exec->number, 'tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('execflow')
                ->where('role', 'employee')
                ->where('initialId', $exec->number)
                ->where('initialTab', 'overview')
            );
    }

    /** 7. بطاقة FlowCard تتضمن rawId للربط المرن مع المعرّف العددي */
    public function test_flow_card_includes_raw_id_for_deep_linking_flexibility(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $exec = $this->registeredExec($client, $lawyer);

        $card = $exec->toFlowCard(false, true);

        $this->assertArrayHasKey('id', $card);
        $this->assertArrayHasKey('rawId', $card);
        $this->assertSame($exec->number, $card['id']);
        $this->assertSame($exec->id, $card['rawId']);
    }
}
