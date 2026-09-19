<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\MeetingStatusBroadcast;
use App\Jobs\FinalizeConsultJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewPreview;
use App\Services\LegalAiService;
use App\Support\ConsultReport;
use App\Support\Permissions;
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
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية', 'session' => 'منتهية', 'tone' => 'b-green',
            'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
        ]);

        // ملاحظات حقيقيّة: بلا مادّة لا يُنادى النموذج أصلاً ولا يُكتب ملخّص
        // (انظر `ConsultNoMaterialTest`)، وهذه الحزمة تحرس ما يقع **بعد** الإنتاج.
        (new FinalizeConsultJob($consult, 'دوّن المستشار: العميل لم يُصرَف له مستخلصان منذ أربعة أشهر، ويريد المطالبة.'))->handle(app(LegalAiService::class));

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

        // ⚠️ **لا يُؤكَّد على مخرج `/report`**: يعود PDF مضغوطاً، فالبحث النصّيّ في
        // بايتاته ينجح دائماً — حارسٌ لا يمكن أن يسقط. وقد صيغ كذلك أوّلاً ومرّ.
        // الفحص على البنية التي تُصيَّر: `ConsultReport::doc`.
        $doc = ConsultReport::doc($consult, $client->name);
        $summarySection = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٤. ملخص الاستشارة');

        $this->assertNotNull($summarySection, 'قسم الملخّص موجود في التقرير');
        $this->assertSame(ConsultReport::AWAITING_APPROVAL, $summarySection['lines']);
        $this->assertStringNotContainsString('الرأي القانوني ثم الإجراءات المقترحة', (string) $summarySection['lines']);

        // والمسار نفسه يبقى عاملاً — لا يُكسر بالحجب
        $this->actingAs($client)->get("/consults/{$consult->id}/report")->assertOk();
    }

    /** وبعد الاعتماد يظهر الملخّص في التقرير — الحجب مؤقّتٌ لا دائم. */
    public function test_the_report_shows_the_summary_once_approved(): void
    {
        [$consult, $lawyer, $client] = $this->finishedConsult();
        $consult->update(['summary_approved_at' => now(), 'summary_approved_by' => $lawyer->id]);

        $doc = ConsultReport::doc($consult->fresh(), $client->name);
        $section = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٤. ملخص الاستشارة');

        $this->assertSame($consult->summary, $section['lines']);
        $this->assertNotSame(ConsultReport::AWAITING_APPROVAL, $section['lines']);
    }

    /** و«الإجراء القادم» لا يُحيل العميل إلى ملخّصٍ محجوب عنه. */
    public function test_the_next_step_does_not_point_to_a_hidden_summary(): void
    {
        [$consult, , $client] = $this->finishedConsult();
        $consult->update(['session' => 'منتهية', 'priced_at' => now(), 'paid_at' => now()]);

        $doc = ConsultReport::doc($consult->fresh(), $client->name);
        $next = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٦. الإجراء القادم');

        // بلا «من المستشار»: الاعتماد مرحلتان، والنصّ نفسه يُطبع قبل اعتماد المستشار وبعده
        $this->assertSame(['بانتظار اعتماد ملخص الاستشارة'], $next['chips']);
    }

    /** وإشعار الإتاحة لا يُرسل عند الإنتاج — يتبع الاعتماد. */
    public function test_no_availability_notice_is_sent_before_approval(): void
    {
        [, , $client] = $this->finishedConsult();

        $texts = UserNotification::where('user_id', $client->id)->pluck('body')->implode(' | ');

        $this->assertStringNotContainsString('متاح الآن', $texts, 'لا يُقال «متاح» لمخرجٍ محجوب');
        $this->assertStringContainsString('فور اعتماده', $texts, 'بل يُخبَر بأنه قيد الاعتماد');
    }

    /** اعتماد المحامي في الصندوق يرفعه، واعتماد الإدارة يُطلقه ويُشعر العميل (2026-09-14). */
    public function test_approving_the_run_releases_the_summary_and_notifies_the_client(): void
    {
        [$consult, $lawyer, $client] = $this->finishedConsult();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $this->actingAs($lawyer)
            ->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept', 'note' => 'مراجَع.'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertNotNull($consult->summary_lawyer_approved_at, 'الاعتماد يُسجَّل في الملفّ لا في السجلّ وحده');
        $this->assertSame($lawyer->id, $consult->summary_lawyer_approved_by);
        $this->assertFalse($consult->summaryApproved(), 'واعتماد المحامي لا يُطلق');
        $this->assertNull($consult->toClientCard()['summary']);

        $this->actingAs($admin)
            ->post("/admin/ai-review/{$run->id}/decide", ['action' => 'accept'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertTrue($consult->summaryApproved());
        $this->assertSame($admin->id, $consult->summary_approved_by);
        $this->assertSame($lawyer->id, $consult->summary_lawyer_approved_by, 'وختم المحامي يبقى');
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

    // ── الباب الثاني لنفس الغرفة: البثّ اللحظيّ ──

    /**
     * **الحارس الأثمن:** ما يحجبه الخادم عن بطاقة العميل لا يبثّه على قناته.
     *
     * `consult.{id}` قناةٌ يُخوَّل عليها العميل، وكانت `broadcastWith` تضع `summary`
     * بلا شرط — فيُحجب النصّ في الحمولة الأولى ثم يصل بعد ثوانٍ لحظة كتابته
     * بالنموذج. مسارٌ ثانٍ التفّ على الحجب الذي بُني في `toClientCard`.
     *
     * والمقارنة بين المسارين — لا التأكيد على كلٍّ وحده — هي ما يمنع افتراقهما ثانيةً.
     */
    public function test_the_broadcast_never_carries_what_the_client_card_hides(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();

        $before = (new ConsultStatusBroadcast($consult))->broadcastWith();
        $this->assertNull($before['summary'], 'قبل الاعتماد: لا نصّ في الحمولة');
        $this->assertSame($consult->toClientCard()['summary'], $before['summary']);
        $this->assertTrue($before['summaryPending']);
        $this->assertFalse($before['summaryApproved']);

        $consult->update(['summary_approved_at' => now(), 'summary_approved_by' => $lawyer->id]);

        $after = (new ConsultStatusBroadcast($consult->fresh()))->broadcastWith();
        $this->assertSame($consult->fresh()->summary, $after['summary'], 'وبعده يُطلَق');
        $this->assertSame($consult->fresh()->toClientCard()['summary'], $after['summary']);
        $this->assertTrue($after['summaryApproved']);
        $this->assertFalse($after['summaryPending']);
    }

    /** والقاعدة نفسها في بثّ الاجتماعات — النظير الذي نُسخ عنه الحلّ. */
    public function test_the_meeting_broadcast_keeps_the_same_rule(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-BRD-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => 'اليوم · 10:00 ص', 'type' => 'اجتماع عميل',
            'status' => 'منتهٍ', 'approve' => 'بانتظار الاعتماد',
            'summary' => 'مداولات الاجتماع وقراراته.', 'minutes' => 'المحضر.',
        ]);

        $before = (new MeetingStatusBroadcast($meeting))->broadcastWith();
        $this->assertNull($before['summary']);
        $this->assertNull($before['minutes']);

        $meeting->update(['approve' => 'معتمد']);
        $after = (new MeetingStatusBroadcast($meeting->fresh()))->broadcastWith();
        $this->assertSame('مداولات الاجتماع وقراراته.', $after['summary']);
    }

    /** واعتمادٌ ثانٍ لا يُشعر العميل مرّتين. */
    public function test_approving_twice_notifies_once(): void
    {
        [$consult, $lawyer, $client] = $this->finishedConsult();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        foreach ([1, 2] as $_) {
            $this->actingAs($lawyer)->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept']);
        }
        foreach ([1, 2] as $_) {
            $this->actingAs($admin)->post("/admin/ai-review/{$run->id}/decide", ['action' => 'accept']);
        }

        $count = UserNotification::where('user_id', $client->id)
            ->where('body', 'like', '%اعتُمد ملخّص%')->count();

        $this->assertSame(1, $count);
    }

    // ── مسار التحرير: «تعديل واعتماد» فعلٌ لا إعلانُ نيّة ──

    /** المحامي يحرّر الملخّص قبل اعتماده، ومخرج النموذج يُجمَّد للمقارنة. */
    public function test_a_lawyer_can_edit_the_summary_before_approving_it(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $original = $consult->summary;

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'نصّ حرّره المحامي بعد مراجعة الجلسة.'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertSame('نصّ حرّره المحامي بعد مراجعة الجلسة.', $consult->summary);
        $this->assertSame($original, $consult->summary_ai_original, 'مخرج النموذج يُحفظ للمقارنة');
        $this->assertSame($lawyer->id, $consult->summary_edited_by);
        $this->assertNotNull($consult->summary_edited_at);
        $this->assertFalse($consult->summaryApproved(), 'والحفظ لا يُطلق — الإطلاق بالاعتماد');
        $this->assertNull($consult->toClientCard()['summary']);
    }

    /** وتحريرٌ ثانٍ لا يدهس مخرج النموذج المجمَّد. */
    public function test_a_second_edit_does_not_overwrite_the_frozen_model_output(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $original = $consult->summary;

        foreach (['تحرير أوّل.', 'تحرير ثانٍ.'] as $text) {
            $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary' => $text]);
        }

        $consult->refresh();
        $this->assertSame('تحرير ثانٍ.', $consult->summary);
        $this->assertSame($original, $consult->summary_ai_original);
    }

    /**
     * **الحارس الأثمن:** «قبول» على نصٍّ حرّره المراجع يُسجَّل **تعديلاً**.
     *
     * وإلّا كان `humanEditRate` استفتاءً على نيّةٍ لا قياساً لعمل.
     */
    public function test_accepting_an_edited_summary_is_recorded_as_edit_not_accept(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", [
            'summary' => 'نصّ مختلف تماماً كتبه المحامي بنفسه بعد مراجعة الجلسة ومستنداتها.',
        ]);

        $this->actingAs($lawyer)->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept'])->assertRedirect();

        $run->refresh();
        $this->assertSame(AiReviewAction::Edit, $run->review_action, 'القرار يتبع ما وقع لا ما أُعلن');
        $this->assertGreaterThan(0, $run->review_edit_distance, 'وحجم التحرير مقيسٌ لا مُدَّعى');
        $this->assertNotNull($consult->fresh()->summary_lawyer_approved_at, 'والاعتماد الأوّل يقع كما هو');
    }

    /** ونقيضه: قبولٌ على نصٍّ لم يُمسّ يبقى قبولاً بمسافة صفر. */
    public function test_accepting_an_untouched_summary_stays_accept(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $this->actingAs($lawyer)->post("/lawyer/ai-review/{$run->id}/decide", ['action' => 'accept'])->assertRedirect();

        $run->refresh();
        $this->assertSame(AiReviewAction::Accept, $run->review_action);
        $this->assertSame(0, $run->review_edit_distance, 'صفرٌ هنا قياسٌ وقع — لا «لم يُقَس»');
    }

    /** والتحرير بعد الاعتماد مرفوض: تبديل نصٍّ قرأه العميل سحبٌ لا حفظ. */
    public function test_editing_after_approval_is_refused(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $consult->update(['summary_approved_at' => now(), 'summary_approved_by' => $lawyer->id]);
        $approved = $consult->summary;

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/summary", ['summary' => 'تبديل بعد وصول النصّ للعميل.'])
            ->assertStatus(422);

        $this->assertSame($approved, $consult->fresh()->summary);
    }

    /**
     * والموظّف لا يحرّر ملخّصاً يصل العميل — **بالصلاحيّة لا بالدور**.
     *
     * كان المسار غير مسجَّل للموظّف بتاتاً فيقع ٤٠٤. وقرار المالك أدقّ: قراءةٌ فقط
     * افتراضاً، **إلّا أن تمنحه الإدارة العليا الصلاحيّة**. فصار المسار قائماً
     * والبوّابةُ صلاحيّة — ومن لا يملكها يُصدّ ٤٠٣ ولا يُغيَّر نصّ.
     */
    public function test_an_employee_cannot_edit_the_client_facing_summary(): void
    {
        [$consult] = $this->finishedConsult();
        $before = $consult->summary;

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get());

        // عُرف المشروع في `EnsurePermission`: الرفض إعادةُ توجيهٍ برسالة خطأ لا ٤٠٣
        // (إلّا لنداءات axios). فالحارس على **الأثر**: لا يُمسّ نصٌّ يقرؤه الموكّل.
        $this->actingAs($employee)
            ->post("/employee/consults/{$consult->id}/summary", ['summary' => 'تحرير الموظّف.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($before, $consult->fresh()->summary, 'ولا يُمسّ النصّ');
    }

    /** ومن منحته الإدارة الصلاحيّة **يحرّر** — البوّابة صلاحيّة لا دور. */
    public function test_an_employee_granted_the_permission_may_edit(): void
    {
        [$consult] = $this->finishedConsult();

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(
            Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get()
        );
        $employee->givePermissionTo('اعتماد/تعديل ملخص الاستشارة');

        $this->actingAs($employee)
            ->post("/employee/consults/{$consult->id}/summary", ['summary' => 'تحريرٌ بصلاحيّةٍ ممنوحة.'])
            ->assertRedirect();

        $this->assertSame('تحريرٌ بصلاحيّةٍ ممنوحة.', $consult->fresh()->summary);
        $this->assertNotNull($consult->fresh()->summary_edited_at);
    }

    /** وصندوق المراجعة يعرض النصّ نفسه — لا اعتماد صادق على بياناتٍ وصفيّة وحدها. */
    public function test_the_review_inbox_shows_the_text_that_will_reach_the_client(): void
    {
        [$consult, $lawyer] = $this->finishedConsult();
        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->firstOrFail();

        $preview = AiReviewPreview::for($run);

        $this->assertNotNull($preview, 'المخرج معروض لا موصوف');
        $this->assertSame($consult->summary, $preview['text']);
        $this->assertStringContainsString('كما سيصل العميل', $preview['label']);
    }
}
