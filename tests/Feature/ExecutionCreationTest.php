<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فتح طلب تنفيذ من قضية بلغت «صدر الحكم».
 */
class ExecutionCreationTest extends TestCase
{
    use RefreshDatabase;

    private function ruledCase(User $client, ?User $lawyer = null): LegalCase
    {
        return LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-0900',
            'type' => 'نزاع تجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'صدر الحكم',
            'tone' => 'b-cyan',
            'ruling' => 'إلزام المدّعى عليه بالمبلغ.',
        ]);
    }

    public function test_lawyer_opens_execution_from_ruled_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->ruledCase($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.cases.execute', $case))->assertRedirect();

        $exec = Execution::where('case_id', $case->id)->first();
        $this->assertNotNull($exec);
        $this->assertSame($client->id, $exec->user_id);
        // قرار المالك 2026-09-12: يبدأ من **الأتعاب** لا من «قيد التنفيذ» — والحكم سندٌ مقبول سلفاً
        $this->assertSame('تحديد الأتعاب', $exec->status);
        $this->assertSame(3, (int) $exec->stage);
        $this->assertSame('مقبول', $exec->decision);
        $this->assertSame('حكم قضائي', $exec->sanad);
        $this->assertMatchesRegularExpression('/^EXE-\d{4}-\d{4}$/', $exec->number);
        // القضية مسندة للمحامي الذي فتح التنفيذ → يرث الطلب محاميها (حساب حقيقي لا اسم مثبّت)
        $this->assertSame($lawyer->name, $exec->assigned_lawyer);
        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id);

        // إشعار للعميل + رسالة في محادثة القضية + ظهوره لدى العميل
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'تنفيذ'));
        $this->actingAs($client)->get(route('execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('execs', 1));
    }

    /**
     * **الأتعاب أوّل مرحلة** (قرار المالك 2026-09-12): المحامي يحدّدها ثمّ تعتمدها الإدارة،
     * فيصل العميلَ العرض ويسدّد — كطلب العميل تماماً، بلا استقبالٍ ولا تحليلٍ ولا دراسة.
     */
    public function test_the_office_is_told_to_price_the_new_execution(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = $this->ruledCase($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.cases.execute', $case))->assertRedirect();

        $exec = Execution::where('case_id', $case->id)->firstOrFail();
        $this->assertSame(3, (int) $exec->stage);
        // المحامي المسنَد يُبلَّغ بأنّ عليه التسعير، والإدارة بأنّها ستعتمده
        $this->assertTrue(UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%بانتظار تحديد الأتعاب%')->exists());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار تحديد الأتعاب واعتمادها%')->exists());
        // والعميل يُبلَّغ أنّ عرض الأتعاب قادم — لا أنّ التنفيذ جارٍ
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('body', 'like', '%عرض أتعاب التنفيذ%')->exists());
    }

    public function test_execution_inherits_case_lawyer_when_linked(): void
    {
        $caseLawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->ruledCase(User::factory()->create(['role' => Role::Client]), $caseLawyer);

        // العزل: محامي القضية وحده يفتح تنفيذها، فيرث الطلب محامي القضية المخزّن
        $this->actingAs($caseLawyer)->post(route('lawyer.cases.execute', $case))->assertRedirect();

        $exec = Execution::where('case_id', $case->id)->firstOrFail();
        $this->assertSame($caseLawyer->id, $exec->assigned_lawyer_id);
        $this->assertSame($caseLawyer->name, $exec->assigned_lawyer);
    }

    public function test_admin_opens_execution_from_ruled_case_without_assignment(): void
    {
        // الإدارة العليا مطلقة الصلاحية — تفتح تنفيذ أي قضية محكومة بلا قيد إسناد (خلافاً للمحامي)
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = $this->ruledCase(User::factory()->create(['role' => Role::Client]));

        $res = $this->actingAs($admin)->post(route('admin.cases.execute', $case));

        $exec = Execution::where('case_id', $case->id)->first();
        $this->assertNotNull($exec);
        // الوجهة الملفّ المفتوح نفسه (`?id=`) لا القائمة كلّها
        $res->assertRedirect(route('admin.execs', ['id' => $exec->number]));
    }

    public function test_admin_cannot_open_execution_before_ruling(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = $this->ruledCase(User::factory()->create(['role' => Role::Client]));
        $case->update(['status' => 'منظورة']);

        $this->actingAs($admin)->post(route('admin.cases.execute', $case))->assertStatus(422);
    }

    public function test_cannot_open_execution_before_ruling(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->ruledCase(User::factory()->create(['role' => Role::Client]), $lawyer);
        $case->update(['status' => 'منظورة']);

        $this->actingAs($lawyer)->post(route('lawyer.cases.execute', $case))->assertStatus(422);
    }

    public function test_cannot_open_execution_twice(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = $this->ruledCase(User::factory()->create(['role' => Role::Client]), $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.cases.execute', $case))->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.cases.execute', $case))->assertStatus(422);
        $this->assertSame(1, Execution::where('case_id', $case->id)->count());
    }
}
