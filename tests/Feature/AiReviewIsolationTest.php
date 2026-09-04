<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\FinalizeConsultJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **صندوق المراجعة يعزل القرار لا العرض وحده.**
 *
 * كان `AiReviewInbox::query()` يُستعمل في `forUser`/`countFor` فقط، بينما
 * `AiReviewController::decide` يستقبل القيد بربط النموذج ويُحدّثه بلا سؤال. فمحامٍ
 * يحمل «اعتماد الملخصات» — وكلّ محاميّ المكتب يحملونها — كان يعتمد **بمعرّفٍ رقميّ**
 * ملخّصَ استشارةٍ ليست مسندة إليه، فيُطلق إلى عميلها رأياً قانونياً عن ملفٍّ لم يره
 * ويُشعره به. ثغرةٌ لا تحتاج إلّا رقماً متسلسلاً.
 *
 * وهذه الاختبارات تحرس **الطرفين**: أن الغريب يُصدّ، وأن صاحب الملفّ والمُصعَّد إليه
 * ما زالا يعتمدان — فحارسٌ يمنع الجميع ليس عزلاً بل تعطيل.
 */
class AiReviewIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function lawyer(): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        return $lawyer;
    }

    /** استشارةٌ منتهية بملخّصٍ مولَّد وقيدِ مراجعةٍ قائم. @return array{0:Consult,1:User} */
    private function reviewableConsult(): array
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'ملخّص الاستشارة: الوقائع ثم الرأي القانوني ثم الإجراءات.'],
            ]]]],
        ], 200)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $owner = $this->lawyer();

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ISO-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية', 'tone' => 'b-green',
            'assigned_lawyer_id' => $owner->id, 'lawyer' => $owner->name,
        ]);

        (new FinalizeConsultJob($consult, 'دوّن المستشار: لم يُصرف للعميل مستخلصان منذ أربعة أشهر.'))
            ->handle(app(LegalAiService::class));

        return [$consult->fresh(), $owner];
    }

    private function runFor(Consult $consult): AiRun
    {
        return AiRun::where('task_type', 'consult.summary')
            ->where('entity_id', $consult->id)
            ->latest('id')->firstOrFail();
    }

    // ══ الصدّ ══

    /**
     * **محامٍ غريبٌ عن الملفّ لا يعتمد ملخّصه.**
     *
     * ويُؤكَّد **الأذى نفسه** لا الرمز وحده: حارسٌ يفحص ٤٠٣ فقط كان سيمرّ لو أُجهض
     * الطلب **بعد** أن أُطلق الملخّص إلى العميل.
     */
    public function test_a_lawyer_cannot_approve_a_summary_of_another_lawyers_consult(): void
    {
        [$consult] = $this->reviewableConsult();
        $run = $this->runFor($consult);
        $stranger = $this->lawyer();

        $this->actingAs($stranger)
            ->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertForbidden();

        $this->assertNull($run->fresh()->review_action, 'لا يُسجَّل قرار');
        $this->assertNull($consult->fresh()->summary_approved_at, 'ولا يُعتمد الملفّ');
        $this->assertNull(
            $consult->fresh()->toClientCard()['summary'],
            'والأذى نفسه لم يقع: الملخّص ما زال محجوباً عن العميل'
        );
    }

    /** والموظّف لا يبتّ في رأيٍ قانونيّ — خارج مهامّه التشغيليّة. */
    public function test_an_employee_cannot_decide_a_legal_summary(): void
    {
        [$consult] = $this->reviewableConsult();
        $run = $this->runFor($consult);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::all());

        $this->actingAs($employee)
            ->post("/employee/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertForbidden();

        $this->assertNull($consult->fresh()->summary_approved_at);
    }

    // ══ ولا يتحوّل العزل إلى تعطيل ══

    /** صاحب الملفّ يعتمد كما كان — وإلّا كان «الإصلاح» تعطيلاً. */
    public function test_the_assigned_lawyer_still_approves(): void
    {
        [$consult, $owner] = $this->reviewableConsult();
        $run = $this->runFor($consult);

        $this->actingAs($owner)
            ->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertRedirect();

        $this->assertNotNull($consult->fresh()->summary_approved_at);
        $this->assertNotNull($consult->fresh()->toClientCard()['summary'], 'ويصل العميل');
    }

    /** والمُصعَّد إليه كذلك — وهو مسارٌ لا يمرّ بالإسناد أصلاً. */
    public function test_an_escalated_reviewer_may_decide_a_file_not_assigned_to_them(): void
    {
        [$consult] = $this->reviewableConsult();
        $run = $this->runFor($consult);
        $escalatee = $this->lawyer();

        $run->update(['escalated_to' => $escalatee->id]);

        $this->actingAs($escalatee)
            ->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertRedirect();

        $this->assertNotNull($consult->fresh()->summary_approved_at);
    }

    /** والإدارة تبقى فوق العزل — نظير `ScopedToLawyer` في بقيّة المشروع. */
    public function test_an_admin_may_decide_any_run(): void
    {
        [$consult] = $this->reviewableConsult();
        $run = $this->runFor($consult);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertRedirect();

        $this->assertNotNull($consult->fresh()->summary_approved_at);
    }
}
