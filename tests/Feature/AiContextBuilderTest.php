<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiContextBuilder;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ما يُرسَل إلى مزوّد خارجيّ: تُموَّه المعرّفات وتبقى المادّة القانونيّة.
 *
 * التوازن هو كل شيء هنا: التمويه الناقص يسرّب هويّات موكّلين إلى خادم أجنبيّ،
 * والتمويه الزائد يُفسد التحليل — فتحليل نزاعٍ بلا أسماء أطرافه ولا مبالغه
 * ولا تواريخه ليس تحليلاً. الاختبار يحرس الطرفين معاً.
 */
class AiContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    // ── ما يجب أن يُموَّه ──

    public function test_masks_saudi_identity_numbers(): void
    {
        $out = AiContextBuilder::mask('هوية الموكّل 1098765432 وإقامة الخصم 2087654321.');

        $this->assertStringNotContainsString('1098765432', $out);
        $this->assertStringNotContainsString('2087654321', $out);
        $this->assertSame(2, substr_count($out, AiContextBuilder::ID_MASK));
    }

    public function test_masks_phone_numbers_in_all_local_forms(): void
    {
        $out = AiContextBuilder::mask('للتواصل: 0512345678 أو +966512345678 أو 00966512345678.');

        $this->assertStringNotContainsString('512345678', $out);
        $this->assertSame(3, substr_count($out, AiContextBuilder::PHONE_MASK));
    }

    public function test_masks_iban_and_email_and_card(): void
    {
        $out = AiContextBuilder::mask('الآيبان SA0380000000608010167519 والبريد client@example.com والبطاقة 4111 1111 1111 1111.');

        $this->assertStringNotContainsString('SA0380000000608010167519', $out);
        $this->assertStringNotContainsString('client@example.com', $out);
        $this->assertStringNotContainsString('4111', $out);
        $this->assertStringContainsString(AiContextBuilder::IBAN_MASK, $out);
        $this->assertStringContainsString(AiContextBuilder::EMAIL_MASK, $out);
        $this->assertStringContainsString(AiContextBuilder::CARD_MASK, $out);
    }

    // ── ما يجب ألّا يُموَّه: المادّة القانونيّة ──

    public function test_keeps_party_names_amounts_and_dates(): void
    {
        $text = 'نزاع بين شركة الأفق التجارية ومؤسسة الرمال بقيمة 150,000 ريال بتاريخ 2026-03-15 '
            .'بموجب عقد توريد رقم ع/2026/44، والحكم صدر في المحكمة التجارية.';

        $out = AiContextBuilder::mask($text);

        $this->assertSame($text, $out, 'الأسماء والمبالغ والتواريخ وأرقام العقود مادّة قانونيّة لا معرّفات');
    }

    public function test_does_not_mask_formatted_amounts_that_resemble_identity_numbers(): void
    {
        // المبالغ تُنسَّق بفواصل في كل المسارات، فلا تلتبس بهويّة من عشرة أرقام
        $out = AiContextBuilder::mask('قيمة المطالبة: 1,098,765 ريال.');

        $this->assertStringContainsString('1,098,765', $out);
    }

    public function test_short_numbers_are_untouched(): void
    {
        $out = AiContextBuilder::mask('الجلسة رقم 12 في الدائرة 5 بالقاعة 301.');

        $this->assertStringNotContainsString('[', $out);
    }

    // ── التصغير ──

    public function test_clip_marks_the_cut_explicitly(): void
    {
        $out = AiContextBuilder::clip(str_repeat('ن', 500), 100);

        $this->assertStringContainsString('قُصّ النصّ عند 100 حرف', $out, 'النموذج يجب أن يعرف أن ما وصله ناقص');
        $this->assertSame(100, mb_strlen(explode("\n", $out)[0]));
    }

    public function test_clip_leaves_short_text_and_zero_limit_alone(): void
    {
        $this->assertSame('نصّ قصير', AiContextBuilder::clip('نصّ قصير', 100));
        $this->assertSame('نصّ', AiContextBuilder::clip('نصّ', 0));
    }

    // ── التجهيز الكامل ──

    public function test_prepare_strips_markup_and_masks_and_clips(): void
    {
        $html = '<p>الموكّل <b>عبدالله</b> جواله 0512345678</p><script>alert(1)</script>';

        $out = AiContextBuilder::prepare($html, 200);

        $this->assertStringNotContainsString('<', $out, 'الوسوم تُزال — لا HTML يصل النموذج');
        $this->assertStringNotContainsString('0512345678', $out);
        $this->assertStringNotContainsString('alert', $out, 'جسم script يُسقَط لا وسومه فقط');
        $this->assertStringContainsString('عبدالله', $out, 'اسم الطرف يبقى');
    }

    /** حذف الوسم بلا مسافة يلصق نصّين فيفلت المعرّف من التمويه — عطبٌ حقيقيّ وقع. */
    public function test_adjacent_tags_do_not_hide_an_identifier_from_masking(): void
    {
        $out = AiContextBuilder::prepare('<span>جواله</span><span>0512345678</span><span>شكراً</span>');

        $this->assertStringNotContainsString('0512345678', $out);
        $this->assertStringContainsString(AiContextBuilder::PHONE_MASK, $out);
    }

    public function test_prepare_collapses_runaway_whitespace(): void
    {
        $out = AiContextBuilder::prepare("سطر\n\n\n\n\nآخر     بمسافات");

        $this->assertStringNotContainsString("\n\n\n", $out);
        $this->assertStringNotContainsString('     ', $out);
    }

    // ── التدقيق ──

    public function test_mask_counts_report_without_revealing_values(): void
    {
        $counts = AiContextBuilder::maskCounts('هوية 1098765432 وجوال 0512345678 وجوال آخر 0555555555.');

        $this->assertSame(1, $counts[AiContextBuilder::ID_MASK]);
        $this->assertSame(2, $counts[AiContextBuilder::PHONE_MASK]);
        $this->assertArrayNotHasKey(AiContextBuilder::IBAN_MASK, $counts, 'لا يُبلَّغ عمّا لم يوجد');
    }

    public function test_clean_text_reports_nothing(): void
    {
        $this->assertSame([], AiContextBuilder::maskCounts('نزاع تجاري بقيمة 50,000 ريال.'));
    }

    // ── الربط: التمويه يقع على الطلب الخارج لا في الصنف وحده ──

    /**
     * الحارس الحقيقيّ: يفحص **جسم الطلب المرسَل إلى جوجل** لا مخرَج الدالّة.
     * صنفٌ صحيح غير موصول لا يحمي شيئاً — والاختبار الذي يفحص الصنف وحده يمرّ
     * وإن كان السياق يخرج خاماً.
     */
    public function test_client_identifiers_never_reach_the_provider(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"case_summary":"م","attachments_summary":"م","facts":"و","key_points":"ن"}']]]]],
        ], 200)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-'.uniqid(),
            'type' => 'نزاع تجاري', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);
        $ticket->messages()->create([
            'who' => 'client', 'name' => 'العميل', 'role' => 'العميل', 'time_label' => 'الآن',
            'body' => '<p>هويتي 1098765432 وجوالي 0512345678 وبريدي client@example.com — '
                .'والنزاع مع مؤسسة الرمال بقيمة 150,000 ريال.</p>',
        ]);

        app(LegalAiService::class)->summarize($ticket);

        // الجسم يُرمّز العربية بـ\uXXXX — يُعاد ترميزه غير مهروب كي تُقارَن النصوص كما هي
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => (string) json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
            ->implode(' ');
        $this->assertNotSame('', $sent, 'لم يُرسَل طلب أصلاً — الاختبار بلا معنى');

        foreach (['1098765432', '0512345678', 'client@example.com'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $sent, "المعرّف «{$identifier}» خرج إلى المزوّد");
        }

        // والمادّة القانونيّة وصلت: التمويه لا يُفرّغ السياق
        $this->assertStringContainsString('مؤسسة الرمال', $sent);
    }
}
