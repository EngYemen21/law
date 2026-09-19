<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalDocument;
use App\Models\Ticket;
use App\Models\User;
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
        $this->admin   = User::factory()->create(['role' => Role::Admin, 'name' => 'المدير العام']);
    }

    public function test_lawyer_sees_only_their_own_documents_in_index(): void
    {
        LegalDocument::create([
            'title'        => 'لائحة المحامي الأول',
            'type'         => 'lawsuit',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>محتوى المحامي الأول</p>',
            'status'       => 'draft',
        ]);

        LegalDocument::create([
            'title'        => 'مذكرة المحامي الثاني',
            'type'         => 'memo',
            'user_id'      => $this->lawyerB->id,
            'content_html' => '<p>محتوى المحامي الثاني</p>',
            'status'       => 'draft',
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
            'title'        => 'لائحة المحامي الأول',
            'type'         => 'lawsuit',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>محتوى 1</p>',
            'status'       => 'draft',
        ]);

        LegalDocument::create([
            'title'        => 'مذكرة المحامي الثاني',
            'type'         => 'memo',
            'user_id'      => $this->lawyerB->id,
            'content_html' => '<p>محتوى 2</p>',
            'status'       => 'draft',
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
            'title'        => 'صحيفة دعوى جديدة',
            'type'         => 'lawsuit',
            'content_html' => '<h1>بسم الله الرحمن الرحيم</h1><p>وقائع الدعوى...</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
            'header_config' => [
                'showHeader' => true,
                'officeName' => 'مكتب المحاماة',
            ],
        ];

        $response = $this->actingAs($this->lawyerA)->post('/lawyer/editor', $payload);

        $this->assertDatabaseHas('legal_documents', [
            'title'   => 'صحيفة دعوى جديدة',
            'type'    => 'lawsuit',
            'user_id' => $this->lawyerA->id,
            'status'  => 'draft',
        ]);

        $doc = LegalDocument::where('title', 'صحيفة دعوى جديدة')->first();
        $response->assertRedirect("/lawyer/editor/{$doc->id}");
    }

    public function test_lawyer_can_edit_and_update_own_document(): void
    {
        $doc = LegalDocument::create([
            'title'        => 'عنوان قديم',
            'type'         => 'free',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>محتوى قديم</p>',
            'status'       => 'draft',
        ]);

        $response = $this->actingAs($this->lawyerA)->put("/lawyer/editor/{$doc->id}", [
            'title'        => 'عنوان محدث ومعدل',
            'type'         => 'memo',
            'content_html' => '<p>محتوى جديد بعد التعديل</p>',
            'content_json' => ['type' => 'doc'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('legal_documents', [
            'id'    => $doc->id,
            'title' => 'عنوان محدث ومعدل',
            'type'  => 'memo',
        ]);
    }

    public function test_lawyer_cannot_view_or_edit_another_lawyers_document(): void
    {
        $doc = LegalDocument::create([
            'title'        => 'مستند خاص بالمحامي الأول',
            'type'         => 'contract',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>بيانات سرية</p>',
            'status'       => 'draft',
        ]);

        // محاولة العرض من محامٍ آخر
        $responseGet = $this->actingAs($this->lawyerB)->get("/lawyer/editor/{$doc->id}");
        $responseGet->assertStatus(403);

        // محاولة التعديل من محامٍ آخر
        $responsePut = $this->actingAs($this->lawyerB)->put("/lawyer/editor/{$doc->id}", [
            'title'        => 'محاولة اختراق',
            'type'         => 'contract',
            'content_html' => '<p>معدل</p>',
        ]);
        $responsePut->assertStatus(403);
    }

    public function test_admin_can_view_and_approve_any_document(): void
    {
        $doc = LegalDocument::create([
            'title'        => 'لائحة استئناف معروضة للاعتماد',
            'type'         => 'lawsuit',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>الطلبات الختامية...</p>',
            'status'       => 'draft',
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
            'title'        => 'مستند للطباعة',
            'type'         => 'summary',
            'user_id'      => $this->lawyerA->id,
            'content_html' => '<p>محتوى جاهز للطباعة</p>',
            'status'       => 'approved',
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
            'text'   => 'المدعى عليه أخل بالعقد ولم يسدد المستحق.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'action'  => 'rephrase',
        ]);
        $this->assertNotEmpty($response->json('text'));
    }

    public function test_ai_assist_endpoint_suggests_legal_basis(): void
    {
        $response = $this->actingAs($this->lawyerA)->postJson('/lawyer/editor/ai-assist', [
            'action' => 'basis',
            'text'   => 'نزاع بشأن عقد مقاولة وإخلال بالتسليم والتعويض عن الأضرار.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'action'  => 'basis',
        ]);
        $this->assertNotEmpty($response->json('text'));
    }

    public function test_lawyer_sees_only_assigned_importables(): void
    {
        $client = User::factory()->create(['name' => 'العميل']);

        // قضية للمحامي الأول بانتظار اعتماد اللائحة
        $caseA = \App\Models\LegalCase::create([
            'number'             => 'CAS-TEST-A',
            'type'               => 'عمالية',
            'department'         => 'المحكمة العمالية',
            'user_id'            => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status'             => 'قيد التحضير',
            'pleading_status'    => 'pending_lawyer',
        ]);

        // قضية للمحامي الثاني بانتظار اعتماد اللائحة
        $caseB = \App\Models\LegalCase::create([
            'number'             => 'CAS-TEST-B',
            'type'               => 'تجارية',
            'department'         => 'المحكمة التجارية',
            'user_id'            => $client->id,
            'assigned_lawyer_id' => $this->lawyerB->id,
            'status'             => 'قيد التحضير',
            'pleading_status'    => 'pending_lawyer',
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

        \App\Models\LegalCase::create([
            'number'             => 'CAS-ADMIN-1',
            'type'               => 'عمالية',
            'department'         => 'المحكمة العمالية',
            'user_id'            => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status'             => 'قيد التحضير',
            'pleading_status'    => 'pending_lawyer',
        ]);

        \App\Models\LegalCase::create([
            'number'             => 'CAS-ADMIN-2',
            'type'               => 'تجارية',
            'department'         => 'المحكمة التجارية',
            'user_id'            => $client->id,
            'assigned_lawyer_id' => $this->lawyerB->id,
            'status'             => 'قيد التحضير',
            'pleading_status'    => 'pending_lawyer',
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

        $case = \App\Models\LegalCase::create([
            'number'             => 'CAS-IMPORT-1',
            'type'               => 'حقوقية',
            'department'         => 'المحكمة العامة',
            'user_id'            => $client->id,
            'assigned_lawyer_id' => $this->lawyerA->id,
            'status'             => 'قيد التحضير',
            'pleading_status'    => 'pending_lawyer',
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
}
