<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دفع فاتورة حقيقي عبر بوّابة ميسّر (زر «ادفع عبر ميسّر» في /invoices) + فاتورة PDF حقيقية.
 * لا دفع حقيقي في الاختبار — Http::fake. بلا مفتاح مهيّأ تُرفض نقطة الدفع (503) — لا تزوير.
 */
class InvoiceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function pendingInvoice(User $client): Invoice
    {
        return Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-GEN-1', 'description' => 'أتعاب استشارة قانونية',
            'amount' => 750, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 5 أيام', 'paid' => false,
        ]);
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
    }

    public function test_checkout_requires_gateway_configured(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($client);

        $this->actingAs($client)->post(route('invoices.checkout', $invoice))->assertStatus(503);

        $this->assertFalse($invoice->fresh()->paid);
    }

    public function test_checkout_initiates_moyasar_and_redirects_when_configured(): void
    {
        $this->configureMoyasar();
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_gen1', 'url' => 'https://moyasar.test/pay/inv_gen1'], 201)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($client);

        $this->actingAs($client)->post(route('invoices.checkout', $invoice))
            ->assertRedirect('https://moyasar.test/pay/inv_gen1');

        $this->assertFalse($invoice->fresh()->paid); // لا دفع قبل التأكيد (callback/webhook)
        $this->assertSame('inv_gen1', $invoice->fresh()->gateway_ref);
    }

    public function test_checkout_rejects_already_paid_invoice(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($client);
        $invoice->update(['paid' => true]);

        $this->actingAs($client)->post(route('invoices.checkout', $invoice))->assertStatus(422);
    }

    public function test_checkout_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($owner);

        $this->actingAs($intruder)->post(route('invoices.checkout', $invoice))->assertForbidden();
    }

    public function test_callback_verifies_via_api_and_settles(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($client);
        $invoice->update(['gateway_ref' => 'inv_cb1']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 75000, 'currency' => 'SAR', 'invoice_id' => 'inv_cb1',
            'metadata' => ['invoice_number' => 'INV-GEN-1'],
        ], 200)]);

        $this->actingAs($client)->get(route('invoices.checkout.callback', $invoice).'?id=pay_1')
            ->assertRedirect(route('invoices'))->assertSessionHas('success');

        $this->assertTrue($invoice->fresh()->paid);
    }

    public function test_callback_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($owner);

        $this->actingAs($intruder)->get(route('invoices.checkout.callback', $invoice).'?id=pay_1')->assertForbidden();
    }

    public function test_client_downloads_real_pdf_invoice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($client);

        $response = $this->actingAs($client)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('INV-GEN-1.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->pendingInvoice($owner);

        $this->actingAs($intruder)->get(route('invoices.pdf', $invoice))->assertForbidden();
    }
}
