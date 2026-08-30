<?php

namespace Tests\Feature;

use App\Services\Ai\AiPromptRegistry;
use Tests\TestCase;

/**
 * تجميد نصوص التعليمات: بصمة لكل تعليمة مقرونة بإصدارها.
 *
 * تعليمة الإنتاج تغيّر جودة المخرجات كلياً، وتغييرها كان يمرّ بلا أثر — لا في السجلّ
 * ولا في المراجعة. البصمة تجعل أي تعديل يُسقط هذا الاختبار عمداً، فيصير رفع الإصدار
 * في `AiPromptRegistry::PROMPTS` فعلاً واعياً لا نيّة حسنة. وهو ما تفرضه تعليمات
 * الخطة: «لا تغيّر Prompt أو النموذج في الإنتاج بلا تشغيل مجموعة تقييم ومراجعة».
 *
 * **إن أسقطتَ هذا الاختبار بتعديل مقصود:** ارفع `version` للتعليمة أولاً، ثم حدّث
 * بصمتها هنا، وسجّل سبب التغيير في تقرير الدفعة. لا تحدّث البصمة وحدها.
 */
class AiPromptRegistryTest extends TestCase
{
    /** أسماء المحامين المستعملة في بصمة تعليمة الاستشارة — ثابتة كي تبقى البصمة ثابتة. */
    private const ROSTER = ['أ. سارة القحطاني', 'أ. خالد المالكي'];

    /**
     * البصمات المجمَّدة — التُقطت من النصوص قبل نقلها من `LegalAiService`، فهي دليل
     * التطابق البايتيّ مع ما كان يُرسَل إلى النموذج فعلاً.
     *
     * @return array<string, array{0:string,1:string}> id => [sha256, version]
     */
    public static function frozenPrompts(): array
    {
        return [
            'ticket.triage' => ['8ba02438ee2218f209b5954906d9e1a129ef87a07a83f370b4084aec0f904ab2', 'v1'],
            'consult.analyze' => ['745613f2d3a6808accaf2c276d4115352c49ed0b53bf5adc049ede546d601c29', 'v1'],
            'execution.analyze' => ['f1da9567fec1a2f819f7de98ab5740d819ee9130af1e60f3edaae303ce6e4528', 'v1'],
            'case.classify' => ['7bf6069110db9a23ffe87e8f20ffadcb2f830468d85eb49d38e139425cc0ff9e', 'v1'],
            'document.analyze' => ['e7723156ba39cc4c03ed5464df50da4cf088e990cd9792a4a11c9f1aa5a02662', 'v1'],
            'meeting.summary' => ['4f831da512089d5b7c3ba494146f32323db7a16083fe2b48590234d60f8df439', 'v1'],
            'meeting.decisions' => ['01c2ccc915cfc7e373476f500b2e8c038050e8421cb97f5bcf2e9f9b8d9e6be0', 'v1'],
            'ticket.summary' => ['651e3f7d7ea8af142a4db4cdd8002d9ca2825828127d683725e83b673b82c317', 'v1'],
            'case.pleading' => ['dbe3a978bad6a2e94cf77acfcabcd7b2e91082b20df0d6500fe60d8a538328b6', 'v1'],
            'consult.summary' => ['f2dcfb18e9013c6e93a769edfde35eb820caab9342bc783607612e0e44a8221f', 'v1'],
            'chat.reply' => ['f3d9d2baa68dc90480747c7efd7e0e6b4ed171f340bc0a00ba47c7c3780574a6', 'v1'],
        ];
    }

