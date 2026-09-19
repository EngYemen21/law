<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **الموظّف يدير إجراءات المحكمة** (قرار المالك 2026-09-11): تسجيل الرفع في ناجز والقيد، وجدولة
 * الجلسات وتحديثها وإلغاؤها، وتسجيل الحكم — بالحرّاس نفسها التي تحرس المحامي (مصدرٌ واحد:
 * `ManagesCourtProceedings`)، وبوسم المكتب في المحادثة، ويُبلَّغ المحامي المسنَد بما سُجّل في ملفّه.
 */
class EmployeeCourtActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** افتراضاً: الصلاحيّتان كما تمنحهما الإدارة من تبويب الموظّفين. */
    private function employee(array $perms = ['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات']): User
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $employee;
    }

    /** @return array{0: LegalCase, 1: User, 2: User} */
    private function approvedCase(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-8101', 'type' => 'تجاري',
            'status' => 'قيد التحضير', 'tone' => 'b-amber', 'pleading_status' => 'approved',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        return [$case, $lawyer, $client];
    }

    private function registration(): array
    {
        return [
            'case_no' => '4700555111', 'court' => 'المحكمة التجارية بالرياض', 'circuit' => 'الدائرة التجارية الخامسة',
            'registered_at' => now()->toDateString(), 'hearing_day' => now()->addDays(10)->toDateString(),
            'hearing_time' => '09:00', 'hearing_mode' => 'حضورية',
        ];
    }

    private function lawyerWasTold(User $lawyer, string $like): bool
    {
        return DB::table('user_notifications')->where('user_id', $lawyer->id)->where('body', 'like', $like)->exists();
    }

    /** موظّفٌ منحته الإدارة «تسجيل الأحكام» أيضاً — الحكم صار صلاحيّةً مستقلّة (قرار 2026-09-18). */
    private function rulingEmployee(): User
    {
        return $this->employee(['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات', Permissions::RECORD_RULINGS]);
    }

    public function test_the_employee_runs_the_court_journey_end_to_end(): void
    {
        [$case, $lawyer, $client] = $this->approvedCase();
        $employee = $this->rulingEmployee();

        // ١) الرفع في ناجز ⇐ «بانتظار القيد»، بوسم المكتب لا المحامي
        $this->actingAs($employee)->post(route('employee.cases.najiz.file', $case), ['request_no' => 'NJ-8101', 'filed_at' => now()->toDateString()])
            ->assertRedirect();
        $case->refresh();
        $this->assertSame('بانتظار القيد', $case->status);
        $this->assertSame('staff', $case->messages()->where('role', 'رفع الدعوى')->value('who'));
        $this->assertTrue($this->lawyerWasTold($lawyer, '%NJ-8101%'), 'المحامي المسنَد يُبلَّغ بما سُجّل في ملفّه');

        // ٢) القيد ⇐ «منظورة» والجلسة الأولى بمسار الجدولة نفسه
        $this->actingAs($employee)->post(route('employee.cases.najiz.register', $case), $this->registration())->assertRedirect();
        $case->refresh();
        $this->assertSame('منظورة', $case->status);
        $this->assertSame('4700555111', $case->najiz_case_no);
        $first = $case->hearings()->firstOrFail();
        $this->assertSame('الجلسة الأولى — حضورية', $first->title);
        $this->assertTrue(DB::table('user_notifications')->where('user_id', $client->id)->where('body', 'like', '%قُيّدت%4700555111%')->exists());

        // ٣) جلسةٌ ثانية، ثم إعادة جدولتها، ثم إلغاؤها
        $this->actingAs($employee)->post(route('employee.cases.hearings.add', $case), ['title' => 'جلسة المرافعة', 'day' => now()->addDays(20)->toDateString(), 'time' => '10:00'])
            ->assertRedirect();
        $second = $case->hearings()->where('title', 'جلسة المرافعة')->firstOrFail();
        $this->actingAs($employee)->post(route('employee.cases.hearings.update', [$case, $second]), ['title' => 'جلسة المرافعة', 'day' => now()->addDays(25)->toDateString(), 'time' => '11:00'])
            ->assertRedirect();
        $this->assertSame('11:00', $second->fresh()->starts_at?->format('H:i'));
        $this->actingAs($employee)->post(route('employee.cases.hearings.cancel', [$case, $second]))->assertRedirect();
        $this->assertSame('ملغاة', $second->fresh()->status);

        // ٤) نتيجة الجلسة الأولى
        $this->actingAs($employee)->post(route('employee.cases.hearings.record', [$case, $first]), ['status' => 'منعقدة', 'outcome' => 'قُدّمت المذكرة'])
            ->assertRedirect();
        $this->assertSame('منعقدة', $first->fresh()->status);

        // ٥) الحكم ⇐ «صدر الحكم» — ولا يُسجَّل مرّةً ثانية
        $this->actingAs($employee)->post(route('employee.cases.ruling', $case), ['ruling' => 'حكمت الدائرة بفسخ العقد وإلزام المدعى عليها بالردّ.'])
            ->assertRedirect();
        $this->assertSame('صدر الحكم', $case->fresh()->status);
        $this->actingAs($employee)->post(route('employee.cases.ruling', $case), ['ruling' => 'مرّة ثانية'])->assertStatus(422);

        // كلّ رسائل الإجراءات بوسم المكتب واسم الموظّف
        $this->assertSame(0, $case->messages()->whereIn('role', ['رفع الدعوى', 'قيد الدعوى', 'جلسة', 'الحكم'])->where('who', '!=', 'staff')->count());
        $this->assertSame($employee->name, $case->messages()->where('role', 'الحكم')->value('name'));
        $this->assertTrue($this->lawyerWasTold($lawyer, '%صدور الحكم%'));
    }

    public function test_the_same_guards_hold_for_the_employee(): void
    {
        [$case] = $this->approvedCase();
        $employee = $this->rulingEmployee();

        // لا قيد قبل الرفع، ولا جلسة ولا حكم قبل القيد
        $this->actingAs($employee)->post(route('employee.cases.najiz.register', $case), $this->registration())->assertStatus(422);
        $this->actingAs($employee)->post(route('employee.cases.hearings.add', $case), ['title' => 'x', 'day' => now()->addWeek()->toDateString()])->assertStatus(422);
        $this->actingAs($employee)->post(route('employee.cases.ruling', $case), ['ruling' => 'x'])->assertStatus(422);

        // ولا رفع قبل الاعتماد النهائيّ للّائحة
        $case->update(['pleading_status' => 'pending_lawyer']);
        $this->actingAs($employee)->post(route('employee.cases.najiz.file', $case), ['request_no' => 'NJ-1', 'filed_at' => now()->toDateString()])->assertStatus(422);

        // والمؤرشفة للقراءة
        $case->update(['status' => 'مؤرشفة']);
        $hearing = $case->hearings()->create(['title' => 'قديمة', 'day' => '2026-01-01', 'status' => 'مجدولة']);
        $this->actingAs($employee)->post(route('employee.cases.hearings.cancel', [$case, $hearing]))->assertStatus(422);
    }

    public function test_an_employee_without_the_case_permission_is_refused(): void
    {
        [$case] = $this->approvedCase();
        $stranger = $this->employee([]);
        $payload = ['request_no' => 'NJ-2', 'filed_at' => now()->toDateString()];

        // عُرف `EnsurePermission`: الصفحة تُعاد للوحته بخطأ، ونداء JSON يُرفض 403 صريحاً
        $this->actingAs($stranger)->post(route('employee.cases.najiz.file', $case), $payload)
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($stranger)->postJson(route('employee.cases.najiz.file', $case), $payload)->assertForbidden();
        $this->actingAs($stranger)->postJson(route('employee.cases.ruling', $case), ['ruling' => 'x'])->assertForbidden();

        $this->assertSame('قيد التحضير', $case->fresh()->status);
        $this->assertNull($case->fresh()->najiz_request_no);
    }

    /**
     * **الصلاحيّة تمنحها الإدارة من تبويب الموظّفين.** من يملك «إدارة القضايا والأتعاب» وحدها يفتح
     * القضيّة ويرى بيانات ناجز للاطّلاع، بلا نماذج — والمسارات ترفضه.
     */
    public function test_court_actions_need_the_permission_granted_from_the_staff_tab(): void
    {
        [$case] = $this->approvedCase();
        $viewer = $this->employee(['إدارة القضايا والأتعاب']);

        $this->actingAs($viewer)->get(route('employee.cases.show', $case))->assertOk()->assertInertia(fn ($p) => $p
            ->where('canCourt', false)
            ->where('filing.canFile', false)
            ->where('filing.canRegister', false)
        );

        $this->actingAs($viewer)->postJson(route('employee.cases.najiz.file', $case), ['request_no' => 'NJ-3', 'filed_at' => now()->toDateString()])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('employee.cases.hearings.add', $case), ['title' => 'x', 'day' => now()->addWeek()->toDateString()])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('employee.cases.ruling', $case), ['ruling' => 'x'])->assertForbidden();
        $this->assertSame('قيد التحضير', $case->fresh()->status);

        // سقفٌ للموظّف تمنحه الإدارة — لا في قالب «خدمة عملاء»، ولا تلقائيّاً
        $this->assertContains('إجراءات المحكمة والجلسات', Permissions::ROLE_PERMISSIONS['employee']);
        $this->assertNotContains('إجراءات المحكمة والجلسات', Permissions::PRESETS['خدمة عملاء']);
        $this->assertContains('إجراءات المحكمة والجلسات', Permissions::GROUPS['القضايا والمالية والإدارة']);
    }

    /**
     * **الحكم أضيق من بقيّة الإجراءات** (قرار المالك 2026-09-18). كان حاملُ «إجراءات المحكمة
     * والجلسات» يسجّل حكماً ويصحّحه على أيّ قضيّة؛ فصار يلزمه «تسجيل الأحكام» — وبقيّة الإجراءات كما هي.
     */
    public function test_recording_a_ruling_needs_its_own_permission(): void
    {
        [$case] = $this->approvedCase();
        $court = $this->employee(); // المحكمة بلا الأحكام

        // الرفع والقيد يمضيان كما كانا
        $this->actingAs($court)->post(route('employee.cases.najiz.file', $case), ['request_no' => 'NJ-R1', 'filed_at' => now()->toDateString()])->assertRedirect();
        $this->actingAs($court)->post(route('employee.cases.najiz.register', $case), $this->registration())->assertRedirect();
        $this->assertSame('منظورة', $case->fresh()->status);

        // والحكم يُردّ بسببٍ مفهوم، ولا يترك أثراً
        $this->actingAs($court)->post(route('employee.cases.ruling', $case), ['ruling' => 'حكمٌ من غير مخوَّل'])
            ->assertForbidden()->assertSee('تسجيل الأحكام');
        $this->assertSame('منظورة', $case->fresh()->status);
        $this->assertNull($case->fresh()->ruling);

        // والشاشة لا تعرض نموذجاً يردّه الخادم
        $this->actingAs($court)->get(route('employee.cases.show', $case))->assertInertia(fn ($p) => $p
            ->where('canCourt', true)->where('canRule', false));

        // ومن منحته الإدارة الصلاحيّة يسجّله؛ ثمّ التصحيح يلزمه الصلاحيّة نفسها
        $ruler = $this->rulingEmployee();
        $this->actingAs($ruler)->get(route('employee.cases.show', $case))->assertInertia(fn ($p) => $p->where('canRule', true));
        $this->actingAs($ruler)->post(route('employee.cases.ruling', $case), ['ruling' => 'حكمت الدائرة برفض الدعوى.'])->assertRedirect();
        $this->assertSame('صدر الحكم', $case->fresh()->status);

        $this->actingAs($court)->post(route('employee.cases.ruling.correct', $case), ['ruling' => 'تصحيح', 'reason' => 'خطأ كتابي'])->assertForbidden();
        $this->actingAs($court)->post(route('employee.cases.appeal.ruling', $case), ['appeal_outcome' => 'نقض الحكم', 'appeal_ruling' => 'x', 'appeal_judged_at' => now()->toDateString()])->assertForbidden();
        $this->assertSame('حكمت الدائرة برفض الدعوى.', $case->fresh()->ruling);

        // سقفٌ للموظّف لا يُمنح تلقائيّاً (والمحامي خارجه: يسجّل بإسناده — `Lawyer\CaseController::guardCourtAccess`)
        $this->assertNotContains(Permissions::RECORD_RULINGS, Permissions::ROLE_PERMISSIONS['lawyer']);
        $this->assertContains(Permissions::RECORD_RULINGS, Permissions::ROLE_PERMISSIONS['employee']);
        $this->assertNotContains(Permissions::RECORD_RULINGS, Permissions::PRESETS['خدمة عملاء']);
    }

    public function test_the_employee_page_offers_what_the_guards_allow(): void
    {
        [$case] = $this->approvedCase();

        $this->actingAs($this->employee())->get(route('employee.cases.show', $case))->assertInertia(fn ($p) => $p
            ->where('canCourt', true)
            ->where('filing.canFile', true)
            ->where('filing.canRegister', false)
        );

        $ui = (string) file_get_contents(resource_path('js/pages/employee/case.tsx'));
        foreach (['<NajizFilingCard', '<ScheduleHearingCard', '<RulingCard', '<HearingUpdatesCard'] as $card) {
            $this->assertStringContainsString($card, $ui, $card);
        }
    }

    public function test_the_lawyer_keeps_his_own_label_and_is_not_told_about_himself(): void
    {
        [$case, $lawyer] = $this->approvedCase();

        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-9', 'filed_at' => now()->toDateString()])->assertRedirect();

        $this->assertSame('lawyer', $case->messages()->where('role', 'رفع الدعوى')->value('who'));
        $this->assertFalse($this->lawyerWasTold($lawyer, '%NJ-9%'));
    }
}
