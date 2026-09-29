<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **فتح تنفيذ الحكم بطلبٍ تعتمده الإدارة العليا** (قرار المالك 2026-09-29) — كان زرّ المحامي يفتح ملفّ
 * التنفيذ مباشرةً. الآن المحامي المسنَد أو الموظّف يرفع الطلب بسببه، والإدارة تعتمده أو ترفضه، وكلّ خطوةٍ
 * سطرٌ باسم فاعلها في سجلّ الانتقالات.
 */
class CaseExecutionRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function ruledCase(?User $lawyer = null): LegalCase
    {
        $lawyer ??= $this->lawyer;

        return LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-EXR-'.uniqid(), 'title' => 'دعوى', 'type' => 'نزاع تجاري',
            'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'update_text' => '—', 'ruling' => 'إلزام بالمبلغ',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);
    }

    private function request(User $by, LegalCase $case, string $reason = 'امتنع المحكوم عليه عن السداد بعد القطعيّة')
    {
        $prefix = $by->role->value;

        return $this->actingAs($by)->post(route("{$prefix}.cases.execution-request", $case), ['reason' => $reason]);
    }

    public function test_lawyer_request_waits_for_admin_and_is_logged(): void
    {
        $case = $this->ruledCase();

        $this->request($this->lawyer, $case)->assertRedirect()->assertSessionHas('flash');

        $case->refresh();
        $this->assertNotNull($case->execution_requested_at);
        $this->assertSame($this->lawyer->id, $case->execution_requested_by);
        $this->assertFalse(Execution::where('case_id', $case->id)->exists(), 'لا يُفتح قبل الاعتماد');
        $row = JourneyTransition::where('entity_id', $case->id)->where('transition', 'case.request_execution')->sole();
        $this->assertSame($this->lawyer->id, $row->actor_id);
        $this->assertStringContainsString('امتنع المحكوم عليه', (string) $row->reason);
        $this->assertTrue(UserNotification::where('user_id', $this->admin->id)->where('body', 'like', '%بانتظار اعتمادك%')->exists());
        // الطلب ملاحظةٌ داخليّة — لا تصل العميل
        $this->assertSame(0, UserNotification::where('user_id', $this->client->id)->count());
        $this->assertFalse($case->messages()->visibleTo(true)->where('role', 'طلب تنفيذ')->exists());

        // ولا يُكرَّر وهو قائم، ولا يُرفع بلا سببٍ كافٍ
        $this->request($this->lawyer, $case)->assertStatus(422);
        $this->request($this->lawyer, $this->ruledCase(), 'قصير')->assertStatus(422);
    }

    public function test_admin_rejects_with_reason_then_the_request_can_be_raised_again(): void
    {
        $case = $this->ruledCase();
        $this->request($this->lawyer, $case);

        $this->actingAs($this->admin)->post(route('admin.cases.execution-request.reject', $case), ['reason' => 'لم تكتسب القطعيّة بعد'])->assertRedirect();

        $case->refresh();
        $this->assertNull($case->execution_requested_at);
        $this->assertFalse(Execution::where('case_id', $case->id)->exists());
        $row = JourneyTransition::where('entity_id', $case->id)->where('transition', 'case.reject_execution_request')->sole();
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertTrue(UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', '%لم تكتسب القطعيّة%')->exists());

        $this->request($this->lawyer, $case)->assertRedirect();
    }

    public function test_admin_approval_opens_the_file_and_informs_the_requester(): void
    {
        $case = $this->ruledCase();
        $this->request($this->lawyer, $case);

        $this->actingAs($this->admin)->post(route('admin.cases.execution-request.approve', $case))->assertRedirect();

        $exec = Execution::where('case_id', $case->id)->sole();
        $this->assertSame($this->lawyer->id, $exec->assigned_lawyer_id);
        $this->assertNull($case->fresh()->execution_requested_at);
        $this->assertSame($this->admin->id, JourneyTransition::where('entity_id', $case->id)->where('transition', 'case.approve_execution_request')->sole()->actor_id);
        $this->assertTrue(UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', "%{$exec->number}%")->exists());
    }

    public function test_employee_raises_it_and_others_are_refused(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = $this->ruledCase();

        $this->request($employee, $case)->assertRedirect();
        $this->assertSame($employee->id, $case->fresh()->execution_requested_by);

        // محامٍ غير المسنَد، وموظّفٌ بلا الصلاحيّة، والعميل — لا يرفعون الطلب
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $other->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $second = $this->ruledCase();
        $this->request($other, $second)->assertForbidden();
        $plain = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $plain->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());
        $this->assertPageRefused($this->request($plain, $second));
        $this->actingAs($this->client)->post('/lawyer/cases/'.$second->number.'/execution-request', ['reason' => 'محاولة من العميل'])->assertRedirect();
        $this->assertNull($second->fresh()->execution_requested_at);
        // ولا يعتمد غير الإدارة
        $this->actingAs($this->lawyer)->post('/admin/cases/'.$case->number.'/execution-request/approve')->assertRedirect();
        $this->assertFalse(Execution::where('case_id', $case->id)->exists());
    }

    public function test_approvals_center_lists_it_and_direct_open_resolves_it(): void
    {
        $case = $this->ruledCase();
        $this->request($this->lawyer, $case);

        $this->actingAs($this->admin)->get(route('admin.approvals'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.executions', 1)
            ->where('executionRequests.0.no', $case->number)
            ->where('executionRequests.0.by', $this->lawyer->name));

        // الفتح المباشر من الإدارة يعتمد الطلب القائم فلا يبقى معلّقاً
        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case))->assertRedirect();
        $this->assertNull($case->fresh()->execution_requested_at);
        $this->assertTrue(JourneyTransition::where('entity_id', $case->id)->where('transition', 'case.approve_execution_request')->exists());
    }
}