    /**
     * `assistant.draft` خمس تعليمات لا واحدة — تُجمَّد كلٌّ على حدة كي يُرصد تعديل
     * فرعٍ بعينه. `docType` ثابت هنا لأن الفرع الافتراضيّ وحده يُقحمه في نصّه.
     *
     * @return array<string, string> kind => sha256
     */
    public static function frozenAssistantBranches(): array
    {
        return [
            'reply_memo' => '8e5fb0345a6d58b7e5befbae833c77df71ac58722aa82868d5d06d0ea90126ff',
            'contract_check' => '54d9f00fd0c5e231e272cc71ced7fa8b0ac237a8e888d082ce56688e1917c715',
            'strengths_weaknesses' => '06c763fac1258f9d55f3956d9519131694363a222fc35f3e613dd140a9cda1ef',
            'qualification' => 'd2d9772a5c68245feb9ea5e36c72bde6f510d6128acbb5cacc593a081d7765ab',
            'lawahe' => 'aac5aa10eb708950e151da825c62a6dc44e5e2fcaf6ca054c44bcc99f2700519',
        ];
    }

    private function textFor(string $id): string
    {
        return match ($id) {
            'ticket.triage' => AiPromptRegistry::ticketTriageSystem(),
            'consult.analyze' => AiPromptRegistry::consultAnalyzeSystem(self::ROSTER),
            'execution.analyze' => AiPromptRegistry::executionAnalyzeSystem(),
            'case.classify' => AiPromptRegistry::caseClassifySystem(),
            'document.analyze' => AiPromptRegistry::documentAnalyzeSystem(),
            'meeting.summary' => AiPromptRegistry::meetingSummarySystem(),
            'meeting.decisions' => AiPromptRegistry::decisionsSystem(),
            'ticket.summary' => AiPromptRegistry::ticketSummarySystem(),
            'case.pleading' => AiPromptRegistry::casePleadingSystem(),
            'consult.summary' => AiPromptRegistry::consultSummarySystem(),
            'chat.reply' => AiPromptRegistry::chatReplySystem(),
        };
    }

    public function test_prompt_texts_match_their_frozen_fingerprints(): void
    {
        foreach (self::frozenPrompts() as $id => [$hash, $version]) {
            $this->assertSame(
                $hash,
                hash('sha256', $this->textFor($id)),
                "تغيّر نصّ التعليمة «{$id}» — ارفع إصدارها في AiPromptRegistry::PROMPTS ثم حدّث بصمتها هنا، ووثّق السبب."
            );
            $this->assertSame($version, AiPromptRegistry::version($id), "إصدار «{$id}» لا يطابق الإصدار المرافق للبصمة.");
        }
    }

    /** التعليمة تتغيّر بتغيّر قائمة المحامين الحقيقيّة — ولا تحمل اسماً خارجها. */
    public function test_consult_prompt_lists_only_the_real_roster(): void
    {
        $text = AiPromptRegistry::consultAnalyzeSystem(['أ. ريم الزهراني']);

        $this->assertStringContainsString('أ. ريم الزهراني', $text);
        $this->assertStringNotContainsString('أ. سارة القحطاني', $text);
    }

    /** بلا محامين مسجَّلين: لا قائمة مختلقة، بل صياغة عامّة. */
    public function test_consult_prompt_without_roster_asks_generically(): void
    {
        $text = AiPromptRegistry::consultAnalyzeSystem([]);

        $this->assertStringContainsString('اسم المحامي المختصّ إن أمكن', $text);
        $this->assertStringNotContainsString('الأنسب من:', $text);
    }

    /**
     * قائمة الأقسام مصدرٌ واحد للفرز ولتصنيف القضية معاً. كانت مكرّرة حرفياً في
     * الدالّتين: إضافة قسم في إحداهما دون الأخرى تجعل التذكرة وقضيتها المحوَّلة
     * منها تُصنَّفان بقاموسين مختلفين.
     */
    public function test_department_catalogue_is_shared_by_triage_and_case_classification(): void
    {
        $this->assertStringContainsString(AiPromptRegistry::DEPARTMENTS, AiPromptRegistry::ticketTriageSystem());
        $this->assertStringContainsString(AiPromptRegistry::DEPARTMENTS, AiPromptRegistry::caseClassifySystem());
    }

