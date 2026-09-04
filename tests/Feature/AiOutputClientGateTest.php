<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Events\CaseMessageBroadcast;
use App\Models\AiRun;
use App\Models\CaseMessage;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewOutcome;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * لا مخرج ذكاءٍ يصل العميل قبل أن يعتمده محامٍ — في المراحل الثلاث.
 *
 * كانت بوّابة الاعتماد تحرس `consult.summary` وحدها: `AiReviewOutcome::apply` فرعٌ
 * واحد، وتسع مهامّ `high` أخرى تُوسم `needs_review` ثم **تُسلَّم فوراً**. فالوسم سطرُ
 * سجلٍّ لا حاجز — والقياس على قاعدة التطوير: ٣٥ قيداً موسوماً، ٤٦ من ٥٠ بلا قرار.
 *
 * وثلاثة مخرجات كانت تصل العميل بلا مرورِ إنسان:
 * - **مسودّة اللائحة** (`case.pleading`) — وثيقةٌ قضائيّة تُنشَر رسالةً في محادثة
 *   القضية بعد سداد الأتعاب، والعروض الثلاثة تستعمل ترشيحاً واحداً بلا فاصل.
 * - **تحليل التنفيذ** (`execution.analyze`) — نواقصُ يُطالَب بها العميل وإجراءاتٌ
 *   يبني عليها توقّعه، و`ai_success` تعني «أعاد النموذج JSON صالحاً» لا أكثر.
 * - **قرارات الاستشارة** (`meeting.decisions`) — استُخرجت من ملخّصٍ محجوبٍ فوقها.
 */
class AiOutputClientGateTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->lawyer->syncPermissions(Permission::all());
    }

    // ── مسودّة اللائحة ──

    private function caseWithDraft(): array
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-GATE-'.uniqid(),
            'type' => 'نزاع تجاري', 'subject' => 'مطالبة', 'status' => 'محوّلة', 'tone' => 'b-blue',
        ]);
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id,
            'number' => 'CASE-GATE-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => 'نشطة',
            'tone' => 'b-green', 'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $draft = $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة',
            'body' => 'لائحة دعوى: وقائع وأسانيد وطلبات ختامية.',
            'time_label' => '10:00 ص', 'withheld_at' => now(),
        ]);
        $case->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => 'رسالة عادية.', 'time_label' => '10:01 ص',
        ]);

        $run = AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class,
            'entity_id' => $case->id, 'entity_ref' => $case->number,
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        return [$case, $draft, $run];
    }

    /** **الحارس الأثمن:** المسودّة لا تصل العميل، ويراها المكتب ليراجعها. */
    public function test_a_pleading_draft_is_withheld_from_the_client_but_visible_to_staff(): void
    {
        [$case] = $this->caseWithDraft();

        $toClient = $case->messages()->visibleTo(false)->pluck('role')->all();
        $toStaff = $case->messages()->visibleTo(true)->pluck('role')->all();

        $this->assertNotContains('مسودة اللائحة', $toClient, 'الحجب في الخادم لا في الواجهة');
        $this->assertContains('العميل', $toClient, 'وبقيّة الرسائل تصله كما كانت');
        $this->assertContains('مسودة اللائحة', $toStaff, 'والمكتب يراها ليراجعها');
    }

    /**
     * ولا تُبثّ على قناة القضية — الباب الثاني لنفس الغرفة.
     *
     * `case.{id}` يُخوَّل عليها العميل (`ownerOrStaff`)، و`CaseMessage::booted` كان
     * يبثّ كل رسالة عند إنشائها — فيتجاوز البثُّ ترشيحَ الحمولة الخادميّ.
     */
    public function test_a_withheld_message_is_never_broadcast(): void
    {
        Event::fake([CaseMessageBroadcast::class]);
        [$case] = $this->caseWithDraft();

        Event::assertNotDispatched(CaseMessageBroadcast::class, fn ($e) => $e->message->role === 'مسودة اللائحة');
        Event::assertDispatched(CaseMessageBroadcast::class, fn ($e) => $e->message->role === 'العميل');
    }

    /** واعتماد المحامي يُطلقها ويبثّها ويُشعر العميل. */
    public function test_approving_the_run_releases_the_draft_to_the_client(): void
    {
        [$case, $draft, $run] = $this->caseWithDraft();
        Event::fake([CaseMessageBroadcast::class]);

        AiReviewOutcome::apply($run, AiReviewAction::Accept, $this->lawyer);

        $this->assertNull($draft->fresh()->withheld_at);
        $this->assertContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all());
        Event::assertDispatched(CaseMessageBroadcast::class, fn ($e) => $e->message->id === $draft->id);
    }

    /** والرفض لا يُطلق شيئاً. */
    public function test_a_rejected_pleading_stays_withheld(): void
    {
        [$case, $draft, $run] = $this->caseWithDraft();

        AiReviewOutcome::apply($run, AiReviewAction::Reject, $this->lawyer);

        $this->assertNotNull($draft->fresh()->withheld_at);
        $this->assertNotContains('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all());
    }

    /**
     * ورسالةٌ عاديّة تبقى ظاهرة بلا أن يمسّها أحد.
     *
     * في `app/` ستّة وعشرون موضعاً تُنشئ رسائل قضية. لو كان العمود يعني «مُطلَقة»
     * لاقتضى أن يتذكّره كلٌّ منها، وأيّ موضعٍ ينساه تختفي رسالته صامتةً.
     */
    public function test_an_ordinary_message_is_visible_without_anyone_setting_a_column(): void
    {
        [$case] = $this->caseWithDraft();

        $plain = $case->messages()->create([
            'who' => 'lawyer', 'name' => 'المستشار', 'role' => 'ردّ',
            'body' => 'ردّ المحامي.', 'time_label' => '11:00 ص',
        ]);

        $this->assertNull($plain->withheld_at);
        $this->assertContains($plain->id, $case->messages()->visibleTo(false)->pluck('id')->all());
    }

    // ── تحليل التنفيذ ──

    private function analysedExecution(): array
    {
        $exec = Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-GATE-'.uniqid(),
            'subject' => 'تنفيذ', 'sanad' => 'حكم قضائي', 'status' => 'قيد الدراسة',
            'tone' => 'b-blue', 'stage' => 2, 'ai_done' => true,
            'ai_source' => AiSource::AiSuccess->value,
            'ai_summary' => 'خلاصة تحليل السند والمستندات.',
            'ai_missing' => ['صك الحكم مكتملاً'],
            'ai_procedures' => ['قيد الطلب في ناجز'],
        ]);

        $run = AiRun::create([
            'task_type' => 'execution', 'entity_type' => Execution::class,
            'entity_id' => $exec->id, 'entity_ref' => $exec->number,
            'source' => AiSource::AiSuccess, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        return [$exec, $run];
    }

    /** تحليل التنفيذ محجوبٌ عن بطاقة العميل، ظاهرٌ لبطاقة المكتب. */
    public function test_execution_analysis_is_withheld_from_the_client_card(): void
    {
        [$exec] = $this->analysedExecution();

        $toClient = $exec->toFlowCard(false, false);
        $toStaff = $exec->toFlowCard(true, true);

        $this->assertSame('', $toClient['aiSummary'], 'الحجب في الخادم لا في الواجهة');
        $this->assertSame([], $toClient['aiMissing']);
        $this->assertSame([], $toClient['aiProcedures']);
        $this->assertTrue($toClient['aiPending'], 'ويُعلَم أنه قيد الاعتماد');

        $this->assertNotSame('', $toStaff['aiSummary'], 'والمكتب يراه ليراجعه');
        $this->assertNotEmpty($toStaff['aiMissing']);
    }

    /** والاعتماد يُطلقه للعميل. */
    public function test_approving_releases_the_execution_analysis(): void
    {
        [$exec, $run] = $this->analysedExecution();

        AiReviewOutcome::apply($run, AiReviewAction::Accept, $this->lawyer);

        $exec->refresh();
        $this->assertTrue($exec->aiApproved());
        $this->assertSame($this->lawyer->id, $exec->ai_approved_by);
        $this->assertNotSame('', $exec->toFlowCard(false, false)['aiSummary']);
        $this->assertFalse($exec->toFlowCard(false, false)['aiPending']);
    }

    // ── قرارات الاستشارة ──

    /** قرارات الاستشارة تتبع ملخّصها في الحجب — استُخرجت منه. */
    public function test_consult_decisions_follow_the_summary_approval(): void
    {
        $consult = Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-GATE-'.uniqid(),
            'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'منتهية', 'session' => 'منتهية', 'tone' => 'b-green',
            'lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
            'summary' => 'ملخّص الجلسة.', 'decisions' => ['توجيه إنذار', 'تجهيز مذكرة'],
        ]);

        $this->assertSame([], $consult->toClientCard()['decisions'], 'محجوبة كملخّصها');

        $consult->update(['summary_approved_at' => now(), 'summary_approved_by' => $this->lawyer->id]);

        $this->assertCount(2, $consult->fresh()->toClientCard()['decisions'], 'وتصله بعد الاعتماد');
    }

    /**
     * والقاعدة العامّة: ما يحجبه الخادم عن العميل لا يُسرَّب في أيّ حقلٍ آخر من بطاقته.
     *
     * حارسٌ يمسح البطاقة كاملةً بدل التأكيد على حقلٍ حقل — فحقلٌ جديد يحمل النصّ
     * نفسه مستقبلاً يُسقط هذا الاختبار بدل أن يمرّ صامتاً.
     */
    public function test_no_client_card_field_leaks_a_withheld_output(): void
    {
        [$exec] = $this->analysedExecution();
        $needle = 'خلاصة تحليل السند';

        $payload = json_encode($exec->toFlowCard(false, false), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($needle, (string) $payload, 'لا حقل في البطاقة يحمله');

        $consult = Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-LEAK-'.uniqid(),
            'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'منتهية', 'tone' => 'b-green', 'lawyer' => $this->lawyer->name,
            'summary' => 'رأيٌ قانونيّ لم يعتمده أحد.', 'decisions' => ['قرارٌ مستنبط'],
        ]);

        $card = json_encode($consult->toClientCard(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('رأيٌ قانونيّ لم يعتمده أحد', (string) $card);
        $this->assertStringNotContainsString('قرارٌ مستنبط', (string) $card);
    }
}
