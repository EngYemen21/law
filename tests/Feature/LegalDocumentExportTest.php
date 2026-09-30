<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Lawyer\DocumentEditorController;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\LegalDocMeta;
use App\Support\LegalDocStyle;
use App\Support\LegalDocx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **محرّر الصياغة ⇄ PDF ⇄ Word — تنسيقٌ واحد** (2026-09-30).
 *
 * كان لكلّ مخرَجٍ خطّه وأحجامه: المحرّر 12pt، وPDF ‏13.5pt بخطّ Google (يسقط لبديلٍ إن تعذّر الطلب)،
 * وWord ملفّ HTML يُسمّى ‎.doc بالأميري 14pt وتاريخٍ هجريّ وتذييلٍ مختلف؛ والقوائم بلا أرقام في المحرّر وحده.
 */
class LegalDocumentExportTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = '<h2>الوقائع</h2><p style="text-align: justify">نصُّ المذكّرة (أوّلاً).</p>'
        .'<ol><li><p>البند الأوّل</p></li><li><p>البند الثاني</p></li></ol>'
        .'<ul><li><p>نقطة</p></li></ul>'
        .'<table><tbody><tr><th><p>البيان</p></th><th><p>القيمة</p></th></tr><tr><td><p>أ</p></td><td><p>ب</p></td></tr></tbody></table>'
        .'<p><span style="font-family: Amiri">بخطّ الأميري</span></p>';

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    private function doc(array $header = []): LegalDocument
    {
        return LegalDocument::create([
            'title' => 'مذكّرة اختبار',
            'type' => 'memo',
            'user_id' => $this->lawyer->id,
            'content_html' => self::CONTENT,
            'header_config' => array_merge(LegalDocument::defaultHeader(), ['showHeader' => true], $header),
            'status' => 'draft',
        ]);
    }

    /** @return array<string, string> محتوى ملفّ docx: المسار ← النصّ */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'ملفّ docx صالح (zip)');
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $files;
    }

    public function test_server_style_constants_match_the_shared_css_file(): void
    {
        $css = (string) file_get_contents(resource_path('css/legal-document.css'));
        $rule = fn (string $sel): string => preg_match('/^'.preg_quote($sel, '/').'\s*\{([^}]*)\}/m', $css, $m) ? $m[1] : '';

        $body = $rule('.legal-doc');
        $this->assertStringContainsString("font-family: '".LegalDocStyle::FONT."'", $body);
        $this->assertStringContainsString('font-size: '.LegalDocStyle::BODY_PT.'pt', $body);
        $this->assertStringContainsString('line-height: '.LegalDocStyle::LINE_HEIGHT, $body);
        $this->assertStringContainsString('color: #'.LegalDocStyle::TEXT_COLOR, $body);

        foreach (LegalDocStyle::HEADINGS as $level => [$pt, $color]) {
            $h = $rule(".legal-doc h{$level}");
            $this->assertStringContainsString("font-size: {$pt}pt", $h, "h{$level}");
            $this->assertStringContainsString("color: #{$color}", $h, "h{$level}");
        }

        $this->assertStringContainsString('#'.LegalDocStyle::TABLE_BORDER, $rule('.legal-doc th, .legal-doc td'));
        $this->assertStringContainsString('#'.LegalDocStyle::TABLE_HEAD_BG, $rule('.legal-doc th'));
        $this->assertStringContainsString('#'.LegalDocStyle::QUOTE_BORDER, $rule('.legal-doc blockquote'));
        $this->assertStringContainsString('#'.LegalDocStyle::QUOTE_BG, $rule('.legal-doc blockquote'));
        $this->assertStringContainsString('#'.LegalDocStyle::LINK_COLOR, $rule('.legal-doc a'));

        // الطباعة من المتصفّح بهوامش الـPDF وWord نفسها
        $app = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('margin: '.LegalDocStyle::pageMargins(), $app);
    }

    public function test_editor_uses_the_shared_style_and_shows_list_markers(): void
    {
        $css = (string) file_get_contents(resource_path('css/legal-document.css'));
        $this->assertMatchesRegularExpression('/\.legal-doc ul\s*\{\s*list-style:\s*disc/', $css);
        $this->assertMatchesRegularExpression('/\.legal-doc ol\s*\{\s*list-style:\s*decimal/', $css);
        $this->assertStringContainsString("@import './legal-document.css'", (string) file_get_contents(resource_path('css/app.css')));

        foreach (['editor.tsx', 'editor-print.tsx'] as $page) {
            $this->assertStringContainsString('legal-editor-content legal-doc', (string) file_get_contents(resource_path("js/pages/lawyer/{$page}")), $page);
        }
    }

    public function test_pdf_embeds_local_fonts_and_the_shared_style(): void
    {
        $html = app(DocumentEditorController::class)->buildDocumentPdfHtml($this->doc());

        $this->assertStringNotContainsString('fonts.googleapis.com', $html, 'لا طلبَ لـGoogle وقت التوليد');
        $this->assertStringContainsString("url('data:font/woff2;base64,", $html);
        $this->assertStringNotContainsString("url('/fonts/legal/", $html, 'كلّ الخطوط مضمَّنة');
        $this->assertStringContainsString('class="legal-doc"', $html);
        $this->assertStringContainsString('<ol>', $html);
    }

    public function test_pdf_hides_the_license_line_when_there_is_no_license_number(): void
    {
        $controller = app(DocumentEditorController::class);

        $this->assertStringNotContainsString('ترخيص رقم', $controller->buildDocumentPdfHtml($this->doc(['licenseNo' => ''])));
        $this->assertStringContainsString('ترخيص رقم: 4455', $controller->buildDocumentPdfHtml($this->doc(['licenseNo' => '4455'])));
    }

    public function test_word_download_is_a_real_rtl_docx_with_embedded_fonts(): void
    {
        $doc = $this->doc(['licenseNo' => '']);

        $res = $this->actingAs($this->lawyer)->get("/lawyer/editor/{$doc->id}/docx");

        $res->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $res->headers->get('Content-Type'));
        $this->assertStringContainsString("filename*=UTF-8''", (string) $res->headers->get('Content-Disposition'));

        $files = $this->unzip((string) $res->getContent());
        $xml = $files['word/document.xml'] ?? '';

        $this->assertStringContainsString('<w:bidi', $xml, 'فقرات من اليمين');
        $this->assertStringContainsString('<w:rtl', $xml, 'نصّ عربيّ');
        $this->assertStringContainsString('<w:bidiVisual', $xml, 'جداول من اليمين');
        $this->assertStringContainsString('<w:numPr>', $xml, 'قوائم مرقّمة حقيقيّة');
        $this->assertStringContainsString('w:val="both"', $xml, 'الضبط (justify)');
        $this->assertStringContainsString('<w:sz w:val="'.(LegalDocStyle::BODY_PT * 2).'"', $xml, 'حجم المتن');
        $this->assertStringNotContainsString('ترخيص رقم', $xml);

        $odttf = array_filter(array_keys($files), fn (string $n) => str_starts_with($n, 'word/fonts/') && str_ends_with($n, '.odttf'));
        $this->assertNotEmpty($odttf, 'الخطوط مضمَّنة');
        $this->assertStringContainsString('w:name="Tajawal"', $files['word/fontTable.xml']);
        $this->assertStringContainsString('w:name="Amiri"', $files['word/fontTable.xml']);
        $this->assertStringContainsString('<w:embedRegular', $files['word/fontTable.xml']);
        $this->assertArrayHasKey('word/_rels/fontTable.xml.rels', $files);
        $this->assertStringContainsString('Extension="odttf"', $files['[Content_Types].xml']);
        $this->assertMatchesRegularExpression('#<w:settings[^>]*><w:embedTrueTypeFonts/>#', $files['word/settings.xml']);
    }

    public function test_word_and_pdf_share_the_same_header_date_and_closing_notice(): void
    {
        $doc = $this->doc();
        $m = LegalDocMeta::of($doc);

        $pdf = app(DocumentEditorController::class)->buildDocumentPdfHtml($doc);
        $xml = $this->unzip(LegalDocx::build($doc))['word/document.xml'];

        foreach (['date', 'refNo', 'notice', 'author'] as $key) {
            $this->assertStringContainsString(e($m[$key]), $pdf, "PDF: {$key}");
            $this->assertStringContainsString(htmlspecialchars($m[$key], ENT_XML1), $xml, "Word: {$key}");
        }
    }

    public function test_font_obfuscation_follows_the_spec_and_is_reversible(): void
    {
        $font = random_bytes(200);
        $guid = '{00112233-4455-6677-8899-AABBCCDDEEFF}';

        $obf = LegalDocx::obfuscate($font, $guid);

        $this->assertSame(substr($font, 32), substr($obf, 32), 'ما بعد ٣٢ بايتاً كما هو');
        $this->assertSame(chr(ord($font[0]) ^ 0xFF), $obf[0], 'المفتاح مقلوب: أوّل بايت يُخلط بآخر بايتٍ في GUID');
        $this->assertSame($font, LegalDocx::obfuscate($obf, $guid));
    }

    public function test_word_export_is_no_longer_built_in_the_browser(): void
    {
        $editor = (string) file_get_contents(resource_path('js/pages/lawyer/editor.tsx'));

        $this->assertStringNotContainsString('application/msword', $editor);
        $this->assertStringContainsString("download('docx')", $editor, 'Word من الخادم (`/editor/{id}/docx`)');
    }

    public function test_other_lawyers_cannot_download_the_word_file(): void
    {
        $doc = $this->doc();
        $other = User::factory()->create(['role' => Role::Lawyer]);

        $res = $this->actingAs($other)->get("/lawyer/editor/{$doc->id}/docx");

        // `abort(403)` يُحوَّل إعادةَ توجيهٍ برسالة (لا صفحة خطأ) — المهمّ ألّا يخرج الملفّ
        $this->assertNotSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('wordprocessingml', (string) $res->headers->get('Content-Type'));
    }
}
