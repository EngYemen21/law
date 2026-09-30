<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **المستند المعتمد في محرّر الصياغة لا يُعدَّل** — قاعدة المنظومة (قرار المالك 2026-09-08، `ApprovalLocksEveryEditorTest`)
 * مُدَّت إلى المحرّر بطلب المالك (2026-09-30).
 *
 * كان الحفظ (اليدويّ والتلقائيّ) يستبدل نصّ المستند المعتمد وتبقى حالته «معتمد»، فيخرج في PDF وWord
 * نصٌّ لم يُعتمد تحت شارة «معتمد رسمياً»؛ وكان الاعتماد يُعاد فيتغيّر المعتمِد وتاريخه.
 */
class LegalDocumentApprovalLockTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    private function doc(string $status): LegalDocument
    {
        return LegalDocument::create([
            'title' => 'مذكّرة', 'type' => 'memo', 'user_id' => $this->lawyer->id,
            'content_html' => '<p>النصّ الأصليّ</p>', 'status' => $status,
            'approved_by' => $status === 'approved' ? $this->lawyer->id : null,
            'approved_at' => $status === 'approved' ? now()->subDay() : null,
        ]);
    }

    /** @return array<string, string> */
    private function edit(): array
    {
        return ['title' => 'عنوان آخر', 'type' => 'memo', 'content_html' => '<p>نصٌّ بعد الاعتماد</p>'];
    }

    public function test_the_lawyer_cannot_edit_an_approved_document(): void
    {
        $doc = $this->doc('approved');

        // الحفظ التلقائيّ (JSON) واليدويّ (Inertia) كلاهما
        $this->actingAs($this->lawyer)->putJson("/lawyer/editor/{$doc->id}", $this->edit())->assertStatus(422);
        $this->actingAs($this->lawyer)->put("/lawyer/editor/{$doc->id}", $this->edit())->assertStatus(422);

        $doc->refresh();
        $this->assertSame('<p>النصّ الأصليّ</p>', $doc->content_html);
        $this->assertSame('مذكّرة', $doc->title);
    }

    public function test_the_admin_cannot_edit_an_approved_document_either(): void
    {
        $doc = $this->doc('approved');
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->putJson("/admin/editor/{$doc->id}", $this->edit())->assertStatus(422);

        $this->assertSame('<p>النصّ الأصليّ</p>', $doc->fresh()->content_html);
    }

    public function test_a_draft_is_still_editable(): void
    {
        $doc = $this->doc('draft');

        $this->actingAs($this->lawyer)->putJson("/lawyer/editor/{$doc->id}", $this->edit())->assertOk();

        $this->assertSame('<p>نصٌّ بعد الاعتماد</p>', $doc->fresh()->content_html);
    }

    public function test_an_approved_document_is_not_approved_again(): void
    {
        $doc = $this->doc('approved');
        $approvedAt = $doc->approved_at->toDateTimeString();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post("/admin/editor/{$doc->id}/approve")->assertStatus(422);

        $doc->refresh();
        $this->assertSame($this->lawyer->id, $doc->approved_by, 'المعتمِد لا يتغيّر');
        $this->assertSame($approvedAt, $doc->approved_at->toDateTimeString());
    }

    public function test_the_editor_turns_read_only_for_an_approved_document(): void
    {
        $editor = (string) file_get_contents(resource_path('js/pages/lawyer/editor.tsx'));

        $this->assertStringContainsString('editable: !locked', $editor, 'المحرّر للقراءة فقط');
        $this->assertStringContainsString('معتمد — للقراءة فقط', $editor);
        $this->assertStringContainsString("locked ? '🔒 مقفل", $editor, 'لا «حفظ تلقائي مفعّل» على مقفل');
    }
}
