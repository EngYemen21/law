<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Support\ExecutionCreation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExecutionConversionDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Queue::fake();
    }

    public function test_converting_ticket_to_execution_migrates_ticket_documents_to_execution_documents(): void
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TK-DOC-1',
            'type' => 'تنفيذ',
            'subject' => 'تحصيل كمبيالة وسند لأمر',
            'opponent_name' => 'شركة المقاولات المتحدة',
            'claim_amount' => 150000,
            'status' => TicketStatus::ReadyForOutcome->value,
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $file = UploadedFile::fake()->create('promissory_note.pdf', 500, 'application/pdf');
        $storedPath = $file->storeAs("ticket-docs/{$ticket->id}", 'promissory_note.pdf', 'local');

        $td1 = TicketDocument::create([
            'ticket_id' => $ticket->id,
            'name' => 'سند_لأمر.pdf',
            'path' => $storedPath,
            'mime' => 'application/pdf',
            'size' => 512000,
            'status' => 'مرتبط',
            'doc_type' => 'سند لأمر',
            'summary' => 'سند لأمر بقيمة 150 ألف ريال مستحق الوفاء.',
        ]);

        $td2 = TicketDocument::create([
            'ticket_id' => $ticket->id,
            'name' => 'عقد_التوريد.pdf',
            'path' => 'ticket-docs/fake_contract.pdf',
            'mime' => 'application/pdf',
            'size' => 102400,
            'status' => 'مرفوع',
            'doc_type' => 'عقد تنفيذي',
            'summary' => 'عقد توريد مبرم بين الطرفين.',
        ]);

        $exec = ExecutionCreation::fromTicket($ticket, $admin, 'تحويل لاعتماد السند لأمر');

        // 1. التحقق من إنشاء سجلات في جدول execution_documents
        $this->assertSame(2, $exec->documents()->count());

        $execDoc1 = $exec->documents()->where('label', 'سند_لأمر.pdf')->first();
        $this->assertNotNull($execDoc1);
        $this->assertSame('سند لأمر', $execDoc1->doc_type);
        $this->assertSame('سند لأمر بقيمة 150 ألف ريال مستحق الوفاء.', $execDoc1->summary);
        $this->assertSame('مرفوع', $execDoc1->status);
        $this->assertNotSame($td1->path, $execDoc1->path, 'يتم نسخ الملف لمسار تنفيذي مستقل');
        $this->assertStringStartsWith("exec-docs/{$exec->id}/", (string) $execDoc1->path);
        Storage::disk('local')->assertExists((string) $execDoc1->path);

        // 2. التحقق من كود العميل واستنتاج السند
        $this->assertSame('CL-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT), $exec->client_code);
        $this->assertSame('سند لأمر', $exec->sanad);

        // 3. التحقق من جاهزية البيانات للواجهة الأمامية (toFlowCard)
        $card = $exec->toFlowCard(false, true);
        $this->assertCount(2, $card['docItems']);
        $labels = collect($card['docItems'])->pluck('label')->all();
        $this->assertContains('سند_لأمر.pdf', $labels);
        $this->assertContains('عقد_التوريد.pdf', $labels);
        $this->assertNotNull($card['docItems'][0]['fileName']);
    }

    public function test_deleting_execution_document_does_not_purge_original_ticket_file(): void
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TK-PURGE-1',
            'type' => 'تنفيذ',
            'subject' => 'سند تنفيذي',
            'status' => TicketStatus::ReadyForOutcome->value,
        ]);

        $file = UploadedFile::fake()->create('sanad.pdf', 200, 'application/pdf');
        $ticketPath = $file->storeAs("ticket-docs/{$ticket->id}", 'sanad.pdf', 'local');

        TicketDocument::create([
            'ticket_id' => $ticket->id,
            'name' => 'sanad.pdf',
            'path' => $ticketPath,
            'mime' => 'application/pdf',
            'size' => 204800,
            'status' => 'مرتبط',
            'doc_type' => 'سند لأمر',
        ]);

        $exec = ExecutionCreation::fromTicket($ticket, $admin, 'اعتماد التنفيذ');
        $execDoc = $exec->documents()->first();
        $this->assertNotNull($execDoc);

        $execFilePath = (string) $execDoc->path;
        Storage::disk('local')->assertExists($ticketPath);
        Storage::disk('local')->assertExists($execFilePath);

        // حذف مستند التنفيذ
        $execDoc->delete();

        // يجب أن يُحذف ملف التنفيذ من القرص
        Storage::disk('local')->assertMissing($execFilePath);
        // ولكن ملف التذكرة الأصلي يجب أن يبقى آمناً وموجوداً على القرص!
        Storage::disk('local')->assertExists($ticketPath);
    }
}
