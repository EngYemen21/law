<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الملفات والتخزين (المرحلة 3): تنزيل الإدارة · تحقّق نوع الملف · إثبات السداد · تنظيف المرفقات.
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private function clientTicketWithDoc(): array
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7001', 'type' => 'استشارة قانونية',
            'department' => 'القانون التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);

        $path = 'ticket-docs/'.$ticket->id.'/evidence.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake');

        $doc = TicketDocument::create([
            'ticket_id' => $ticket->id, 'name' => 'صورة العقد.pdf', 'path' => $path,
            'mime' => 'application/pdf', 'size' => 13, 'uploaded_by' => 'العميل',
        ]);

        return [$client, $ticket, $doc];
    }

    private function caseWithDoc(): array
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'C-2026-501', 'title' => 'قضية', 'court' => 'المحكمة', 'type' => 'قضية تجارية',
            'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);

        $path = 'case-docs/'.$case->id.'/deed.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 case');

        $doc = CaseDocument::create([
            'case_id' => $case->id, 'name' => 'صك.pdf', 'path' => $path,
            'mime' => 'application/pdf', 'size' => 13, 'uploaded_by' => 'client',
        ]);

        return [$case, $doc, $lawyer];
    }

    /**
     * 🔴 لوحة الإدارة تبني روابط documents.download-file في ملفّ العميل، والمتحكّم كان
     * يفلتر بـ$request->user()->id — وهو الإداريّ لا العميل — فيرمي findOrFail دائماً 404.
     */
    public function test_admin_can_download_a_clients_ticket_document(): void
    {
        [, , $doc] = $this->clientTicketWithDoc();
        $admin = User::factory()->create(['role' => Role::Admin]);

        // النظير الإداري admin.documents.download-file — بوابة العميل صارت مقفلة على الأدمن (2026-08-28)
        $this->actingAs($admin)
            ->get(route('admin.documents.download-file', ['type' => 'ticket', 'id' => $doc->id]))
            ->assertOk()
            ->assertHeaderMissing('x-error');

        // المحتوى هو الدليل — ترويسة الاسم تُرمَّز (RFC 5987) للأسماء العربية
        $this->assertSame('%PDF-1.4 fake', Storage::disk('local')->get($doc->path));
    }

    /** العميل صاحب المستند يبقى قادراً. */
    public function test_owner_can_still_download_their_document(): void
    {
        [$client, , $doc] = $this->clientTicketWithDoc();

        $this->actingAs($client)
            ->get(route('documents.download-file', ['type' => 'ticket', 'id' => $doc->id]))
            ->assertOk();
    }

    /** عميل آخر لا يصل — الفلترة بالمالك لا تُفتح للجميع. */
    public function test_another_client_cannot_download_it(): void
    {
        [, , $doc] = $this->clientTicketWithDoc();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)
            ->get(route('documents.download-file', ['type' => 'ticket', 'id' => $doc->id]))
            ->assertNotFound();
    }

    /**
     * 🔴 رفعان كانا بلا `mimes:` بينما كل رفوعات المشروع الأخرى تفرض قائمة سماح
     * صريحة تستثني التنفيذيّ والمضغوط (راجع TicketController::ALLOWED_DOC_MIMES).
     */
    public function test_client_document_upload_rejects_executable_files(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)
            ->post(route('documents.store'), [
                'name' => 'ملف',
                'file' => UploadedFile::fake()->create('payload.exe', 8, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('file');
    }

    /** وإثبات السداد كذلك — نفس قائمة السماح. */
    public function test_invoice_proof_upload_rejects_executable_files(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PROOF-1', 'description' => 'أتعاب',
            'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);

        $this->actingAs($client)
            ->post(route('invoices.proof', $invoice), [
                'file' => UploadedFile::fake()->create('payload.exe', 8, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('file');
    }

    /** الملف السليم يُقبل — التضييق لا يكسر المسار. */
    public function test_invoice_proof_accepts_a_pdf(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PROOF-2', 'description' => 'أتعاب',
            'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);

        $this->actingAs($client)
            ->post(route('invoices.proof', $invoice), [
                'file' => UploadedFile::fake()->create('receipt.pdf', 8, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($invoice->fresh()->proof_path);
    }

    /**
     * 🔴 proof_path كان يُكتب ولا يقرؤه أحد: لا مسار ولا مكوّن — فحالة «بانتظار مراجعة
     * الإثبات» طريق مسدود لا يستطيع المراجع إغلاقه.
     */
    public function test_admin_can_download_a_payment_proof(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PROOF-3', 'description' => 'أتعاب',
            'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('receipt.pdf', 8, 'application/pdf'),
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.proof', $invoice->fresh()))
            ->assertOk();
    }

    /** والعميل لا يصل إثبات فاتورة غيره. */
    public function test_client_cannot_download_another_clients_proof(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PROOF-4', 'description' => 'أتعاب',
            'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);
        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('receipt.pdf', 8, 'application/pdf'),
        ]);

        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)
            ->get(route('admin.invoices.proof', $invoice->fresh()))
            ->assertStatus(302);
    }

    /**
     * 🔴 لم يكن للطاقم مسار تنزيل إطلاقاً: المحامي المسنَد يرى أنّ مستنداً رُفع على قضيّته
     * ويقرأ ملخّصه ولا يستطيع فتحه. القرار: **المحامي المسنَد** (والإدارة إشرافاً). وحجبُ الموظّف
     * نُقض بقرار المالك 2026-09-11 — قاعدته في `ConversationFileDownloadTest`.
     */
    public function test_assigned_lawyer_can_download_a_case_document(): void
    {
        [$case, $doc, $lawyer] = $this->caseWithDoc();

        $this->actingAs($lawyer)
            ->get(route('lawyer.documents.download', ['type' => 'case', 'id' => $doc->id]))
            ->assertOk();
    }

    /** محامٍ غير مسنَد لا يصل — العزل بالإسناد. */
    public function test_unassigned_lawyer_cannot_download_a_case_document(): void
    {
        [, $doc] = $this->caseWithDoc();
        $outsider = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($outsider)
            ->get(route('lawyer.documents.download', ['type' => 'case', 'id' => $doc->id]))
            ->assertForbidden();
    }

    /**
     * 🔓 **قرار المالك 2026-09-11 (ينقض السابق):** الموظّف يُنزّل مرفقات القضيّة التي يفتحها —
     * عبر المسار الموحّد. ومسار المحامي يبقى للمحامي: الموظّف يُحوَّل عنه كما كان.
     */
    public function test_employee_downloads_a_case_document_through_the_shared_route(): void
    {
        [, $doc] = $this->caseWithDoc();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)
            ->get(route('files.download', ['type' => 'case', 'id' => $doc->id]))
            ->assertOk();

        $this->actingAs($employee)
            ->get(route('lawyer.documents.download', ['type' => 'case', 'id' => $doc->id]))
            ->assertRedirect(Role::Employee->home());
    }

    /** ويظهر له رابط التنزيل في بيانات الشاشة. */
    public function test_employee_case_screen_exposes_the_download_url(): void
    {
        [$case, $doc] = $this->caseWithDoc();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)
            ->get(route('employee.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where(
                'documents.0.downloadUrl',
                route('files.download', ['type' => 'case', 'id' => $doc->id]),
            ));
    }

    /** والمحامي المسنَد يظهر له الرابط. */
    public function test_assigned_lawyer_case_screen_exposes_the_download_url(): void
    {
        [$case, $doc, $lawyer] = $this->caseWithDoc();

        $this->actingAs($lawyer)
            ->get(route('lawyer.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where(
                'documents.0.downloadUrl',
                route('files.download', ['type' => 'case', 'id' => $doc->id]),
            ));
    }

    /**
     * 🔴 لا خطّاف حذف على أي نموذج مستند ولا نداء Storage::delete في المشروع كلّه —
     * حذف التذكرة يُسقط صفوف المستندات بالتسلسل ويترك ملفاتها يتيمة بلا مسار يدلّ عليها.
     */
    public function test_deleting_a_ticket_removes_its_stored_files(): void
    {
        [, $ticket, $doc] = $this->clientTicketWithDoc();
        $path = $doc->path;

        Storage::disk('local')->assertExists($path);

        $ticket->delete();

        Storage::disk('local')->assertMissing($path);
    }
}
