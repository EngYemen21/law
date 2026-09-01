<?php

namespace Tests\Feature;

use App\Services\Ai\AiOutputValidator;
use PHPUnit\Framework\TestCase;

/**
 * المحقِّق الخادميّ المستقلّ — **لا يُوثَق بأن النموذج اتّبع التعليمات**.
 *
 * القاعدة الحاكمة: قيمةٌ خارج المسموح تُسقَط ولا تُستبدَل بتخمين، وحقلٌ إلزاميّ ناقص
 * يُسقِط المخرج كلّه فيقع المستهلك على مساره الاحتياطيّ الموسوم. الاختبارات هنا تخصّ
 * المهامّ الثلاث المضافة مع توسيع مجموعة التقييم إلى الوظائف الستّ.
 */
class AiOutputValidatorTest extends TestCase
{
    // ── فحص المستند ──

    /**
     * `related` بلا افتراض: حملُه على `false` يوسم مستنداً صحيحاً بأنه أجنبيّ، وحملُه
     * على `true` يُدخل مستنداً أجنبياً إلى ملفّ قضية. كلاهما حكمٌ لم يتّخذه أحد.
     */
    /**
     * **القائمة الافتراضيّة لا تُحقَن — ولو أغفلها النموذج.**
     *
     * كان المعامل `$defaultProcedures` يملأ `procedures` عند إغفالها، فتخرج إجراءات
     * تنفيذٍ قضائيّة مكتوبة في الشيفرة داخل مخرجٍ يُوسم `ai_success`. أي أن القالب
     * يلتفّ على `AiSource` من داخله. والتعليمة تطلب الحقل صراحةً، فإغفاله معلومةٌ.
     */
    public function test_missing_procedures_stay_empty_and_are_never_filled_from_a_template(): void
    {
        $valid = AiOutputValidator::executionAnalysis(['summary' => 'سند تنفيذيّ مستوفٍ.']);

        $this->assertNotNull($valid, 'المخرج صالح: الملخّص وحده يكفي');
        $this->assertSame([], $valid['procedures'], 'ولا تُملأ الإجراءات من قالب');

        foreach (['تقديم طلب تنفيذ إلكتروني', 'طلب الإفصاح عن الأصول', 'الحجز على الحسابات'] as $canned) {
            $this->assertNotContains($canned, $valid['procedures']);
        }
    }

    /** وقائمةٌ فارغة صراحةً تبقى فارغة — لا تُقرأ «غياباً» يُملأ. */
    public function test_an_explicitly_empty_procedure_list_is_respected(): void
    {
        $valid = AiOutputValidator::executionAnalysis(['summary' => 'ملخّص.', 'procedures' => []]);

        $this->assertSame([], $valid['procedures']);
    }

    public function test_a_document_verdict_is_never_assumed_when_missing(): void
    {
        $this->assertNull(AiOutputValidator::documentAnalysis([
            'doc_type' => 'صك', 'summary' => 'صك ملكية.', 'reason' => '',
        ]));
    }

    /** ونصُّ «نعم» ليس حكماً منطقياً — قبولُه يحوّل كلمةً إلى قرار بالصلة. */
    public function test_a_textual_yes_is_not_a_boolean_verdict(): void
    {
        $this->assertNull(AiOutputValidator::documentAnalysis(['related' => 'نعم', 'doc_type' => 'مراسلات']));
        $this->assertNull(AiOutputValidator::documentAnalysis(['related' => 1, 'doc_type' => 'مراسلات']));
    }

    /** «غير مرتبط» نتيجةٌ صحيحة لا فشل — فلا تُسقَط. */
    public function test_an_unrelated_document_is_a_valid_result(): void
    {
        $valid = AiOutputValidator::documentAnalysis([
            'related' => false, 'doc_type' => 'فاتورة', 'summary' => 'فاتورة كهرباء.', 'reason' => 'لا صلة.',
        ]);

        $this->assertFalse($valid['related']);
        $this->assertSame('فاتورة', $valid['doc_type']);
    }

    /** ونوعٌ غير معلوم يقع على «مستند» ولا يُخمَّن — والحكم بالصلة قائم فلا يسقط المخرج. */
    public function test_an_unknown_document_type_falls_back_without_guessing(): void
    {
        $valid = AiOutputValidator::documentAnalysis(['related' => true, 'doc_type' => '   ']);

        $this->assertSame('مستند', $valid['doc_type']);
    }

    // ── ملخّص الملفّ ──

    public function test_a_summary_without_its_body_is_not_a_summary(): void
    {
        $this->assertNull(AiOutputValidator::ticketSummary(['attachments_summary' => 'لا مرفقات.']));
        $this->assertNull(AiOutputValidator::ticketSummary(['case_summary' => '   ']));
    }

    /** النموذج يتأرجح بين القائمة والنصّ — التوحيد هنا لا في كل موضع عرض. */
    public function test_facts_are_accepted_as_a_list_or_as_text(): void
    {
        $asList = AiOutputValidator::ticketSummary([
            'case_summary' => 'نزاع تجاري.', 'facts' => ['واقعة أولى', 'واقعة ثانية'],
        ]);
        $asText = AiOutputValidator::ticketSummary([
            'case_summary' => 'نزاع تجاري.', 'facts' => 'واقعة واحدة',
        ]);

        $this->assertSame("• واقعة أولى\n• واقعة ثانية", $asList['facts']);
        $this->assertSame('واقعة واحدة', $asText['facts']);
    }

    /** ولا تتكرّر النقطة حين يضعها النموذج بنفسه. */
    public function test_an_already_bulleted_item_is_not_double_bulleted(): void
    {
        $valid = AiOutputValidator::ticketSummary(['case_summary' => 'ملخّص.', 'key_points' => ['• نقطة']]);

        $this->assertSame('• نقطة', $valid['key_points']);
    }

    // ── استخراج القرارات ──

    /**
     * **الصفر نتيجة صحيحة**: نصٌّ بلا التزام يجب أن يُخرج صفراً لا أن يُختلق منه قرار.
     * ولذلك يُفرَّق بين «المفتاح غائب» (بنية لا تُقرأ) و«موجود وفارغ» (لا قرارات).
     */
    public function test_no_decisions_is_a_result_but_a_missing_key_is_a_failure(): void
    {
        $this->assertSame(['decisions' => []], AiOutputValidator::decisions(['decisions' => []]));
        $this->assertNull(AiOutputValidator::decisions(['notes' => 'تأجيل']));
        $this->assertNull(AiOutputValidator::decisions(['decisions' => 'مراجعة المسودة']));
    }

    /** والقيم الفارغة تُنظَّف فلا تُعدّ قرارات ولا تُنشئ مهامّ فارغة. */
    public function test_blank_entries_are_not_counted_as_decisions(): void
    {
        $valid = AiOutputValidator::decisions(['decisions' => ['مراجعة المسودة', '', '   ']]);

        $this->assertSame(['مراجعة المسودة'], $valid['decisions']);
    }
}
