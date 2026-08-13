<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * رفع مستندات داعمة حقيقية عند فتح تذكرة جديدة (/tickets/new → POST /tickets) — نفس آلية
 * التخزين والفحص المستخدَمة فعلياً لإرفاق مستند لاحق داخل محادثة التذكرة (TicketController::attach).
 */
class TicketAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_supporting_documents_are_stored_on_ticket_creation(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);

        $file1 = UploadedFile::fake()->create('عقد.pdf', 200, 'application/pdf');
        $file2 = UploadedFile::fake()->image('هوية.jpg');

        $response = $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'details' => 'أطالب الطرف الآخر بمستحقاتي بموجب العقد.',
            'files' => [$file1, $file2],
        ]);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame(2, $ticket->attachments);
        $this->assertSame(2, TicketDocument::where('ticket_id', $ticket->id)->count());

        $doc = TicketDocument::where('ticket_id', $ticket->id)->where('name', 'عقد.pdf')->firstOrFail();
        $this->assertSame('قيد الفحص', $doc->status);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_ticket_creation_works_without_files(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'استفسار عام', 'details' => 'سؤال بسيط.',
        ])->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame(0, $ticket->attachments);
    }

    public function test_rejects_oversized_attachment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $tooBig = UploadedFile::fake()->create('كبير.pdf', 10241); // >10MB

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'details' => 'تفاصيل.', 'files' => [$tooBig],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, Ticket::count());
    }

    public function test_rejects_disallowed_extension_on_creation(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exe = UploadedFile::fake()->create('برنامج.exe', 50, 'application/octet-stream');

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'details' => 'تفاصيل.', 'files' => [$exe],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, Ticket::count());
    }

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'details' => 'تفاصيل الطلب.',
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_attach_in_chat_accepts_allowed_extension(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('مرفق.pdf', 100, 'application/pdf'),
        ])->assertNoContent();

        $this->assertSame(1, TicketDocument::where('ticket_id', $ticket->id)->count());
    }

    public function test_attach_in_chat_rejects_disallowed_extension(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('سكربت.exe', 50, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, TicketDocument::where('ticket_id', $ticket->id)->count());
    }
}
