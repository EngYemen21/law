<?php

namespace Tests\Feature;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Invoice\RejectPaymentProof;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **إثبات التحويل اليدويّ يمرّ بالمحرّك** — رفعه (`invoice.submit_proof`) ورفضه (`invoice.reject_proof`)
 * على فاتورة استشارة (وهي ما يراقبه `StateWriteGuard`)، بالنتيجة المخزّنة نفسها التي كانت.
 */
class InvoiceProofWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function consultInvoice(User $client): Invoice
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => '—', 'day' => '—', 'time' => '—', 'when_label' => '—', 'status' => 'بانتظار السداد',
        ]);

        return Invoice::create([
            'user_id' => $client->id, 'consult_id' => $consult->id, 'number' => 'INV-PRF-'.uniqid(),
            'description' => 'استشارة', 'amount' => 575, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);
    }

    public function test_uploading_then_rejecting_a_proof_goes_through_the_engine(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $invoice = $this->consultInvoice($client);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('proof.jpg', 40, 'image/jpeg'),
        ])->assertSessionHas('success', 'تم استلام إثبات التحويل وسيُراجَع.');

        $invoice->refresh();
        $this->assertSame(['بانتظار مراجعة الإثبات', 'b-blue'], [$invoice->status, $invoice->tone]);
        $this->assertNotNull($invoice->proof_uploaded_at);
        $path = $invoice->proof_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($admin)->post(route('admin.invoices.proof.reject', $invoice), ['reason' => 'غير واضح'])
            ->assertSessionHas('flash', 'رُفض الإثبات وأُعيدت الفاتورة للاستحقاق وأُشعر العميل.');

        $invoice->refresh();
        $this->assertSame(['مستحقة', 'b-amber'], [$invoice->status, $invoice->tone]);
        $this->assertNull($invoice->proof_path);
        $this->assertNull($invoice->proof_uploaded_at);
        Storage::disk('local')->assertMissing($path);

        $rows = JourneyTransition::where('entity_type', 'Invoice')->where('entity_id', $invoice->id)->orderBy('id')->get();
        $this->assertSame(['invoice.submit_proof', 'invoice.reject_proof'], $rows->pluck('transition')->all());
        $this->assertSame('غير واضح', $rows[1]->reason);
    }

    /** المتحكّم يردّ المحصّلة 422 برسالته؛ والانتقال نفسه شبكةُ أمانٍ لو نودي مباشرة. */
    public function test_a_paid_invoice_proof_cannot_be_rejected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $invoice = $this->consultInvoice($client);
        $invoice->forceFill(['proof_path' => 'invoice-proofs/x.jpg', 'paid' => true])->saveQuietly();

        $this->actingAs($admin)->post(route('admin.invoices.proof.reject', $invoice))->assertStatus(422);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new RejectPaymentProof, $invoice->fresh(), $admin);
    }
}
