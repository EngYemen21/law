<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Invoice\SubmitPaymentProof;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **لا إثبات تحويلٍ لفاتورةٍ ملغاة ولا لمدفوعة** (قرار المالك 2026-09-19).
 *
 * 🔴 كان الرفع بلا شرط: إثباتٌ على فاتورة الـ500 الملغاة بعد إعادة تسعيرها يمحو علامة إلغائها —
 * تصير «بانتظار مراجعة الإثبات» — ولا يعرف زرّ «تحصيل» (`settleManual`) أنّها أُلغيت فيُحصّلها
 * ويسدّد بها الاستشارة. والملغاة كانت تُعرض «متأخرة» حمراء بعد استحقاقها وزرّا الدفع ظاهران.
 */
class CancelledInvoiceProofTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(User $client, array $extra = []): Invoice
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-CXL-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => '—', 'status' => 'بانتظار السداد',
        ]);

        return Invoice::create(array_merge([
            'user_id' => $client->id, 'consult_id' => $consult->id, 'number' => 'INV-CXL-'.uniqid(),
            'description' => 'استشارة', 'amount' => 575, 'status' => 'ملغاة', 'tone' => 'b-red',
            'due_label' => 'خلال 3 أيام', 'due_at' => now()->subDays(5)->toDateString(), 'paid' => false,
        ], $extra));
    }

    public function test_a_proof_on_a_cancelled_invoice_is_refused_and_leaves_no_trace(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('proof.jpg', 40, 'image/jpeg'),
        ])->assertStatus(422)->assertSee(SubmitPaymentProof::CANCELLED);

        $invoice->refresh();
        $this->assertSame('ملغاة', $invoice->status, 'علامة الإلغاء باقية');
        $this->assertNull($invoice->proof_path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'لا ملفّ يتيم على القرص');

        // والتحصيل ما زال يعرف أنّها ملغاة — فلا تُسدَّد بها الاستشارة
        $this->assertFalse(PaymentReconciler::settleManual($invoice, 'الإدارة'));
        $this->assertFalse((bool) $invoice->fresh()->paid);
    }

    public function test_a_proof_on_a_paid_invoice_is_refused(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client, ['status' => 'مدفوعة', 'paid' => true]);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('proof.jpg', 40, 'image/jpeg'),
        ])->assertStatus(422)->assertSee(SubmitPaymentProof::PAID);

        $this->assertNull($invoice->fresh()->proof_path);
    }

    /** الملغاة لا «تتأخّر»، والشاشة تعرفها فتُخفي الدفع والإثبات والتحصيل. */
    public function test_a_cancelled_invoice_is_shown_as_cancelled_not_overdue(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $card = $this->invoice($client)->toCard();

        $this->assertTrue($card['cancelled']);
        $this->assertFalse($card['overdue']);
        $this->assertSame('ملغاة', $card['status'], 'لا «متأخرة» حمراء لما أُلغي');

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->get(route('admin.accounting'))->assertInertia(fn ($p) => $p->where('totals.overdue', 0));
    }

    /** المستحقّة العاديّة كما كانت: الرفع يُقبل. */
    public function test_a_due_invoice_still_accepts_a_proof(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client, ['status' => 'مستحقة', 'tone' => 'b-amber', 'due_at' => now()->addDays(3)->toDateString()]);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('proof.jpg', 40, 'image/jpeg'),
        ])->assertRedirect();

        $this->assertSame('بانتظار مراجعة الإثبات', $invoice->fresh()->status);
    }
}
