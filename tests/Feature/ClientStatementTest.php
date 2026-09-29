<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Finance\ClientStatement;
use App\Support\Finance\ClientStatementDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * **كشف حساب العميل** (المرحلة ج): عليه الفاتورة الصادرة، وله المقبوض والملغى والمشطوب، ورصيدٌ
 * افتتاحيّ وجارٍ وختاميّ — والعميل يرى كشفه وحده، والإدارة كشف أيّ عميل.
 */
class ClientStatementTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'name' => 'عميل الكشف']);
    }

    private function invoice(User $client, string $number, int $amount, InvoiceStatus $status, string $issuedAt, array $extra = []): Invoice
    {
        return Invoice::create($extra + [
            'user_id' => $client->id, 'number' => $number, 'description' => 'أتعاب '.$number,
            'amount' => $amount, 'status' => $status->value, 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
            'issued_at' => $issuedAt,
        ]);
    }

    private function received(Invoice $invoice, int $halalas, string $at, string $receiptNo): Payment
    {
        return Payment::create([
            'invoice_id' => $invoice->id, 'gateway' => 'manual', 'method' => 'cash', 'status' => Payment::PAID,
            'amount' => intdiv($halalas, 100), 'amount_halalas' => $halalas, 'currency' => 'SAR', 'source_channel' => 'admin',
            'receipt_no' => $receiptNo, 'received_at' => $at, 'reconciled_at' => $at,
        ]);
    }

    public function test_opening_running_and_closing_balances_follow_the_period(): void
    {
        $client = $this->client();
        // قبل الفترة: فاتورة 1000 سُدّد منها 400 ⇒ افتتاحيّ 600
        $old = $this->invoice($client, 'INV-S-1', 1000, InvoiceStatus::PartiallyPaid, '2026-01-10 10:00:00');
        $this->received($old, 40000, '2026-01-20 10:00:00', 'RV-2026-00001');
        // في الفترة: فاتورة 500 ثمّ سداد باقي الأولى 600
        $this->invoice($client, 'INV-S-2', 500, InvoiceStatus::Due, '2026-03-05 10:00:00');
        $this->received($old, 60000, '2026-03-10 10:00:00', 'RV-2026-00002');
        // بعد الفترة: لا يدخل
        $this->invoice($client, 'INV-S-3', 900, InvoiceStatus::Due, '2026-05-01 10:00:00');

        $s = ClientStatement::build($client, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertSame(60000, $s['opening']);
        $this->assertSame(50000, $s['debit']);
        $this->assertSame(60000, $s['credit']);
        $this->assertSame(50000, $s['closing']);
        $this->assertSame([110000, 50000], array_column($s['rows'], 'balance'));
        $this->assertSame(['فاتورة', 'سند قبض'], array_column($s['rows'], 'kind'));
        $this->assertSame('RV-2026-00002', $s['rows'][1]['ref']);
    }

    public function test_paid_invoice_without_payment_row_is_credited_not_left_as_debt(): void
    {
        $client = $this->client();
        $this->invoice($client, 'INV-S-OLD', 700, InvoiceStatus::Paid, '2026-02-01 09:00:00', ['paid' => true, 'paid_at' => '2026-02-03 09:00:00']);

        $s = ClientStatement::build($client, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0, $s['closing']);
        $this->assertSame(['فاتورة', 'سداد'], array_column($s['rows'], 'kind'));
        $this->assertSame('2026-02-03', $s['rows'][1]['date']);
    }

    public function test_paid_invoice_with_partial_payment_rows_is_credited_only_the_remainder(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 'INV-S-MIX', 1000, InvoiceStatus::Paid, '2026-02-01 09:00:00', ['paid' => true, 'paid_at' => '2026-02-09 09:00:00']);
        $this->received($invoice, 30000, '2026-02-05 09:00:00', 'RV-2026-00009');

        $s = ClientStatement::build($client, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0, $s['closing'], 'لا يُقيَّد السداد مرّتين');
        $this->assertSame([0, 30000, 70000], array_column($s['rows'], 'credit'));
    }

    public function test_cancelled_and_written_off_invoices_are_credited_and_drafts_are_excluded(): void
    {
        $client = $this->client();
        $this->invoice($client, 'INV-S-C', 300, InvoiceStatus::Cancelled, '2026-04-01 09:00:00', ['cancelled_at' => '2026-04-02 09:00:00']);
        $this->invoice($client, 'INV-S-W', 200, InvoiceStatus::WrittenOff, '2026-04-01 09:00:00', ['written_off_at' => '2026-04-20 09:00:00']);
        $this->invoice($client, 'INV-S-D', 999, InvoiceStatus::Draft, '2026-04-01 09:00:00');

        $s = ClientStatement::build($client, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0, $s['closing']);
        $this->assertSame(50000, $s['debit'], 'المسوّدة لم تصدر فلا قيد لها');
        $this->assertContains('إلغاء فاتورة', array_column($s['rows'], 'kind'));
        $this->assertContains('شطب دين', array_column($s['rows'], 'kind'));
        $this->assertNotContains('INV-S-D', array_column($s['rows'], 'ref'));
    }

    public function test_client_sees_only_own_statement_with_default_fiscal_year_period(): void
    {
        $client = $this->client();
        $other = $this->client();
        $this->invoice($client, 'INV-MINE', 400, InvoiceStatus::Due, now()->toDateTimeString());
        $this->invoice($other, 'INV-THEIRS', 800, InvoiceStatus::Due, now()->toDateTimeString());

        $this->actingAs($client)->get('/statement')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('statement')
            ->where('client.id', $client->id)
            ->where('statement.from', now()->startOfYear()->toDateString())
            ->where('statement.to', now()->toDateString())
            ->where('statement.closing', 40000)
            ->where('statement.rows.0.ref', 'INV-MINE')
            ->has('statement.rows', 1)
            ->where('backUrl', null));
    }

    public function test_admin_views_any_client_and_non_clients_are_404(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = $this->client();
        $this->invoice($client, 'INV-ADM', 250, InvoiceStatus::Due, now()->toDateTimeString());

        $this->actingAs($admin)->get("/admin/clients/{$client->id}/statement")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('statement')
                ->where('statement.closing', 25000)
                ->where('pdfUrl', "/admin/clients/{$client->id}/statement/pdf")
                ->where('backUrl', "/admin/clients/{$client->id}"));

        $this->actingAs($admin)->get("/admin/clients/{$admin->id}/statement")->assertNotFound();
    }

    public function test_non_admins_cannot_open_another_clients_statement(): void
    {
        $client = $this->client();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->assertPageRefused($this->actingAs($client)->get("/admin/clients/{$client->id}/statement"));
        $this->assertPageRefused($this->actingAs($employee)->get("/admin/clients/{$client->id}/statement/pdf"));
    }

    public function test_inverted_period_is_rejected(): void
    {
        $client = $this->client();

        $this->actingAs($client)->get('/statement?from=2026-05-01&to=2026-04-01')->assertSessionHasErrors('to');
    }

    public function test_pdf_is_rendered_for_client_and_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = $this->client();
        $this->invoice($client, 'INV-PDF', 1150, InvoiceStatus::Due, now()->toDateTimeString());

        foreach ([[$client, '/statement/pdf'], [$admin, "/admin/clients/{$client->id}/statement/pdf"]] as [$user, $url]) {
            $pdf = $this->actingAs($user)->get($url);
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $html = ClientStatementDocument::html($client, ClientStatement::build($client, now()->startOfYear(), now()));
        $this->assertStringContainsString('INV-PDF', $html);
        $this->assertStringContainsString('1,150.00 ر.س عليه', $html);
        $this->assertStringContainsString('class="cf-tbl"', $html);
    }
}
