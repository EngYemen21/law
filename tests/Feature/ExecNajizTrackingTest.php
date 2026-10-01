<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFlow;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **مسار التنفيذ في ناجز داخل المرحلتين 7 و8** (قرار المالك 2026-09-12): رفع الطلب ← القيد لدى
 * محكمة التنفيذ ← الإبلاغ بأمر التنفيذ ومهلة الوفاء ← إجراءات عدم الوفاء ← التحصيل ← الإنهاء
 * بسببه. كان كلّ هذا نصّاً حرّاً في «إجراء»، فلا رقمَ طلبٍ ولا مهلةً تُحسب ولا متبقّياً يُعرف.
 *
 * الخطوات تمرّ بموزّع الإجراءات نفسه (`exec-flow.act`) — لا مسارات ولا حرّاس مكرّرة.
 */
class ExecNajizTrackingTest extends TestCase
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

    /**
     * موظّفٌ بصلاحيّاته — «إجراءات المحكمة والجلسات» هي ما تمنحه الإدارة من تبويب الموظّفين
     * ليسجّل خطوات المحكمة (قرار المالك 2026-09-12)، كما في القضايا تماماً.
     *
     * @param  array<int, string>  $perms
     */
    private function employee(array $perms = ['إجراءات المحكمة والجلسات']): User
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $employee;
    }

    /** ملفٌّ سُدّدت أتعابه: المرحلة 7 «بانتظار الرفع في ناجز». */
    private function openFile(User $client, User $lawyer, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-N-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'stage' => 7,
            'status' => ExecFlow::label(7),
            'tone' => ExecFlow::tone(7),
            'paid' => true,
            'exec_no' => 'EXE-TN-'.uniqid(), // فريد: العمود مفهرسٌ بالتفرّد، والاختبار يفتح ملفّين
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ], $attrs));
    }

    private function act(User $actor, Execution $exec, string $action, array $payload = [])
    {
        return $this->actingAs($actor)->post(route('exec-flow.act', $exec), array_merge(['action' => $action], $payload));
    }

    public function test_the_office_records_the_whole_najiz_journey(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer);

        // ١) الرفع ⇐ «قيد التنفيذ»
        $this->act($lawyer, $exec, 'fileNajiz', ['request_no' => '2026-778812', 'filed_at' => now()->toDateString()])->assertRedirect();
        $exec->refresh();
        $this->assertSame(8, (int) $exec->stage);
        $this->assertSame('2026-778812', $exec->najiz_request_no);
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('body', 'like', '%منصّة ناجز برقم الطلب%')->exists());

        // ٢) القيد لدى محكمة التنفيذ
        $this->act($lawyer, $exec, 'registerNajiz', ['court' => 'محكمة التنفيذ بالرياض', 'circuit' => 'الدائرة الثالثة', 'registered_at' => now()->toDateString()])->assertRedirect();
        $exec->refresh();
        $this->assertSame('محكمة التنفيذ بالرياض', $exec->court);
        $this->assertSame('الدائرة الثالثة', $exec->circuit);

        // ٣) الإبلاغ بأمر التنفيذ ⇐ تُحسب مهلة الوفاء (خمسة أيام تقويميّة قبل نفاذ النظام الجديد)
        $notified = now()->subDay();
        $this->act($lawyer, $exec, 'notifyDebtor', ['notified_at' => $notified->toDateString()])->assertRedirect();
        $exec->refresh();
        $this->assertSame($notified->copy()->startOfDay()->addDays(5)->toDateString(), $exec->pay_due_at?->toDateString());
        $this->assertFalse($exec->najizCard()['payDueOver'], 'المهلة لم تنقضِ بعد');

        // ٤) إجراءات عدم الوفاء — من القائمة وحدها
        $this->act($lawyer, $exec, 'applyMeasures', ['measures' => ['منع السفر', 'إيقاف الخدمات الحكومية']])->assertRedirect();
        $this->assertSame(['منع السفر', 'إيقاف الخدمات الحكومية'], $exec->fresh()->measures);
        $this->act($lawyer, $exec, 'applyMeasures', ['measures' => ['مصادرة المنزل']])->assertSessionHasErrors('measures.0');
        $this->assertSame(['منع السفر', 'إيقاف الخدمات الحكومية'], $exec->fresh()->measures, 'المرفوض لا يُكتب');

        // ٥) التحصيل: يُراكَم، ويظهر المتبقّي، ويُسجَّل في سجلّ الإجراءات
        $this->act($lawyer, $exec, 'addCollection', ['amount' => 25000, 'note' => 'حجز حساب بنكيّ'])->assertRedirect();
        $this->act($lawyer, $exec, 'addCollection', ['amount' => 15000])->assertRedirect();
        $exec->refresh();
        $this->assertSame(40000, (int) $exec->collected);
        $this->assertSame(2, $exec->procedures()->where('type', 'تحصيل')->count());
        $this->assertStringContainsString('المتبقّي', (string) $exec->messages()->where('role', 'تحصيل')->latest('id')->value('body'));

        // ٦) الإنهاء بسببه
        $this->act($lawyer, $exec, 'close', ['reason' => 'سداد كامل'])->assertRedirect();
        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertSame('سداد كامل', $exec->closed_reason);
    }

    public function test_the_steps_happen_in_order_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer);

        // لا قيد ولا إبلاغ قبل الرفع — حارس المرحلة يردّ بخطأ «stage» (كبقيّة انتقالات التدفّق)
        $this->act($lawyer, $exec, 'registerNajiz', ['court' => 'محكمة', 'circuit' => 'دائرة', 'registered_at' => now()->toDateString()])->assertSessionHasErrors('stage');
        $this->act($lawyer, $exec, 'notifyDebtor', ['notified_at' => now()->toDateString()])->assertSessionHasErrors('stage');

        $this->act($lawyer, $exec, 'fileNajiz', ['request_no' => 'NJ-1', 'filed_at' => now()->toDateString()])->assertRedirect();
        // بعد الرفع: الإبلاغ يلزمه القيد، والإجراءات تلزمها الإبلاغ
        $this->act($lawyer, $exec, 'notifyDebtor', ['notified_at' => now()->toDateString()])->assertStatus(422);
        $this->act($lawyer, $exec, 'applyMeasures', ['measures' => ['منع السفر']])->assertStatus(422);

        // ولا رفع قبل سداد الأتعاب (المرحلة 7)
        $early = $this->openFile($client, $lawyer, ['stage' => 6, 'status' => ExecFlow::label(6), 'paid' => false]);
        $this->act($lawyer, $early, 'fileNajiz', ['request_no' => 'NJ-2', 'filed_at' => now()->toDateString()])->assertSessionHasErrors('stage');
    }

    /** المهلة تصير أيام عمل من تاريخ نفاذ النظام الجديد، والتاريخ من الإعدادات. */
    public function test_the_deadline_switches_to_working_days_when_the_new_law_applies(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer, ['stage' => 8, 'najiz_request_no' => 'NJ-9', 'registered_at' => now()->subDays(3)->toDateString()]);

        // الإبلاغ يوم أربعاء: خمسة أيام عمل تتخطّى الجمعة والسبت ⇒ الأربعاء التالي
        $wednesday = now()->subWeek()->startOfWeek()->addDays(2);

        // النظام نافذ **يوم الإبلاغ** — الحكم بتاريخ الإبلاغ لا بيوم التسجيل (تدقيق الإعدادات 2026-09-30)
        Setting::put('exec_working_days_from', $wednesday->copy()->subDay()->toDateString());
        $this->assertTrue(ExecFlow::countsWorkingDays($wednesday));
        $this->act($lawyer, $exec, 'notifyDebtor', ['notified_at' => $wednesday->toDateString()])->assertRedirect();

        $due = $exec->fresh()->pay_due_at;
        $this->assertSame(7, (int) $wednesday->diffInDays($due), 'خمسة أيام عملٍ تعني سبعة تقويميّة هنا');
        $this->assertNotContains($due->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY]);
    }

    public function test_the_client_sees_the_journey_but_does_not_record_it(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer, [
            'stage' => 8, 'najiz_request_no' => 'NJ-77', 'najiz_filed_at' => now()->subDays(10)->toDateString(),
            'court' => 'محكمة التنفيذ بجدة', 'circuit' => 'الدائرة الأولى', 'registered_at' => now()->subDays(8)->toDateString(),
            'notified_at' => now()->subDays(7)->toDateString(), 'pay_due_at' => now()->subDays(2)->toDateString(),
            'measures' => ['منع السفر'], 'collected' => 10000,
        ]);

        $this->actingAs($client)->get(route('execs'))->assertInertia(fn ($p) => $p
            ->where('execs.0.najiz.requestNo', 'NJ-77')
            ->where('execs.0.najiz.court', 'محكمة التنفيذ بجدة')
            ->where('execs.0.najiz.payDueOver', true)
            ->where('execs.0.najiz.collected', 10000)
        );

        // ولا يسجّل شيئاً بنفسه
        $this->actingAs($client)->postJson(route('exec-flow.act', $exec), ['action' => 'addCollection', 'amount' => 5000])->assertForbidden();
        $this->assertSame(10000, (int) $exec->fresh()->collected);
    }

    /**
     * **الموظّف يسجّل خطوات ناجز متى مُنح صلاحيّتها** (قرار المالك 2026-09-12) — نظير القضايا.
     * وكان حارس الدور يسبق حارس الصلاحيّة، فلا منحٌ يفتح له شيئاً مهما فعلت الإدارة.
     */
    public function test_the_employee_records_the_najiz_steps_when_granted_the_court_permission(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
        $employee = $this->employee();
        $exec = $this->openFile($client, $lawyer);

        $this->act($employee, $exec, 'fileNajiz', ['request_no' => 'NJ-EMP-1', 'filed_at' => now()->toDateString()])->assertRedirect();
        $this->act($employee, $exec, 'registerNajiz', ['court' => 'محكمة التنفيذ بالرياض', 'circuit' => 'الدائرة الثانية', 'registered_at' => now()->toDateString()])->assertRedirect();
        $this->act($employee, $exec, 'notifyDebtor', ['notified_at' => now()->subDay()->toDateString()])->assertRedirect();
        $this->act($employee, $exec, 'applyMeasures', ['measures' => ['منع السفر']])->assertRedirect();
        $this->act($employee, $exec, 'addCollection', ['amount' => 5000])->assertRedirect();

        $exec->refresh();
        $this->assertSame('NJ-EMP-1', $exec->najiz_request_no);
        $this->assertSame('الدائرة الثانية', $exec->circuit);
        $this->assertNotNull($exec->pay_due_at);
        $this->assertSame(['منع السفر'], $exec->measures);
        $this->assertSame(5000, (int) $exec->collected);

        // وما سجّله غيرُ المحامي المسنَد يبلغه ويبلغ الإدارة — كان يُشعَر العميل وحده
        $trace = fn (int $id) => UserNotification::where('user_id', $id)->where('body', 'like', '%سجّله%')->exists();
        $this->assertTrue($trace($lawyer->id), 'المحامي المسنَد يعلم ما جرى على ملفّه');
        $this->assertTrue($trace($admin->id), 'والإدارة معه');

        // …وإغلاق الملفّ يبقى للمحامي والإدارة وحدهما
        $this->act($employee, $exec, 'close', ['reason' => 'سداد كامل'])->assertForbidden();
        $this->assertSame(8, (int) $exec->fresh()->stage);
    }

    /** بلا الصلاحيّة يُصدّ — و403 صريحة لنداء ajax (المسار بلا وسيط permission، فالحارس في المتحكّم). */
    public function test_the_employee_without_the_court_permission_is_refused(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->openFile($client, $this->lawyer());
        $employee = $this->employee(['إدارة القضايا والأتعاب']); // صلاحيّته المعتادة وحدها

        $this->actingAs($employee)->postJson(route('exec-flow.act', $exec), [
            'action' => 'fileNajiz', 'request_no' => 'NJ-X', 'filed_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertNull($exec->fresh()->najiz_request_no, 'لا يُكتب شيء لمن لا يملك');
    }

    /** المحامي المسنَد لا يُشعَر بفعل نفسه — الإشعار لمن لم يفعل. */
    public function test_the_lawyer_is_not_notified_of_his_own_step(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer);

        $this->act($lawyer, $exec, 'fileNajiz', ['request_no' => 'NJ-SELF', 'filed_at' => now()->toDateString()])->assertRedirect();

        $this->assertFalse(UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%سجّله%')->exists());
    }

    /**
     * **التحصيل بعد القيد كما تقول رسالته.** كان الحارس يفحص المرحلة وحدها ورسالتُه تَعِد
     * بالقيد، فيُسجَّل مبلغٌ محصَّل على ملفٍّ لم يُقيَّد لدى محكمة التنفيذ بعد.
     */
    public function test_a_collection_needs_the_registration_its_message_promises(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer, ['stage' => 8, 'najiz_request_no' => 'NJ-5']); // مرفوع بلا قيد

        $this->act($lawyer, $exec, 'addCollection', ['amount' => 5000])->assertStatus(422);
        $this->assertSame(0, (int) $exec->fresh()->collected);
    }

    /**
     * **السجلّ يحفظ ما كُتب فعلاً.** كان القيد المركزيّ يسجّل الحالة والمرحلة فقط، فلا يُعرف
     * منه رقمُ الطلب في ناجز ولا المحكمة ولا المبلغ المحصَّل — وهي المرجع الخارجيّ والمال.
     * والإثراء في النداء القائم نفسه: لا قيد ثانٍ لكلّ خطوة.
     */
    public function test_the_audit_trail_records_what_each_najiz_step_actually_wrote(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->lawyer();
        $exec = $this->openFile($client, $lawyer);

        $after = fn (string $action) => AuditLog::where('auditable_ref', $exec->number)
            ->where('action', 'إجراء على ملف تنفيذ: '.$action)
            ->latest('id')->firstOrFail()->after_state;

        $this->act($lawyer, $exec, 'fileNajiz', ['request_no' => '2026-4410', 'filed_at' => now()->toDateString()])->assertRedirect();
        $this->assertSame('2026-4410', $after('fileNajiz')['رقم الطلب في ناجز']);

        $this->act($lawyer, $exec, 'registerNajiz', ['court' => 'محكمة التنفيذ بالدمام', 'circuit' => 'الدائرة الرابعة', 'registered_at' => now()->toDateString()])->assertRedirect();
        $this->assertSame('محكمة التنفيذ بالدمام', $after('registerNajiz')['محكمة التنفيذ']);
        $this->assertSame('الدائرة الرابعة', $after('registerNajiz')['الدائرة']);

        $this->act($lawyer, $exec, 'notifyDebtor', ['notified_at' => now()->subDay()->toDateString()])->assertRedirect();
        $this->assertSame($exec->fresh()->pay_due_at?->toDateString(), $after('notifyDebtor')['نهاية مهلة الوفاء']);

        $this->act($lawyer, $exec, 'applyMeasures', ['measures' => ['منع السفر', 'الحبس التنفيذيّ']])->assertRedirect();
        $this->assertSame('منع السفر · الحبس التنفيذيّ', $after('applyMeasures')['إجراءات عدم الوفاء']);

        $this->act($lawyer, $exec, 'addCollection', ['amount' => 25000])->assertRedirect();
        $this->assertSame(25000, $after('addCollection')['إجمالي المحصَّل']);
        $this->assertSame(75000, $after('addCollection')['المتبقّي'], 'المتبقّي من قيمة المطالبة');

        // والحالة والمرحلة تبقيان كما كانتا — الإثراء يضيف ولا يستبدل
        $this->assertArrayHasKey('الحالة', $after('addCollection'));
        $this->assertArrayHasKey('المرحلة', $after('addCollection'));
    }

    /** القوائم المسموحة تصل الواجهة من الخادم — فلا تُنسخ يدوياً في TS ثمّ تتباعد عن التحقّق. */
    public function test_the_card_ships_the_allowed_lists_instead_of_letting_the_screen_copy_them(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->openFile($client, $this->lawyer(), ['stage' => 8, 'najiz_request_no' => 'NJ-1']);

        $card = $exec->najizCard();
        $this->assertSame(ExecFlow::MEASURES, $card['measureOptions']);
        $this->assertSame(ExecFlow::CLOSE_REASONS, $card['closeReasons']);
    }

    /** قبل فتح الملفّ لا بطاقة أصلاً — ولا تُعرض خطوات لا يجوز تسجيلها. */
    public function test_no_najiz_card_before_the_file_is_open(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->openFile($client, $this->lawyer(), ['stage' => 5, 'status' => ExecFlow::label(5), 'paid' => false, 'exec_no' => null]);

        $this->assertNull($exec->najizCard());
        $this->assertNull($exec->toFlowCard(false, false)['najiz']);
    }
}
