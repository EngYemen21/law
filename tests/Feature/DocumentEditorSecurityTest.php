<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Lawyer\DocumentEditorController;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\RichHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * محرّر المستندات لا يصير قناةً إلى ملفّات الخادم ولا إلى متصفّح المدير.
 *
 * - `logoUrl` في الترويسة كان يُمرَّر لـ`public_path()` بلا حدّ، فـ`/../.env` يُضمَّن في الـPDF.
 * - `content_html` كان يُحفظ ويُعرض كما هو: سكربتٌ يكتبه محامٍ يعمل في صفحة الطباعة عند المدير
 *   وفي كروم الخادم عند تصيير الـPDF.
 */
class DocumentEditorSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTILE = '<p>الوقائع</p><script>alert(1)</script>'
        .'<iframe src="http://169.254.169.254/latest/meta-data/"></iframe>'
        .'<img src="x" onerror="fetch(\'/admin/staff\')">'
        .'<a href="javascript:alert(1)">رابط</a>';

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    public function test_logo_path_cannot_escape_the_public_directory(): void
    {
        $outside = storage_path('framework/testing/secret-logo.png');
        @mkdir(dirname($outside), 0775, true);
        file_put_contents($outside, 'SECRET');

        try {
            $this->assertNull(DocumentEditorController::publicImagePath('/../.env'));
            $this->assertNull(DocumentEditorController::publicImagePath('/../storage/framework/testing/secret-logo.png'));
            $this->assertNull(DocumentEditorController::publicImagePath('/index.php'), 'ليس ملفّ صورة');
            $this->assertNotNull(DocumentEditorController::publicImagePath('/images/021.png'), 'الشعار الافتراضيّ يبقى يعمل');
        } finally {
            @unlink($outside);
        }
    }

    public function test_pdf_html_does_not_embed_a_file_outside_public(): void
    {
        $outside = storage_path('framework/testing/secret-logo.png');
        @mkdir(dirname($outside), 0775, true);
        file_put_contents($outside, 'TOP-SECRET-CONTENT');

        try {
            $doc = LegalDocument::create([
                'title' => 'مستند',
                'type' => 'memo',
                'user_id' => $this->lawyer->id,
                'content_html' => '<p>نصّ</p>',
                'header_config' => ['showHeader' => true, 'logoUrl' => '/../storage/framework/testing/secret-logo.png'],
                'status' => 'draft',
            ]);

            $html = app(DocumentEditorController::class)->buildDocumentPdfHtml($doc);

            $this->assertStringNotContainsString(base64_encode('TOP-SECRET-CONTENT'), $html);
        } finally {
            @unlink($outside);
        }
    }

    public function test_stored_content_is_sanitized(): void
    {
        $this->actingAs($this->lawyer)->post('/lawyer/editor', [
            'title' => 'مستند عدائيّ',
            'type' => 'memo',
            'content_html' => self::HOSTILE,
        ])->assertRedirect();

        $raw = DB::table('legal_documents')->where('title', 'مستند عدائيّ')->value('content_html');

        $this->assertStringContainsString('<p>الوقائع</p>', $raw);
        $this->assertHostileRemoved($raw);
    }

    /** ما حُفظ قبل التنقية لا يصل صفحة الطباعة ولا صفحة الـPDF كما هو. */
    public function test_legacy_unsanitized_content_is_cleaned_on_read(): void
    {
        $id = DB::table('legal_documents')->insertGetId([
            'title' => 'مستند قديم',
            'type' => 'memo',
            'user_id' => $this->lawyer->id,
            'content_html' => self::HOSTILE,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get("/admin/editor/{$id}/print")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lawyer/editor-print')
                ->where('document.contentHtml', fn ($html) => $this->assertHostileRemoved((string) $html)));

        $pdfHtml = app(DocumentEditorController::class)->buildDocumentPdfHtml(LegalDocument::find($id));
        $this->assertHostileRemoved($pdfHtml);
    }

    public function test_sanitizer_keeps_editor_formatting(): void
    {
        $html = '<h2 style="text-align: center">صحيفة الدعوى</h2>'
            .'<p><strong>أولاً</strong> <em>ثانياً</em> <u>ثالثاً</u> <mark data-color="#ff0" style="background-color:#ff0">مظلّل</mark></p>'
            .'<table><tbody><tr><td colspan="2">خلية</td></tr></tbody></table>'
            .'<ul><li>بند</li></ul><a href="https://example.com" target="_blank">رابط</a>'
            .'<img src="data:image/png;base64,AAAA" alt="صورة">';

        $clean = RichHtml::clean($html);

        foreach (['<h2 style="text-align: center">صحيفة الدعوى</h2>', '<strong>أولاً</strong>', '<em>ثانياً</em>', '<u>ثالثاً</u>',
            'data-color="#ff0"', '<td colspan="2">خلية</td>', '<li>بند</li>', 'href="https://example.com"',
            'src="data:image/png;base64,AAAA"'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
    }

    private function assertHostileRemoved(string $html): bool
    {
        foreach (['<script', 'alert(1)', '<iframe', '169.254.169.254', 'onerror', 'javascript:'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }

        return true;
    }
}
