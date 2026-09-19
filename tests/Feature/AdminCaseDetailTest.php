<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\ConversationFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **صفحة تفاصيل القضيّة للإدارة — الدفعة ٤ (قرار المالك 2026-09-11).**
 *
 * كانت الإدارة تحدّد الأتعاب وتُغلق وتؤرشف ولا ترى المحادثة ولا الجلسات ولا المستندات:
 * حارسُ الأدوار يمنعها من مسارات المحامي، ولا صفحةَ لها.
 */
class AdminCaseDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_sees_the_whole_file_with_its_actions(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عبدالله محمد العتيبي']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'محمد بندر']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'number' => 'CASE-ADMV-1', 'type' => 'نزاع تجاري', 'status' => 'صدر الحكم', 'tone' => 'b-cyan',
            'ruling' => 'حُكم للمدّعي.', 'ai_classification' => ['type' => 'نزاع عمالي', 'department' => 'القسم العمالي'],
        ]);
        $case->messages()->create(['who' => 'note', 'name' => 'الإدارة', 'role' => 'ملاحظة', 'body' => 'ملاحظة داخليّة']);
        $case->messages()->create(['who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة', 'body' => 'مسودّة', 'withheld_at' => now()]);
        Storage::disk('local')->put('case-docs/x/a.pdf', '%PDF');
        $doc = CaseDocument::create(['case_id' => $case->id, 'name' => 'صك.pdf', 'path' => 'case-docs/x/a.pdf']);

        $this->actingAs($admin)->get(route('admin.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/case')
                ->where('case.client', 'عبدالله محمد العتيبي') // لا تقنيع على الإدارة
                ->where('case.canClose', true)
                ->where('case.canExecute', true)
                ->where('case.canReassign', true)
                ->where('case.aiClassification.type', 'نزاع عمالي')
                // المحجوب بانتظار الاعتماد يُرى (و`visibleTo` تُسقط `note` للمكتب كلّه — سلوكٌ قائم)
                ->where('messages', fn ($ms) => collect($ms)->pluck('role')->contains('مسودة اللائحة'))
                ->where('documents.0.downloadUrl', ConversationFiles::url('case', $doc->id))
                ->where('lawyers', fn ($ls) => collect($ls)->pluck('name')->contains('محمد بندر')));
    }

    public function test_the_page_is_for_the_admin_only(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-ADMV-2', 'type' => 'نزاع', 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->get(route('admin.cases.show', $case))->assertRedirect();
    }

    public function test_every_admin_case_row_links_to_its_page(): void
    {
        $src = (string) file_get_contents(resource_path('js/pages/admin/cases.tsx'));

        $this->assertStringContainsString('href={`/admin/cases/${encodeURIComponent(c.no)}`}', $src);
    }
}