    public function test_assistant_draft_branches_match_their_frozen_fingerprints(): void
    {
        foreach (self::frozenAssistantBranches() as $kind => $hash) {
            $this->assertSame(
                $hash,
                hash('sha256', AiPromptRegistry::assistantDraftSystem($kind, 'مذكرة')),
                "تغيّر فرع «{$kind}» من assistant.draft — ارفع الإصدار ثم حدّث بصمته هنا."
            );
        }
    }

    /** الفرع الافتراضيّ وحده يُقحم نوع الوثيقة في نصّ التعليمة. */
    public function test_assistant_default_branch_carries_the_document_type(): void
    {
        $this->assertStringContainsString('عقد إيجار', AiPromptRegistry::assistantDraftSystem('lawahe', 'عقد إيجار'));
        $this->assertStringNotContainsString('عقد إيجار', AiPromptRegistry::assistantDraftSystem('qualification', 'عقد إيجار'));
    }

    /** المرادفات تُنتج التعليمة نفسها — `mems` نظير `reply_memo` و`analyze` نظير `contract_check`. */
    public function test_assistant_aliases_resolve_to_the_same_prompt(): void
    {
        $this->assertSame(
            AiPromptRegistry::assistantDraftSystem('reply_memo', 'مذكرة'),
            AiPromptRegistry::assistantDraftSystem('mems', 'مذكرة'),
        );
        $this->assertSame(
            AiPromptRegistry::assistantDraftSystem('contract_check', 'عقد'),
            AiPromptRegistry::assistantDraftSystem('analyze', 'عقد'),
        );
        $this->assertSame(
            AiPromptRegistry::assistantDraftSystem('strengths_weaknesses', 'تحليل'),
            AiPromptRegistry::assistantDraftSystem('defense', 'تحليل'),
        );
    }

    /** حواجز عدم الاختلاق في المسودات القانونيّة — أثمن ما في هذه التعليمات. */
    public function test_legal_drafting_prompts_keep_their_no_fabrication_guards(): void
    {
        $pleading = AiPromptRegistry::casePleadingSystem();
        $this->assertStringContainsString('(يُستكمل)', $pleading, 'البديل عن اختراع البيانات الناقصة');
        $this->assertStringContainsString('ولا تخترع مواد نظامية بأرقام غير مؤكّدة', $pleading);

        $this->assertStringContainsString(
            'لا تُثبت واقعة أو دلالة لمستند لم ترد صراحةً',
            AiPromptRegistry::ticketSummarySystem(),
            'حاجز الإثبات: لا افتراض لمحتوى مستند لم يُفحص'
        );
    }

    /**
     * تعليمة المحادثة تصل العميل مباشرةً في كل ردّ — قيدان فيها يحميان المكتب:
     * ألّا يكشف الوكيل أنه نموذج (هويّته المعلنة «خدمة العملاء»)، وألّا يَعِد بنتيجة.
     */
    public function test_chat_prompt_keeps_its_client_facing_guards(): void
    {
        $text = AiPromptRegistry::chatReplySystem();

        $this->assertStringContainsString('لا تذكر أبداً أنك ذكاء اصطناعي', $text);
        $this->assertStringContainsString('لا تَعِد بنتيجة مضمونة', $text);
    }

    /** حاجز عدم اختلاق وقائع الاجتماع — لا يجوز أن يسقط من التعليمة بلا قرار. */
    public function test_meeting_prompt_keeps_its_no_fabrication_guard(): void
    {
        $text = AiPromptRegistry::meetingSummarySystem();

        $this->assertStringContainsString('حصراً', $text);
        $this->assertStringContainsString('لا تختلق وقائع', $text);
    }

    /** كل تعليمة منقولة لها مدخل مسجَّل بإصدار ومالك ونطاق. */
    public function test_migrated_prompts_are_registered(): void
    {
        foreach (array_keys(self::frozenPrompts()) as $id) {
            $this->assertTrue(AiPromptRegistry::has($id), "التعليمة «{$id}» منقولة لكنها غير مسجَّلة.");
            $this->assertNotSame('', AiPromptRegistry::PROMPTS[$id]['owner'] ?? '');
        }
    }
}
