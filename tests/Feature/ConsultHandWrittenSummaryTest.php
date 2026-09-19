<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **ملخّصٌ بخطّ المحامي يصل العميل — بلا تزوير قيدٍ للنموذج.**
 *
 * كانت حالةُ الاعتماد تعيش على `AiRun` بينما الوثيقة تعيش على `Consult`: البوّابة لا
 * تُفتح إلّا بقيدٍ من نوع `consult.summary`، والقيد لا يُنشأ إلّا بنداءٍ ناجح للنموذج.
 * فجلسةٌ تنتهي بلا تدوين — وهي الحال الغالبة — كان تقريرها المكتوب باليد **محجوباً للأبد**.
 *
 * ومنذ 2026-09-14 الاعتماد على مرحلتين: المحامي يعتمد ويرفع، والإدارة تعتمد فتُطلق.
 */
class ConsultHandWrittenSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{0:Consult,1:User,2:User} */
    private function endedWithoutNotes(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-HW-'.uniqid(), 'subject' => 'مطالبة مالية',
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية', 'session' => 'منتهية',
            'tone' => 'b-green', 'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
        ]);

        return [$consult, $lawyer, $client];
    }

    /**
     * **الحارس الأثمن.** يقفل العطل **وينفي الحلّ الرخيص** في آن: التأكيد الأخير
     * يمنع «إصلاحاً» يُزوّر قيد `AiRun` بلا نداءِ نموذج.
     */
    public function test_a_hand_written_summary_is_approvable_without_forging_an_ai_run(): void
    {
        [$consult, $lawyer, $client] = $this->endedWithoutNotes();
        $admin = User::factory()->create(['role' => Role::Admin]);

        // الواقع القائم: جلسة بلا تدوين لا تُنتج قيداً
        $this->assertSame(0, AiRun::where('task_type', 'consult.summary')->count());

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'الرأي: تُرفع مطالبة أمام الدائرة التجارية.'])
            ->assertRedirect();

        $this->assertNull($consult->fresh()->summary_approved_at, 'الحفظ ليس اعتماداً');
        $this->assertNull($consult->fresh()->toClientCard()['summary'], 'ولا يصل العميل بالحفظ');

        // ── المرحلة الأولى: المحامي يعتمد ويرفع — لا يصل العميل ──
        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/summary/approve")
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertNotNull($fresh->summary_lawyer_approved_at, 'صار قابلاً للاعتماد');
        $this->assertSame($lawyer->id, $fresh->summary_lawyer_approved_by);
        $this->assertNull($fresh->summary_approved_at, 'اعتماد المحامي لا يُطلق');
        $this->assertNull($fresh->toClientCard()['summary']);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->where('body', 'like', '%اعتُمد ملخّص استشارتك%')->count());

        // ── المرحلة الثانية: الإدارة تعتمد فيصل العميل ──
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/summary/approve")
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertNotNull($fresh->summary_approved_at);
        $this->assertSame($admin->id, $fresh->summary_approved_by);
        $this->assertStringContainsString('الدائرة التجارية', (string) $fresh->toClientCard()['summary'], 'ووصل العميل');

        $this->assertSame(
            1,
            UserNotification::where('user_id', $client->id)->where('body', 'like', '%اعتُمد ملخّص استشارتك%')->count(),
            'إشعارٌ واحد لا اثنان'
        );

        $this->assertSame(
            0,
            AiRun::where('task_type', 'consult.summary')->count(),
            'ولا قيدَ زُوِّر: الاعتماد فعلٌ على الملفّ لا على نداءٍ لم يقع'
        );
    }

    /** والاعتماد مرّتين مرفوض في كلّ مرحلة — الختم الأوّل يبقى. */
    public function test_approving_twice_is_refused(): void
    {
        [$consult, $lawyer] = $this->endedWithoutNotes();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'رأيٌ مقتضب.']);
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary/approve")->assertRedirect();
        $lawyerStamp = $consult->fresh()->summary_lawyer_approved_at;
        $this->assertNotNull($lawyerStamp);

        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary/approve")->assertStatus(422);
        $this->assertEquals($lawyerStamp, $consult->fresh()->summary_lawyer_approved_at, 'ختم المحامي الأوّل يبقى');

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();
        $adminStamp = $consult->fresh()->summary_approved_at;
        $this->assertNotNull($adminStamp);

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertStatus(422);
        $this->assertEquals($adminStamp, $consult->fresh()->summary_approved_at, 'وختم الإدارة كذلك');
    }

    /** ولا يُعتمد فراغ — «اعتماد» على لا شيء يُطلق وثيقةً خالية إلى العميل. */
    public function test_an_empty_summary_cannot_be_approved(): void
    {
        [$consult, $lawyer] = $this->endedWithoutNotes();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/summary/approve")
            ->assertStatus(422);

        $this->assertNull($consult->fresh()->summary_lawyer_approved_at);
        $this->assertNull($consult->fresh()->summary_approved_at);
    }

    /** ومحامٍ غريبٌ عن الملفّ لا يعتمده من شاشة الملفّ أيضاً — لا بابَ يلتفّ على العزل. */
    public function test_the_file_screen_is_not_a_way_around_the_isolation(): void
    {
        [$consult, $lawyer] = $this->endedWithoutNotes();
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'رأيٌ مقتضب.']);

        $stranger = User::factory()->create(['role' => Role::Lawyer]);
        $stranger->syncPermissions(Permission::all());

        $this->actingAs($stranger)
            ->post("/lawyer/consults/{$consult->id}/summary/approve")
            ->assertForbidden();

        $this->assertNull($consult->fresh()->summary_lawyer_approved_at);
        $this->assertNull($consult->fresh()->summary_approved_at);
    }

    /** والموظّف لا يعتمد — الرأي القانونيّ لا يعتمده غير محامٍ ثمّ الإدارة. */
    public function test_an_employee_without_the_permission_cannot_approve(): void
    {
        [$consult, $lawyer] = $this->endedWithoutNotes();
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'رأيٌ مقتضب.']);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(
            Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get()
        );

        // **لا مسار للموظّف أصلاً** (قرار المالك 2026-09-14: الموظّف لا يعتمد ملخّصاً) —
        // والحارس على الأثر: لا يُعتمد ولا يصل الموكّل.
        $status = $this->actingAs($employee)
            ->post("/employee/consults/{$consult->id}/summary/approve")
            ->status();
        $this->assertContains($status, [404, 405]);

        $this->assertNull($consult->fresh()->summary_approved_at);
        $this->assertNull($consult->fresh()->toClientCard()['summary'], 'ولا يصل الموكّل');
    }
}
