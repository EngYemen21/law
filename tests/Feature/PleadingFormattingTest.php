<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\CasePleading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * **تنسيق لائحة الدعوى يصل القضيّة والعميل، والقالب يُلفّ مرّةً واحدة** (قرار المالك 2026-10-02).
 *
 * ثبت في المتصفّح: اعتماد المستند في محرّر الصياغة ينقل اللائحة إلى القضيّة نصّاً عاديّاً (`toPlain`) فيضيع
 * تنسيقها، وفتحُها في المحرّر ثانيةً يلفّها بترويسة القالب وتحيّته فوق ترويستها — فتتكرّر.
 */
class PleadingFormattingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: LegalCase, 1: User} */
    private function caseFor(): array
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-FMT-'.uniqid(), 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'tone' => 'b-blue',
            'status' => 'قيد التحضير', 'pleading_status' => 'pending_lawyer',
        ]);

        return [$case, $lawyer];
    }

    private function approveInEditor(LegalCase $case, User $lawyer, string $html): void
    {
        $doc = LegalDocument::create([
            'title' => 'لائحة', 'type' => 'lawsuit', 'user_id' => $lawyer->id, 'case_id' => $case->id,
            'metadata' => ['source_type' => 'case_pleading', 'case_id' => $case->id], 'content_html' => $html, 'status' => 'draft',
        ]);
        $this->actingAs($lawyer)->post("/lawyer/editor/{$doc->id}/approve")->assertSessionHasNoErrors();
    }

    private function importedHtml(LegalCase $case, User $lawyer): string
    {
        $html = '';
        $this->actingAs($lawyer)->get('/lawyer/editor/create?importType=case_pleading&id='.$case->number)
            ->assertInertia(function (AssertableInertia $page) use (&$html) {
                $html = (string) $page->toArray()['props']['incomingDraft'];
            });

        return $html;
    }

    // ── البند 1: التنسيق يُحفظ منسّقاً ──

    public function test_the_case_card_saves_formatting_and_strips_what_must_not_reach_the_client(): void
    {
        [$case, $lawyer] = $this->caseFor();

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => '<h3>أولاً: الوقائع</h3>'
            .'<p><strong>أبرمت المدّعية</strong> عقد توريد مع المدّعى عليها.</p><script>alert(1)</script>'
            .'<p style="position:fixed;color:#0e5c9c" class="x" onclick="steal()">نصٌّ ملوّن</p><img src="https://evil.test/t.png">'
            .'<a href="https://evil.test">رابط</a>'])->assertRedirect()->assertSessionHasNoErrors();

        $body = CasePleading::latestDraft($case)->body;
        $this->assertStringContainsString('<h3>أولاً: الوقائع</h3>', $body);
        $this->assertStringContainsString('<strong>أبرمت المدّعية</strong>', $body);
        $this->assertStringContainsString('<p style="color: #0e5c9c;">نصٌّ ملوّن</p>', $body);
        foreach (['<script', 'alert(1)', 'position', 'onclick', 'class="x"', '<img', 'evil.test', '<a '] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $body);
        }
        $this->assertStringContainsString('رابط', $body, 'الرابط يُفَكّ ويبقى نصّه');
        $this->assertFalse(CasePleading::isDocument(CasePleading::latestDraft($case)));
    }

    /** الطول بالنصّ لا بالوسوم — وسومٌ كثيرة حول كلمتين ليست لائحة. */
    public function test_the_length_rule_reads_text_not_tags(): void
    {
        [$case, $lawyer] = $this->caseFor();

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => '<p><strong><em><u>لائحة</u></em></strong></p><p><br></p><p><br></p>'])
            ->assertSessionHasErrors('body');
        $this->assertNull(CasePleading::latestDraft($case));
    }

    /** المسودّة النصّيّة (آليّة أو قديمة) تُفتح في المحرّر المنسّق فقراتٍ مُهرَّبة — بلا تحويلٍ في القاعدة. */
    public function test_a_plain_draft_opens_as_escaped_paragraphs(): void
    {
        [$case, $lawyer] = $this->caseFor();
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => CasePleading::DRAFT_ROLE, 'withheld_at' => now(),
            'body' => '<div class="draft" style="white-space:pre-line">'.e("أولاً: الوقائع\n<b>ليس وسماً</b>\n\nثانياً: الطلبات").'</div>',
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pleadingDraft', '<p>أولاً: الوقائع</p><p>&lt;b&gt;ليس وسماً&lt;/b&gt;</p><p>ثانياً: الطلبات</p>')
            ->where('pleadingIsDocument', false));
    }

    public function test_the_editor_approval_carries_the_formatting_to_the_case(): void
    {
        [$case, $lawyer] = $this->caseFor();

        $this->approveInEditor($case, $lawyer, '<h2 style="text-align: center;">لائحة دعوى</h2><hr><p><strong>رقم القضية:</strong> 1</p>'
            .'<table><tbody><tr><td colspan="2">المدّعي</td><td colspan="javascript">x</td></tr></tbody></table>');

        $draft = CasePleading::latestDraft($case);
        $this->assertTrue(CasePleading::isDocument($draft));
        $this->assertStringContainsString('<h2 style="text-align: center;">لائحة دعوى</h2><hr><p><strong>رقم القضية:</strong> 1</p>', $draft->body);
        $this->assertStringContainsString('<td colspan="2">المدّعي</td><td>x</td>', $draft->body, 'امتداد الخليّة رقمٌ وحده');
        $this->assertNotNull($draft->withheld_at, 'محجوبة حتى الاعتماد النهائيّ');
    }

    // ── البند 2: القالب يُلفّ مرّةً واحدة ──

    public function test_a_plain_draft_is_wrapped_by_the_template_once(): void
    {
        [$case, $lawyer] = $this->caseFor();
        CasePleading::save($case, $lawyer, '<p>أولاً: الوقائع</p>');

        $html = $this->importedHtml($case, $lawyer);
        $this->assertSame(1, substr_count($html, 'لدى أصحاب الفضيلة'));
        $this->assertStringContainsString('<p>أولاً: الوقائع</p>', $html);
    }

    public function test_an_approved_editor_document_is_imported_as_is(): void
    {
        [$case, $lawyer] = $this->caseFor();
        CasePleading::save($case, $lawyer, '<p>أولاً: الوقائع</p>');

        // دورةٌ كاملة: استيراد بالقالب ← اعتماد في المحرّر ← استيرادٌ ثانٍ ← اعتمادٌ ثانٍ — والترويسة واحدة
        $first = $this->importedHtml($case, $lawyer);
        $this->approveInEditor($case, $lawyer, $first);
        $second = $this->importedHtml($case, $lawyer);
        $this->approveInEditor($case, $lawyer, $second);

        $this->assertSame(1, substr_count($this->importedHtml($case, $lawyer), 'لدى أصحاب الفضيلة'));
        $this->assertSame(1, substr_count(CasePleading::latestDraft($case)->body, 'لدى أصحاب الفضيلة'));
    }

    /** المستند الكامل يُحرَّر في محرّر الصياغة وحده — والبطاقة تعرضه للقراءة. */
    public function test_the_case_card_does_not_overwrite_an_editor_document(): void
    {
        [$case, $lawyer] = $this->caseFor();
        $this->approveInEditor($case, $lawyer, '<h2>لائحة دعوى</h2><p>نصّ اللائحة المعتمد في المحرّر</p>');

        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pleadingIsDocument', true));
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => '<p>نصٌّ يستبدل المستند كاملاً بلا ترويسة</p>'])
            ->assertStatus(422);
        $this->assertStringContainsString('نصّ اللائحة المعتمد في المحرّر', CasePleading::latestDraft($case)->body);
    }

    /** العلامة على غلافٍ يبنيه الخادم — نصٌّ مُرسَل من البطاقة لا يزوّرها. */
    public function test_the_document_marker_cannot_be_forged_from_the_card(): void
    {
        [$case, $lawyer] = $this->caseFor();

        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading.save', $case), [
            'body' => '<div class="draft draft-rich" data-pleading="document"><p>محاولة تزوير علامة المستند الكامل</p></div>',
        ])->assertRedirect();

        $this->assertFalse(CasePleading::isDocument(CasePleading::latestDraft($case)));
    }

    /**
     * **المسافة حول الكلمة المنسّقة تبقى** — ثبت في المتصفّح: `TrimStrings` كان يقصّ نصوص بنية المستند
     * (`content_json`)، فيُعاد تحميله «كلمة**عريضة**بعدها» ويحفظها الحفظ التلقائيّ كذلك في اللائحة.
     */
    public function test_the_editor_structure_keeps_spaces_around_formatted_words(): void
    {
        [, $lawyer] = $this->caseFor();
        $json = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'كلمة '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'عريضة'], ['type' => 'text', 'text' => ' بعدها'],
        ]]]];

        $this->actingAs($lawyer)->post('/lawyer/editor', [
            'title' => 'مستند', 'type' => 'free', 'content_html' => '<p>كلمة <strong>عريضة</strong> بعدها</p>', 'content_json' => $json,
        ])->assertSessionHasNoErrors();

        $doc = LegalDocument::latest('id')->firstOrFail();
        $this->assertSame('كلمة ', $doc->content_json['content'][0]['content'][0]['text']);
        $this->assertSame(' بعدها', $doc->content_json['content'][0]['content'][2]['text']);
        $this->assertSame('<p>كلمة <strong>عريضة</strong> بعدها</p>', $doc->content_html);
    }

    /** النصّ العاديّ (معاينة الصندوق والاستيراد) مشتقٌّ من المنسّقة بفقراتها. */
    public function test_plain_consumers_read_paragraphs(): void
    {
        [$case, $lawyer] = $this->caseFor();
        CasePleading::save($case, $lawyer, '<h3>الوقائع</h3><p>أبرمت المدّعية عقداً.</p><ul><li>البند الأوّل</li></ul>');

        $this->assertSame("الوقائع\n\nأبرمت المدّعية عقداً.\n\n• البند الأوّل", CasePleading::draftText(CasePleading::latestDraft($case)));
    }
}
