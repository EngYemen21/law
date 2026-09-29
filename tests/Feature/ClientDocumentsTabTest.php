<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Document;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **تبويب «المستندات» للعميل: الصادرة من المكتب وحده، وما رفعه هو في «مستنداتك المرفوعة»** (قرار المالك 2026-09-29).
 *
 * ثبت قبل الإصلاح: مرفقات العميل من محادثات القضيّة والتذكرة والتنفيذ كانت تُعرض «صادرةً إليك»، ومرفق
 * التنفيذ بوسم «قرار 34/46» كأنّ المكتب أصدره؛ وقسم «المرفوعة» يعرض رفع الصفحة وحده.
 */
class ClientDocumentsTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_uploads_and_office_documents_land_in_their_own_sections_and_download(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-DOC-1', 'type' => 'استشارة', 'subject' => 'نزاع', 'status' => 'جديدة', 'tone' => 'b-blue', 'priority' => 'متوسطة']);
        $case = LegalCase::create(['user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => 'م', 'number' => 'CASE-DOC-1', 'title' => 'دعوى', 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'court' => 'م', 'circuit' => 'د', 'opponent_name' => 'خ', 'update_text' => '—']);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EX-DOC-1', 'subject' => 'سند', 'stage' => 7, 'status' => 'قيد التنفيذ', 'tone' => 'b-purple', 'assigned_lawyer_id' => $lawyer->id]);

        $pdf = fn (string $n) => UploadedFile::fake()->create($n, 20, 'application/pdf');
        // ما يرفعه العميل من المحادثات الثلاث ومن الصفحة نفسها
        $this->actingAs($client)->post(route('tickets.attach', $ticket), ['file' => $pdf('client-ticket.pdf')]);
        $this->actingAs($client)->post(route('cases.attach', $case), ['file' => $pdf('client-case.pdf')]);
        $this->actingAs($client)->post(route('exec-flow.attach', $exec), ['file' => $pdf('client-exec.pdf')]);
        $this->actingAs($client)->post(route('documents.store'), ['file' => $pdf('client-direct.pdf')]);
        // وما يصدره المكتب
        Storage::put('office/case.pdf', 'x');
        $case->documents()->create(['name' => 'office-case.pdf', 'path' => 'office/case.pdf', 'uploaded_by' => 'lawyer', 'status' => 'معتمد']);
        Storage::put('office/ticket.pdf', 'x');
        $ticket->documents()->create(['name' => 'office-ticket.pdf', 'path' => 'office/ticket.pdf', 'status' => TicketDocument::FROM_OFFICE]);
        Storage::put('office/direct.pdf', 'x');
        Document::create(['user_id' => $client->id, 'name' => 'office-direct.pdf', 'meta' => 'م', 'direction' => 'out', 'path' => 'office/direct.pdf']);

        $props = $this->actingAs($client)->get(route('documents'))->viewData('page')['props'];
        $names = fn (array $docs) => collect($docs)->pluck('name')->sort()->values()->all();

        $this->assertSame(['office-case.pdf', 'office-direct.pdf', 'office-ticket.pdf'], $names($props['docsOut']));
        $this->assertSame(['client-case.pdf', 'client-direct.pdf', 'client-exec.pdf', 'client-ticket.pdf'], $names($props['docsUp']));
        $this->assertSame('مستند تنفيذ · مرفوع منك', collect($props['docsUp'])->firstWhere('name', 'client-exec.pdf')['meta'], 'وسم «قرار 34/46» على مرفق العميل');

        foreach (array_merge($props['docsOut'], $props['docsUp']) as $doc) {
            $this->actingAs($client)->get($doc['downloadUrl'])->assertOk();
        }
    }

    public function test_an_office_attachment_copied_into_an_execution_stays_issued_by_the_office(): void
    {
        $officeDoc = new TicketDocument(['status' => TicketDocument::FROM_OFFICE]);
        $clientDoc = new TicketDocument(['status' => 'قيد الفحص']);

        $this->assertFalse($officeDoc->isFromClient());
        $this->assertTrue($clientDoc->isFromClient());
        $this->assertStringContainsString(
            "'uploaded_by' => \$td instanceof TicketDocument && ! \$td->isFromClient() ? 'staff' : 'client'",
            (string) file_get_contents(app_path('Support/ExecutionCreation.php')),
        );
    }
}
