<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalSource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiReviewInbox;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * مساعد المحامي: القالب يُعرّف نفسه، والمسودّة تُقيَّد.
 *
 * كان `assist()` يُرجع نصّاً وحده — فيستلم المحامي مخرج النموذج ومخرج القالب بالشكل
 * نفسه تماماً، والقالب فيه «(م/191)» و«العقد شريعة المتعاقدين» و«مهلة إخطار كتابي
 * (15 يوماً)» و«المحكمة المختصة بمدينة الرياض»، وتحته في الشاشة «مستندة للأنظمة
 * والقضاء السعودي». ولا قيد في `ai_runs` رغم تصنيف المهمّة `high`.
 */
class AiAssistantSourceTest extends TestCase
{
    use RefreshDatabase;

    /** كل الأنواع الثمانية المسموح بها في التحقّق. */
    private const KINDS = [
        'lawahe', 'mems', 'analyze', 'defense',
        'reply_memo', 'contract_check', 'strengths_weaknesses', 'qualification',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function noProvider(): void
    {
        config(['services.gemini.key' => '', 'services.glm.key' => '']);
        Cache::flush();
    }

    private function fakeProvider(string $text = 'مذكّرة من النموذج.'): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
        ], 200)]);
    }

    private function lawyer(): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        return $lawyer;
    }

    /** الاستجابة تُعلن هل جرى تحليل أصلاً. */
    public function test_the_endpoint_declares_whether_a_model_ran_at_all(): void
    {
        $lawyer = $this->lawyer();

        $this->noProvider();
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'context' => 'سياق النزاع.',
        ])->assertOk()->assertJson(['source' => AiSource::Fallback->value]);

        $this->fakeProvider();
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'context' => 'سياق النزاع.',
        ])->assertOk()->assertJson(['source' => AiSource::AiSuccess->value]);
    }

    /**
     * القالب يُعرّف نفسه **فوق** نصّه — في الأنواع الثمانية كلّها.
     * لا يكفي أن يُوسم في حقلٍ جانبيّ: مسودّةٌ تُقرأ من أوّلها تُصدَّق قبل بلوغ وسمها.
     */
    public function test_the_hardcoded_template_announces_itself_above_the_text(): void
    {
        $this->noProvider();
        $ai = app(LegalAiService::class);

        foreach (self::KINDS as $kind) {
            $result = $ai->assistResult($kind, 'مستند اختبار');

            $this->assertSame(AiSource::Fallback, $result['source'], "[{$kind}] مصدر القالب");
            $this->assertStringStartsWith(
                '⚠️ قالب استرشاديّ ثابت',
                $result['draft'],
                "[{$kind}] الوسم يتصدّر النصّ لا يذيّله"
            );
            $this->assertStringContainsString('أمثلةٌ لا أسانيد', $result['draft'], "[{$kind}]");
        }
    }

    /** ومخرج النموذج لا يحمل وسم القالب — الوسم يميّز، فلا يُلصق بالجميع. */
    public function test_a_model_output_does_not_carry_the_template_banner(): void
    {
        $this->fakeProvider('مذكّرة مصاغة من النموذج.');

        $result = app(LegalAiService::class)->assistResult('reply_memo', 'مذكرة رد');

        $this->assertSame(AiSource::AiSuccess, $result['source']);
        $this->assertStringNotContainsString('قالب استرشاديّ ثابت', $result['draft']);
    }

    /** كل مسودّة تُقيَّد في سجلّ القرارات — `high` ⇒ تنتظر مراجعة. */
    public function test_every_assistant_draft_lands_in_the_decision_log(): void
    {
        $this->fakeProvider();
        $lawyer = $this->lawyer();

        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'defense', 'docType' => 'مذكرة دفوع', 'context' => 'سياق.',
        ])->assertOk();

        $run = AiRun::where('task_type', 'assistant.draft')->latest('id')->first();

        $this->assertNotNull($run, 'مخرج قانونيّ لا يجوز أن يُنتَج بلا قيد');
        $this->assertSame(AiRun::STATUS_NEEDS_REVIEW, $run->status, 'حساسيّتها high');
        $this->assertNotNull($run->prompt_version);
        $this->assertNotNull($run->trace_id);
    }

    /** والمسودّة على مرجعٍ مسنَد تبلغ صندوق صاحبه هو. */
    public function test_an_assistant_draft_reaches_the_lawyer_who_owns_the_reference(): void
    {
        $this->fakeProvider();
        $lawyer = $this->lawyer();
        $other = $this->lawyer();
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-ASSIST-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'ref' => $ticket->number, 'context' => 'سياق.',
        ])->assertOk();

        $refs = AiReviewInbox::forUser($lawyer)->pluck('entity_ref')->all();
        $this->assertContains($ticket->number, $refs, 'تبلغ صندوق صاحب الملفّ');

        $this->assertNotContains(
            $ticket->number,
            AiReviewInbox::forUser($other)->pluck('entity_ref')->all(),
            'ولا تبلغ زميله'
        );
    }

    // ── الاسترجاع: مجالٌ صحيح واستعلامٌ من الملفّ ──

    /**
     * **لا مصادر من مجالٍ أجنبيّ.**
     *
     * كان النداء `retrieve(domain: '', …)` يُسقط فلتر المجال بالكامل، فتُمرَّر موادّ
     * من مجالٍ لا يحكم الواقعة تحت لافتة «مصادر معتمدة — استشهد بمعرّفاتها حصراً».
     */
    public function test_the_assistant_never_receives_sources_from_a_foreign_domain(): void
    {
        $this->fakeProvider();
        $this->legalSource('LS-EXEC-ONLY', 'التنفيذ', 'الحجز على حسابات المنفَّذ ضده وأمواله لدى الغير.');
        $this->legalSource('LS-GENERAL', null, 'يلتزم المورّد بتسليم البضاعة وللمشتري الفسخ والتعويض عند التأخّر.');

        $lawyer = $this->lawyer();
        $ticket = $this->commercialTicket($lawyer);

        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد',
            'ref' => $ticket->number,
            'context' => 'نزاع حول تسليم بضاعة والمطالبة بالتعويض عن التأخير وفسخ العقد.',
        ])->assertOk();

        $sent = $this->lastRequestBody();

        $this->assertStringContainsString('LS-GENERAL', $sent, 'العامّ يحكم كل المجالات فيُمرَّر');
        $this->assertStringNotContainsString('LS-EXEC-ONLY', $sent, 'ومادّةُ مجالٍ آخر لا تُمرَّر سنداً');
    }

    /**
     * الاستعلام من **وقائع الملفّ** لا من نوع المستند.
     * قيس حيّاً: «مذكرة رد» وحدها ⇒ صفر مصدر — أي أن الاسترجاع كان معطَّلاً عملياً.
     */
    public function test_the_assistant_query_is_built_from_the_file_not_from_the_document_type(): void
    {
        $this->fakeProvider();
        $this->legalSource('LS-GENERAL', null, 'يلتزم المورّد بتسليم البضاعة وللمشتري الفسخ والتعويض عند التأخّر.');
        $lawyer = $this->lawyer();

        // نوع المستند وحده، بلا سياق ولا مرجع ⇒ لا سند
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'context' => 'ـ',
        ])->assertOk();
        $this->assertStringNotContainsString('LS-GENERAL', $this->lastRequestBody(), 'نوع المستند وحده لا يكفي');

        // ومع وقائع الملفّ ⇒ سند
        $this->fakeProvider();
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد',
            'context' => 'تأخّر المورّد عن تسليم البضاعة، ونطالب بفسخ العقد والتعويض عن الضرر.',
        ])->assertOk();
        $this->assertStringContainsString('LS-GENERAL', $this->lastRequestBody(), 'الوقائع تُنتج سنداً');
    }

    /** والمذكرات والدفوع تُمرَّر لها المصادر — تعليماتها تطلب «الأسانيد» صراحةً. */
    public function test_memo_and_defense_kinds_now_receive_authority(): void
    {
        $this->legalSource('LS-GENERAL', null, 'يلتزم المورّد بتسليم البضاعة وللمشتري الفسخ والتعويض عند التأخّر.');
        $lawyer = $this->lawyer();

        foreach (['reply_memo', 'defense', 'strengths_weaknesses', 'mems'] as $kind) {
            $this->fakeProvider();
            $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
                'kind' => $kind, 'docType' => 'مستند',
                'context' => 'تأخّر المورّد عن تسليم البضاعة، ونطالب بفسخ العقد والتعويض عن الضرر.',
            ])->assertOk();

            $this->assertStringContainsString('LS-GENERAL', $this->lastRequestBody(), "[{$kind}] يتلقّى سنداً");
        }
    }

    /** وقاعدةٌ خالية لا تُوقف المسودّة — حارسٌ ضدّ إصلاحٍ مفرط. */
    public function test_an_empty_knowledge_base_does_not_block_the_draft(): void
    {
        $this->fakeProvider('مذكّرة من النموذج.');
        $lawyer = $this->lawyer();

        $response = $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'context' => 'وقائع النزاع.',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('draft'));
        $this->assertStringNotContainsString('مصادر نظاميّة معتمدة', $this->lastRequestBody());
    }

    private function legalSource(string $ref, ?string $domain, string $text): void
    {
        LegalSource::create([
            'ref' => $ref, 'title' => 'مادّة اختبار', 'system_name' => 'نظام تجريبيّ للاختبار',
            'article_no' => '1', 'domain' => $domain, 'jurisdiction' => 'السعودية', 'text' => $text,
            'version' => '1', 'effective_from' => '2020-01-01', 'effective_to' => null,
            'source_owner' => 'الفريق القانونيّ', 'legal_review_at' => '2026-01-01',
            'usage_scope' => 'مسودات داخليّة', 'status' => LegalSource::STATUS_APPROVED,
        ]);
    }

    private function commercialTicket(User $lawyer): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'SB-DOM-'.uniqid(), 'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية',
            'subject' => 'فسخ عقد توريد', 'details' => 'تأخّر المورّد عن التسليم.',
            'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id,
        ]);
    }

    private function lastRequestBody(): string
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'لم يُرسَل طلب — الاختبار بلا معنى');

        return (string) json_encode(json_decode((string) $recorded[0][0]->body(), true), JSON_UNESCAPED_UNICODE);
    }

    /** ومرجعٌ مسنَد لزميلٍ يبقى ممنوعاً — النسبة لم تُضعِف العزل. */
    public function test_a_colleagues_reference_is_still_refused(): void
    {
        $this->fakeProvider();
        $lawyer = $this->lawyer();
        $other = $this->lawyer();
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-OTHER-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'assigned_lawyer_id' => $other->id,
        ]);

        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo', 'docType' => 'مذكرة رد', 'ref' => $ticket->number, 'context' => 'سياق.',
        ])->assertForbidden();
    }
}
