<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\FinalizeConsultJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ملخّص الاستشارة لا يصل العميل قبل أن يعتمده محامٍ.
 *
 * كان يُكتب بالنموذج ويُشعَر به العميل فوراً («ملخص الاستشارة متاح الآن») ويُعرض
 * تحت شارة **«معتمد رسمياً»** — رأيٌ قانونيّ مصنَّف `high` بلا استرجاع ولا تحقّق ولا
 * مرورِ إنسان. وقرار المالك: يُحجب حتى الاعتماد.
 *
 * والحجب بلا مسار اعتماد أسوأ من الحال السابقة، فهذه الاختبارات تحرس **المسار كاملاً**:
 * الإنتاج ⇒ الحجب ⇒ الوصول إلى صندوق المحامي ⇒ الاعتماد ⇒ الإطلاق والإشعار.
 */
class ConsultSummaryApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{0:Consult, 1:User, 2:User} الاستشارة ومحاميها وعميلها */
    private function finishedConsult(): array
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'ملخّص الاستشارة: الوقائع ثم الرأي القانوني ثم الإجراءات المقترحة.'],
            ]]]],
        ], 200)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-APR-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية', 'tone' => 'b-green',
            'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
        ]);

        (new FinalizeConsultJob($consult, ''))->handle(app(LegalAiService::class));

        return [$consult->fresh(), $lawyer, $client];
    }

    /** المخرج يُنتَج ويُخزَّن — لكنه غير معتمد. */
    public function test_a_generated_summary_starts_unapproved(): void
    {
        [$consult] = $this->finishedConsult();

        $this->assertNotEmpty($consult->summary, 'الملخّص يُنتَج ويُحفظ');
        $this->assertNull($consult->summary_approved_at);
        $this->assertFalse($consult->summaryApproved());
    }

    /** ولا يصل العميل — لا في بطاقته ولا في تقريره. */
    public function test_the_client_never_receives_an_unapproved_summary(): void
    {
        [$consult, , $client] = $this->finishedConsult();

        $card = $consult->toClientCard();
        $this->assertNull($card['summary'], 'الحجب في الخادم لا في الواجهة');
        $this->assertTrue($card['summaryPending']);
        $this->assertFalse($card['summaryApproved']);

        $report = $this->actingAs($client)->get("/consults/{$consult->id}/report");
        $report->assertOk();
        $this->assertStringNotContainsString('الرأي القانوني ثم الإجراءات المقترحة', $report->getContent());
    }

    /** وإشعار الإتاحة لا يُرسل عند الإنتاج — يتبع الاعتماد. */
    public function test_no_availability_notice_is_sent_before_approval(): void
    {
        [, , $client] = $this->finishedConsult();

        $texts = UserNotification::where('user_id', $client->id)->pluck('body')->implode(' | ');

        $this->assertStringNotContainsString('متاح الآن', $texts, 'لا يُقال «متاح» لمخرجٍ محجوب');
        $this->assertStringContainsString('لاعتماد المستشار', $texts, 'بل يُخبَر بأنه قيد الاعتماد');
    }

    /** واعتماد المحامي في صندوق المراجعة يُطلقه ويُشعر العميل. */
    public function test_approving_the_run_releases_the_summary_and_notifies_the_client(): void
    {
        [$consult, $lawyer, $client] = $this->finishedConsult();

        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $this->actingAs($lawyer)
            ->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept', 'note' => 'مراجَع.'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertTrue($consult->summaryApproved(), 'الاعتماد يُسجَّل في الملفّ لا في السجلّ وحده');
        $this->assertSame($lawyer->id, $consult->summary_approved_by);
        $this->assertNotNull($consult->toClientCard()['summary'], 'ويصل العميل بعدها');

        $texts = UserNotification::where('user_id', $client->id)->pluck('body')->implode(' | ');
        $this->assertStringContainsString('اعتُمد ملخّص استشارتك', $texts);
    }

    /** والرفض لا يُطلق شيئاً. */
    public function test_a_rejected_summary_stays_hidden(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();

        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $this->actingAs($lawyer)->post("/lawyer/ai-review/{$run->id}/decide", [
            'action' => 'reject', 'reason' => 'fabricated_fact', 'note' => 'وقائع غير واردة.',
        ])->assertRedirect();

        $this->assertFalse($consult->fresh()->summaryApproved());
        $this->assertNull($consult->fresh()->toClientCard()['summary']);
    }

    /** واعتمادٌ ثانٍ لا يُشعر العميل مرّتين. */
    public function test_approving_twice_notifies_once(): void
    {
        [$consult, $lawyer, $client] = $this->finishedConsult();
        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        foreach ([1, 2] as $_) {
            $this->actingAs($lawyer)->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept']);
        }

        $count = UserNotification::where('user_id', $client->id)
            ->where('body', 'like', '%اعتُمد ملخّص%')->count();

        $this->assertSame(1, $count);
    }
}
