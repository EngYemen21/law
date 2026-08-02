<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تحقّق من الأزرار التي كانت وهميّة (المستندات/الفواتير/الملف الشخصي) بعد جعلها حقيقيّة.
 */
class ClientAccountActionsTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    // ── الملف الشخصي ──

    public function test_client_can_save_profile_fields(): void
    {
        $client = $this->client();

        $this->actingAs($client)->post('/profile', [
            'name' => 'عبدالله محمد',
            'phone' => '0551112223',
            'email' => 'abdullah@example.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'name' => 'عبدالله محمد',
            'phone' => '0551112223',
            'email' => 'abdullah@example.com',
        ]);
    }

    public function test_profile_email_must_be_unique(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $client = $this->client();

        $this->actingAs($client)->post('/profile', [
            'name' => 'اسم',
            'email' => 'taken@example.com',
        ])->assertSessionHasErrors('email');
    }

    // ── المستندات ──

    public function test_client_can_upload_document_and_download_it(): void
    {
        Storage::fake('local');
        $client = $this->client();

        $this->actingAs($client)->post('/documents', [
            'file' => UploadedFile::fake()->create('عقد.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $doc = Document::where('user_id', $client->id)->first();
        $this->assertNotNull($doc);
        $this->assertSame('up', $doc->direction);
        $this->assertNotNull($doc->path);
        Storage::disk('local')->assertExists($doc->path);

        $this->actingAs($client)->get(route('documents.download', $doc))->assertOk();
    }

    public function test_document_upload_requires_file(): void
    {
        // بلا ملفّ → خطأ تحقّق يصل الواجهة (onError) ويُعرض كـ toast
        $this->actingAs($this->client())->post('/documents', [])
            ->assertSessionHasErrors('file');
    }

    public function test_download_forbidden_for_non_owner(): void
    {
        Storage::fake('local');
        $owner = $this->client();
        $intruder = $this->client();

        $this->actingAs($owner)->post('/documents', [
            'file' => UploadedFile::fake()->create('id.pdf', 50),
        ])->assertRedirect();
        $doc = Document::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($intruder)->get(route('documents.download', $doc))->assertForbidden();
    }

    public function test_download_missing_file_returns_404(): void
    {
        $client = $this->client();
        $doc = Document::create([
            'user_id' => $client->id, 'name' => 'صادر.pdf', 'meta' => 'PDF', 'direction' => 'out',
        ]); // بلا path (مستند صادر بلا ملفّ)

        $this->actingAs($client)->get(route('documents.download', $doc))->assertNotFound();
    }

    // ── الفواتير ──

    public function test_client_can_upload_transfer_proof(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-9001', 'description' => 'أتعاب',
            'amount' => 1200, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال أسبوع', 'paid' => false,
        ]);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('proof.jpg', 80, 'image/jpeg'),
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertNotNull($invoice->proof_path);
        $this->assertNotNull($invoice->proof_uploaded_at);
        $this->assertSame('بانتظار مراجعة الإثبات', $invoice->status);
        Storage::disk('local')->assertExists($invoice->proof_path);
    }

    public function test_proof_upload_requires_file(): void
    {
        $client = $this->client();
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-9003', 'description' => 'أتعاب',
            'amount' => 700, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
        ]);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [])
            ->assertSessionHasErrors('file');
    }

    public function test_proof_upload_forbidden_for_non_owner(): void
    {
        Storage::fake('local');
        $owner = $this->client();
        $intruder = $this->client();
        $invoice = Invoice::create([
            'user_id' => $owner->id, 'number' => 'INV-9002', 'description' => 'أتعاب',
            'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
        ]);

        $this->actingAs($intruder)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('x.pdf', 10),
        ])->assertForbidden();
    }
}
