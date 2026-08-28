<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_downloads_real_invoice_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-2026-9901',
            'amount' => 1500,
            'description' => 'أتعاب استشارة ودراسة قضية تجارية',
            'status' => 'مدفوعة',
            'paid' => true,
            'due_label' => '2026-08-30',
        ]);

        $response = $this->actingAs($client)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('INV-2026-9901.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * زرّ «الفاتورة PDF» في تبويب الفواتير والمحاسبة (الإدارة) — صار له نظير admin خاص
     * (قرار 2026-08-28: الأدمن لا يمرّ عبر بوابة دور العميل إطلاقًا)، ونفس المتحكّم يخدمه.
     */
    public function test_admin_downloads_any_invoice_pdf_from_accounting_tab(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-2026-9903',
            'amount' => 2000,
            'description' => 'أتعاب قضية عمّالية',
            'status' => 'مستحقة',
            'paid' => false,
            'due_label' => '2026-09-15',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // وبوابة دور العميل مقفلة على الأدمن حتى لهذه النقطة — النظير الإداري هو الطريق الوحيد
        $this->actingAs($admin)->get(route('invoices.pdf', $invoice))->assertRedirect('/admin/dashboard');
    }

    public function test_other_client_cannot_download_foreign_invoice_pdf(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $owner->id,
            'number' => 'INV-2026-9902',
            'amount' => 500,
            'description' => 'استشارة قانونية',
            'status' => 'مستحقة',
            'paid' => false,
            'due_label' => '2026-08-30',
        ]);

        $this->actingAs($intruder)->get(route('invoices.pdf', $invoice))->assertForbidden();
    }
}
