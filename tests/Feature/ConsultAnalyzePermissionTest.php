<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **صلاحيّةٌ تُعرض في الشاشة يجب أن تحرس باباً.**
 *
 * كانت «تشغيل تلخيص الفريق القانوني» تحرس **نسخة الإدارة وحدها** من مسار `analyze`
 * — وهي اللوحة التي لا تحتاجها أصلاً (`Gate::before` يجعل الأدمن يتجاوز كلّ حارس).
 * أمّا نسختا الموظّف والمحامي فكانتا تمرّان بـ«استقبال الاستشارات» وحدها. فوقع عطلان
 * متقابلان في الشاشة الواحدة:
 *
 * **نزعُها عن محامٍ لا يمنعه.** المدير يُلغي التأشير ويطمئنّ إلى أنّه منع استهلاك
 * الذكاء الاصطناعيّ، والزرّ يعمل عند المحامي كما كان.
 *
 * **والموظّف يشغّلها ولا يملكها.** لم تكن في `ROLE_PERMISSIONS['employee']` إطلاقاً،
 * فالمربّع لا يُعرض له — ومع ذلك يمرّ.
 *
 * وهو عطلٌ لا يُكتشف بالاستعمال: لا رسالةَ خطأ ولا شكوى — لا أحد يعرف أنّ هناك ما
 * يُفترض أن يُمنع.
 */
class ConsultAnalyzePermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * يقصر صلاحيّات المستخدم على المذكور.
     *
     * لازمٌ لأنّ `UserFactory::configure` يمنح **كلّ** صلاحيّات الكتالوج لمستخدمي
     * `Employee`/`Lawyer` في الاختبارات — فبلا هذا القصر يكون الفحص أجوفَ يمرّ دائماً.
     *
     * @param  list<string>  $names
     */
    private function limitTo(User $user, array $names): void
    {
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $user->syncPermissions(Permission::whereIn('name', $names)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function consultFor(User $client, ?User $lawyer = null): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ANL-'.uniqid(), 'subject' => 'نزاع تجاري مع مورّد',
            'type' => 'تجاري', 'channel' => 'مرئية', 'status' => 'قيد مراجعة الموظف',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-cyan',
            'lawyer' => $lawyer?->name ?: 'المستشار القانوني',
            'assigned_lawyer_id' => $lawyer?->id,
        ]);
    }

    /** **الحارس الأثمن:** موظّفٌ بلا الصلاحيّة لا يُشغّل التلخيص — ولا أثرَ يبقى. */
    public function test_an_employee_without_the_permission_cannot_analyze(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->limitTo($employee, ['استقبال الاستشارات', 'إجراء الجلسات المرئية']);

        $consult = $this->consultFor($client);
        $runsBefore = AiRun::count();

        $this->actingAs($employee)->post(route('employee.consults.analyze', $consult));

        $fresh = $consult->fresh();
        $this->assertSame('قيد مراجعة الموظف', $fresh->status, 'ولا تتقدّم الحالة');
        $this->assertFalse((bool) $fresh->ai_done);
        $this->assertSame('', (string) $fresh->ai_summary, 'ولا يُكتب تحليل');
        $this->assertSame($runsBefore, AiRun::count(), 'ولا نداءَ للنموذج');
    }

    /** **والمحامي المسنَد يشغّله** — فالمسار لا ينقطع، ينتقل الإطلاق إليه. */
    public function test_the_assigned_lawyer_may_analyze(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = $this->consultFor($client, $lawyer);

        $this->actingAs($lawyer)
            ->post(route('lawyer.consults.analyze', $consult))
            ->assertRedirect();

        $this->assertSame('بانتظار اعتماد الموظف', $consult->fresh()->status);
    }

    /**
     * **والحارس صلاحيّةٌ لا دور.**
     *
     * موظّفٌ منحته الإدارة الصلاحيّة استثناءً يشغّل التلخيص. وهذا ما يجعل المربّع في
     * شاشة الصلاحيّات **مفتاحاً حقيقيّاً** بيد الإدارة لا زينةً — وهو شرطُ القرار:
     * «تُضاف لسقف الموظّف بلا منحٍ افتراضيّ».
     */
    public function test_an_employee_granted_the_permission_may_analyze(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->limitTo($employee, ['استقبال الاستشارات', 'تشغيل تلخيص الفريق القانوني']);

        $consult = $this->consultFor($client);

        $this->actingAs($employee)
            ->post(route('employee.consults.analyze', $consult))
            ->assertRedirect();

        $this->assertSame('بانتظار اعتماد الموظف', $consult->fresh()->status);
    }

    /**
     * **والصلاحيّة ضمن سقف الموظّف — لا خارجه.**
     *
     * `StaffController::permsForRole` يقصّ أيّ صلاحيّةٍ خارج `ROLE_PERMISSIONS[$role]`
     * **دفاعاً خادميّاً**. فلو بقيت خارجه لكان الاستثناء أعلاه مستحيلاً من الشاشة:
     * تفقد الإدارة مفتاحها ويصير الفقدان نهائيّاً.
     *
     * ولا تُمنح تلقائياً: غائبةٌ عن قالب «خدمة عملاء» وعن بذرة الموظّف.
     */
    public function test_the_permission_is_grantable_but_not_granted_by_default(): void
    {
        $this->assertContains(
            'تشغيل تلخيص الفريق القانوني',
            Permissions::ROLE_PERMISSIONS['employee'],
            'وإلّا فقدت الإدارة مفتاح الاستثناء'
        );

        $this->assertNotContains(
            'تشغيل تلخيص الفريق القانوني',
            Permissions::PRESETS['خدمة عملاء'],
            'ولا ينالها موظّفٌ يُنشَأ بالقالب'
        );

        $this->assertStringContainsString(
            "array_diff(\n                Permissions::ROLE_PERMISSIONS['employee'],\n                ['تشغيل تلخيص الفريق القانوني'],",
            (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php')),
            'ولا تنالها بذرةُ الموظّف — السقف ليس منحاً'
        );
    }

    /**
     * **والزرّ يُخفى لمن لا يملكه.**
     *
     * `EnsurePermission` لا يردّ ٤٠٣ لطلبات Inertia بل **يعيد التوجيه إلى لوحة
     * المستخدم**. فزرٌّ ظاهرٌ بلا صلاحيّة يعني أنّ الموظّف يضغطه فيجد نفسه مقذوفاً
     * خارج صفحة الاستشارة بلا أن يفهم لماذا — أسوأ من غياب الزرّ.
     */
    public function test_the_button_is_hidden_without_the_permission(): void
    {
        $code = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'))
        );

        $this->assertStringContainsString(
            "useCan()('تشغيل تلخيص الفريق القانوني')",
            $code,
            'الشرط يُقرأ من الكتالوج نفسه'
        );

        // **الصلاحيّة تحرس الزرّين معاً** — وقد يُضاف إلى أحدهما شرطُ حالةٍ فوقها
        // (`!analyzeBlocked`: `analyze` يردّ ٤٢٢ على المنتهية والملغاة)، فالفحص على
        // بداية الشرط لا على صيغته كاملةً كي يقيس النيّة لا الحرف.
        $this->assertSame(2, substr_count($code, '{mayAnalyze &&'), 'زرّا الإطلاق والإعادة كلاهما');

        // ولا يُخفى عرضُ النتيجة ولا اعتمادُها — الموظّف يقرأ ويحرّر ويعتمد
        $this->assertStringContainsString('onClick={approveAI}', $code);
        $this->assertStringContainsString('onClick={saveAI}', $code);
    }
}
