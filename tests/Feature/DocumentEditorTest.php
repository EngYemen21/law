<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\CasePleading;
use App\Support\RichHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyerA;

    private User $lawyerB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lawyerA = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي الأول']);
        $this->lawyerB = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي الثاني']);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'المدير العام']);
    }

    public function test_lawyer_sees_only_their_own_documents_in_index(): void
    {
        LegalDocument::create([
            'title' => 'لائحة المحامي الأول',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>محتوى المحامي الأول</p>',
            'status' => 'draft',
        ]);

        LegalDocument::create([
            'title' => 'مذكرة المحامي الثاني',
            'type' => 'memo',
            'user_id' => $this->lawyerB->id,
            'content_html' => '<p>محتوى المحامي الثاني</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->get('/lawyer/editor');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor-index')
            ->has('documents', 1)
            ->where('documents.0.title', 'لائحة المحامي الأول')
        );
    }

    public function test_admin_sees_all_documents_in_editor_index(): void
    {
        LegalDocument::create([
            'title' => 'لائحة المحامي الأول',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>محتوى 1</p>',
            'status' => 'draft',
        ]);

        LegalDocument::create([
            'title' => 'مذكرة المحامي الثاني',
            'type' => 'memo',
            'user_id' => $this->lawyerB->id,
            'content_html' => '<p>محتوى 2</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/editor');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor-index')
            ->has('documents', 2)
        );
    }

    public function test_lawyer_can_open_create_page(): void
    {
        $response = $this->actingAs($this->lawyerA)->get('/lawyer/editor/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor')
            ->where('document', null)
            ->has('types')
            ->has('defaultHeader')
        );
    }

    public function test_lawyer_can_store_new_document(): void
    {
        $payload = [
            'title' => 'صحيفة دعوى جديدة',
            'type' => 'lawsuit',
            'content_html' => '<h1>بسم الله الرحمن الرحيم</h1><p>وقائع الدعوى...</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
            'header_config' => [
                'showHeader' => true,
                'officeName' => 'مكتب المحاماة',
            ],
        ];

        $response = $this->actingAs($this->lawyerA)->post('/lawyer/editor', $payload);

        $this->assertDatabaseHas('legal_documents', [
            'title' => 'صحيفة دعوى جديدة',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'status' => 'draft',
        ]);

        $doc = LegalDocument::where('title', 'صحيفة دعوى جديدة')->first();
        $response->assertRedirect("/lawyer/editor/{$doc->id}");
    }

    public function test_lawyer_can_edit_and_update_own_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'عنوان قديم',
            'type' => 'free',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>محتوى قديم</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->put("/lawyer/editor/{$doc->id}", [
            'title' => 'عنوان محدث ومعدل',
            'type' => 'memo',
            'content_html' => '<p>محتوى جديد بعد التعديل</p>',
            'content_json' => ['type' => 'doc'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('legal_documents', [
            'id' => $doc->id,
            'title' => 'عنوان محدث ومعدل',
            'type' => 'memo',
        ]);
    }

    public function test_lawyer_cannot_view_or_edit_another_lawyers_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند خاص بالمحامي الأول',
            'type' => 'contract',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>بيانات سرية</p>',
            'status' => 'draft',
        ]);

        // محاولة العرض من محامٍ آخر
        $responseGet = $this->actingAs($this->lawyerB)->get("/lawyer/editor/{$doc->id}");
        $this->assertPageRefused($responseGet);
        $responseGet->assertDontSee('بيانات سرية');

        // محاولة التعديل من محامٍ آخر
        $responsePut = $this->actingAs($this->lawyerB)->put("/lawyer/editor/{$doc->id}", [
            'title' => 'محاولة اختراق',
            'type' => 'contract',
            'content_html' => '<p>معدل</p>',
        ]);
        $responsePut->assertStatus(403);
    }

    public function test_admin_can_view_and_approve_any_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'لائحة استئناف معروضة للاعتماد',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>الطلبات الختامية...</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->post("/admin/editor/{$doc->id}/approve");

        $response->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertEquals('approved', $doc->status);
        $this->assertEquals($this->admin->id, $doc->approved_by);
        $this->assertNotNull($doc->approved_at);
    }

    public function test_print_page_renders_successfully(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند للطباعة',
            'type' => 'summary',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>محتوى جاهز للطباعة</p>',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->lawyerA)->get("/lawyer/editor/{$doc->id}/print");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor-print')
            ->has('document')
            ->where('document.title', 'مستند للطباعة')
        );
    }

    public function test_lawyer_can_open_create_page_with_template(): void
    {
        $response = $this->actingAs($this->lawyerA)->get('/lawyer/editor/create?template=najiz_lawsuit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor')
            ->where('incomingTemplate', 'najiz_lawsuit')
        );
    }

    public function test_ai_assist_endpoint_returns_smart_response(): void
    {
        $response = $this->actingAs($this->lawyerA)->postJson('/lawyer/editor/ai-assist', [
            'action' => 'rephrase',
            'text' => 'المدعى عليه أخل بالعقد ولم يسدد المستحق.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'action' => 'rephrase',
        ]);
        $this->assertNotEmpty($response->json('text'));
    }

    public function test_ai_assist_endpoint_suggests_legal_basis(): void
    {
        $response = $this->actingAs($this->lawyerA)->postJson('/lawyer/editor/ai-assist', [
            'action' => 'basis',
            'text' => 'نزاع بشأن عقد مقاولة وإخلال بالتسليم والتعويض عن الأضرار.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'action' => 'basis',
        ]);
        $this->assertNotEmpty($response->json('text'));
    }

    public function test_lawyer_sees_only_assigned_importables(): void
    {
        $client = User::factory()->create(['name' => 'العميل']);

        // قضية للمحامي الأول بانتظار اعتماد اللائحة
        $caseA = LegalCase::create([
            'number' => 'CAS-TEST-A',
            'type' => 'عمالية',
            'department' => 'المحكمة العمالية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        // قضية للمحامي الثاني بانتظار اعتماد اللائحة
        $caseB = LegalCase::create([
            'number' => 'CAS-TEST-B',
            'type' => 'تجارية',
            'department' => 'المحكمة التجارية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerB->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        $response = $this->actingAs($this->lawyerA)->getJson('/lawyer/editor/importables');

        $response->assertOk();
        $items = $response->json('items');
        $this->assertNotEmpty($items);

        $refs = collect($items)->pluck('ref')->all();
        $this->assertContains('CAS-TEST-A', $refs);
        $this->assertNotContains('CAS-TEST-B', $refs);
    }

    public function test_admin_sees_all_importables(): void
    {
        $client = User::factory()->create(['name' => 'العميل']);

        LegalCase::create([
            'number' => 'CAS-ADMIN-1',
            'type' => 'عمالية',
            'department' => 'المحكمة العمالية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        LegalCase::create([
            'number' => 'CAS-ADMIN-2',
            'type' => 'تجارية',
            'department' => 'المحكمة التجارية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerB->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/admin/editor/importables');

        $response->assertOk();
        $items = $response->json('items');
        $refs = collect($items)->pluck('ref')->all();

        $this->assertContains('CAS-ADMIN-1', $refs);
        $this->assertContains('CAS-ADMIN-2', $refs);
    }

    public function test_lawyer_can_open_create_page_with_imported_case_pleading(): void
    {
        $client = User::factory()->create(['name' => 'العميل']);

        $case = LegalCase::create([
            'number' => 'CAS-IMPORT-1',
            'type' => 'حقوقية',
            'department' => 'المحكمة العامة',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        $response = $this->actingAs($this->lawyerA)->get("/lawyer/editor/create?importType=case_pleading&id={$case->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor')
            ->where('incomingType', 'lawsuit')
            ->has('incomingDraft')
            ->where('case.id', $case->id)
            ->where('incomingMeta.source_type', 'case_pleading')
        );
    }

    public function test_html_to_plain_text_preserves_paragraphs_and_newlines(): void
    {
        $html = '<h2>لائحة دعوى</h2><p>لدى الدائرة الموقرة</p><p>الوقائع والأسانيد:<br/>أولاً: بتاريخ 1445هـ</p><ul><li>السند الأول</li><li>السند الثاني</li></ul>';
        $plain = RichHtml::toPlain($html);

        $this->assertStringContainsString("لائحة دعوى\n\nلدى الدائرة الموقرة", $plain);
        $this->assertStringContainsString("الوقائع والأسانيد:\nأولاً: بتاريخ 1445هـ", $plain);
        $this->assertStringContainsString("• السند الأول\n\n• السند الثاني", $plain);
        $this->assertStringNotContainsString('لائحة دعوىلدى', $plain, 'لا تلتصق الكلمات ببعضها كما في strip_tags');
    }

    public function test_approving_imported_case_pleading_saves_clean_formatted_text_to_case(): void
    {
        $client = User::factory()->create(['name' => 'العميل']);

        $case = LegalCase::create([
            'number' => 'CAS-APPROVE-1',
            'type' => 'تجارية',
            'department' => 'المحكمة التجارية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status' => 'قيد التحضير',
            'pleading_status' => 'pending_lawyer',
        ]);

        $doc = LegalDocument::create([
            'title' => 'لائحة دعوى تجارية',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'case_id' => $case->id,
            'metadata' => ['source_type' => 'case_pleading', 'case_id' => $case->id],
            'content_html' => '<h2>صحيفة الدعوى</h2><p>الوقائع والأسانيد:</p><p>أولاً: ثبت تخلف المدعى عليه.</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->post("/lawyer/editor/{$doc->id}/approve");

        $response->assertSessionHasNoErrors();
        $this->assertEquals('approved', $doc->fresh()->status);

        $draft = CasePleading::latestDraft($case);
        $this->assertNotNull($draft);
        $this->assertStringContainsString("صحيفة الدعوى\n\nالوقائع والأسانيد:\n\nأولاً: ثبت تخلف المدعى عليه.", $draft->body);
        $this->assertStringNotContainsString('صحيفة الدعوىالوقائع والأسانيد:أولاً:', $draft->body);
    }

    public function test_lawyer_can_open_print_preview_with_case_number_and_document_data(): void
    {
        $client = User::factory()->create(['name' => 'عميل القضية']);

        $case = LegalCase::create([
            'number' => 'CAS-PRINT-777',
            'type' => 'عمالية',
            'department' => 'المحكمة العمالية',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status' => 'قيد التحضير',
        ]);

        $doc = LegalDocument::create([
            'title' => 'مذكرة دفاع عمالية للطباعة',
            'type' => 'memo',
            'user_id' => $this->lawyerA->id,
            'case_id' => $case->id,
            'content_html' => '<p>محتوى المذكرة للطباعة</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->get("/lawyer/editor/{$doc->id}/print");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor-print')
            ->where('document.title', 'مذكرة دفاع عمالية للطباعة')
            ->where('document.caseNo', 'CAS-PRINT-777')
            ->has('document.headerConfig')
        );
    }

    public function test_unauthorized_lawyer_cannot_view_print_preview_of_other_lawyer_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند خاص بالمحامي الأول',
            'type' => 'free',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>سري للغاية</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerB)->get("/lawyer/editor/{$doc->id}/print");

        $this->assertPageRefused($response);
    }

    public function test_admin_can_view_print_preview_of_any_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند المحامي الأول متاح للإدارة للطباعة',
            'type' => 'contract',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>عقد رسمي</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get("/admin/editor/{$doc->id}/print");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('lawyer/editor-print')
            ->where('document.title', 'مستند المحامي الأول متاح للإدارة للطباعة')
        );
    }

    public function test_lawyer_can_download_document_as_pdf(): void
    {
        $doc = LegalDocument::create([
            'title' => 'لائحة دعوى نهائية للتنزيل',
            'type' => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<h2>لائحة دعوى</h2><p>محتوى اللائحة</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->get("/lawyer/editor/{$doc->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_unauthorized_lawyer_cannot_download_pdf_of_other_lawyer(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند خاص بالمحامي الأول',
            'type' => 'free',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>سري للغاية</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerB)->get("/lawyer/editor/{$doc->id}/pdf");

        $this->assertPageRefused($response);
    }

    public function test_admin_can_download_pdf_of_any_document(): void
    {
        $doc = LegalDocument::create([
            'title' => 'مستند المحامي متاح للإدارة',
            'type' => 'contract',
            'user_id' => $this->lawyerA->id,
            'content_html' => '<p>عقد رسمي</p>',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get("/admin/editor/{$doc->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
