<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use App\Support\Finance\ArabicAmount;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\ReceiptVoucherDocument;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **سند القبض** (المرحلة أ، طلب المالك 2026-09-29): لكلّ دفعةٍ ناجحة سندٌ برقمٍ متسلسل لكلّ سنة،
 * وطريقة القبض ومن قبض وتاريخه، والمبلغ رقماً وكتابةً — والمحاولة الفاشلة لا سند لها ولا تُعدّ دخلاً.
 */
class ReceiptVoucherTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'name' => 'مدير المالية']);
    }

    private function dueInvoice(User $client, string $number, int $amount = 1150): Invoice
    {
        return Invoice::create([
            'user_id' => $client->id, 'number' => $number, 'description' => 'أتعاب قضيّة تجاريّة',
            'amount' => $amount, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);
    }

    public function test_amount_in_words_follows_arabic_number_rules(): void
    {
        $cases = [
            1 => 'واحد', 2 => 'اثنان', 11 => 'أحد عشر', 12 => 'اثنا عشر', 21 => 'واحد وعشرون',
            100 => 'مائة', 200 => 'مائتان', 300 => 'ثلاثمائة', 1000 => 'ألف', 2000 => 'ألفان',
            3000 => 'ثلاثة آلاف', 11000 => 'أحد عشر ألفاً', 11500 => 'أحد عشر ألفاً وخمسمائة',
            1150 => 'ألف ومائة وخمسون', 101000 => 'مائة ألف وألف', 200000 => 'مائتا ألف',
            250000 => 'مائتان وخمسون ألفاً', 2500000 => 'مليونان وخمسمائة ألف', 3000000 => 'ثلاثة ملايين',
        ];
        foreach ($cases as $n => $words) {
            $this->assertSame($words, ArabicAmount::words($n), "العدد {$n}");
        }

        $this->assertSame('فقط ألف ومائة وخمسون ريال سعودي لا غير', ArabicAmount::riyals(115000));
        $this->assertSame('فقط سبعة عشر ريال سعودي وخمسة وعشرون هللة لا غير', ArabicAmount::riyals(1725));
    }

    public function test_manual_collection_issues_sequential_vouchers_with_method_and_receiver(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $first = $this->dueInvoice($client, 'INV-RV-1');
        $second = $this->dueInvoice($client, 'INV-RV-2', 800);

        $this->actingAs($admin)->post(route('admin.invoices.pay', $first), ['method' => 'cash'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.invoices.pay', $second), ['method' => 'bank_transfer'])->assertRedirect();

        $year = now()->format('Y');
        $p1 = Payment::where('invoice_id', $first->id)->sole();
        $p2 = Payment::where('invoice_id', $second->id)->sole();

        $this->assertSame("RV-{$year}-00001", $p1->receipt_no);
        $this->assertSame("RV-{$year}-00002", $p2->receipt_no, 'متسلسل لا عشوائيّ');
        $this->assertSame('cash', $p1->method);
        $this->assertSame('bank_transfer', $p2->method);
        $this->assertSame($admin->id, $p1->actor_id);
        $this->assertNotNull($p1->received_at);
        $this->assertSame('نقداً', $p1->methodLabel());
        $this->assertSame('مدير المالية', $p1->receiverLabel());
    }

    public function test_an_unknown_method_is_refused_and_nothing_is_collected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->dueInvoice($client, 'INV-RV-3');

        $this->actingAs($this->admin())->post(route('admin.invoices.pay', $invoice), ['method' => 'gateway'])
            ->assertSessionHasErrors('method');

        $this->assertSame(0, Payment::count());
        $this->assertFalse($invoice->fresh()->paid);
    }

    public function test_a_refused_collection_consumes_no_voucher_number(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $cancelled = $this->dueInvoice($client, 'INV-RV-4');
        $cancelled->update(['status' => 'ملغاة']);
        $ok = $this->dueInvoice($client, 'INV-RV-5');

        $this->assertFalse(PaymentReconciler::settleManual($cancelled, 'الإدارة'));
        $this->assertTrue(PaymentReconciler::settleManual($ok, 'الإدارة', null, 'cash'));

        $this->assertSame('RV-'.now()->format('Y').'-00001', Payment::sole()->receipt_no, 'لا فجوة في التسلسل');
    }

    public function test_gateway_payment_gets_one_voucher_and_a_failed_attempt_gets_none(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RV-1', 'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'مستشار',
            'status' => 'بانتظار السداد', 'price' => 450, 'vat' => 68, 'total' => 518,
        ]);
        $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-RV-GW', 'description' => 'استشارة CN-RV-1', 'amount' => 518,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false, 'gateway_ref' => 'inv_rv1',
        ]);
        $meta = ['invoice_number' => 'INV-RV-GW', 'consult_id' => (string) $consult->id];

        // محاولةٌ فاشلة أوّلاً: في الدفتر للتدقيق، بلا سند، ولا تُعدّ مقبوضاً
        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment(['id' => 'pay_rv_fail', 'status' => 'failed', 'amount' => 51800, 'currency' => 'SAR', 'metadata' => $meta]), 'callback');
        $failed = Payment::where('gateway_payment_id', 'pay_rv_fail')->sole();
        $this->assertNull($failed->receipt_no);

        // ثمّ الناجحة، ويصل إشعارها وعودتها معاً
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(['id' => 'pay_rv_ok', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR', 'metadata' => $meta], 200)]);
        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec_1', 'data' => ['id' => 'pay_rv_ok']])->assertOk();
        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment(['id' => 'pay_rv_ok', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR', 'metadata' => $meta]), 'callback');

        $paid = Payment::where('gateway_payment_id', 'pay_rv_ok')->sole();
        $this->assertSame('RV-'.now()->format('Y').'-00001', $paid->receipt_no, 'سندٌ واحد مهما تكرّر الوصول');
        $this->assertSame('gateway', $paid->method);
        $this->assertSame('بوّابة الدفع', $paid->receiverLabel());

        // تبويب المقبوضات ومجموعه: الناجحة وحدها (كانت الفاشلة تُضاف إلى الدخل)
        $period = ['from' => now()->subDay(), 'to' => now()->addDay()];
        $this->assertSame(1, FinanceBoard::receipts($period)->total());
        $this->assertSame(518.0, FinanceBoard::receiptsTotal($period));
    }

    public function test_the_voucher_prints_amount_in_words_and_its_number(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'نورة القحطاني']);
        $invoice = $this->dueInvoice($client, 'INV-RV-6');
        PaymentReconciler::settleManual($invoice, 'الإدارة', $this->admin(), 'bank_transfer');

        $html = ReceiptVoucherDocument::html(Payment::sole());

        foreach (['سند قبض', 'RV-'.now()->format('Y').'-00001', 'نورة القحطاني', '1,150.00 ر.س', 'فقط ألف ومائة وخمسون ريال سعودي لا غير', 'INV-RV-6', 'تحويل بنكيّ', 'مدير المالية'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_voucher_downloads_for_admin_and_owner_only(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $paid = $this->dueInvoice($client, 'INV-RV-7');
        $due = $this->dueInvoice($client, 'INV-RV-8');
        PaymentReconciler::settleManual($paid, 'الإدارة', $admin, 'cash');
        $payment = Payment::sole();

        $pdf = $this->actingAs($admin)->get(route('admin.receipts.pdf', $payment));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString($payment->receipt_no.'.pdf', (string) $pdf->headers->get('Content-Disposition'));

        $this->actingAs($client)->get(route('invoices.receipt', $paid))->assertOk();
        $this->assertPageRefused($this->actingAs($other)->get(route('invoices.receipt', $paid)));
        $this->actingAs($client)->get(route('invoices.receipt', $due))->assertNotFound();

        // وصفحة فواتير العميل تعرض الزرّ لما له سندٌ فعلاً
        $this->actingAs($client)->get(route('invoices'))->assertInertia(fn ($p) => $p
            ->where('invoices.1.no', 'INV-RV-7')->where('invoices.1.hasReceipt', true)
            ->where('invoices.0.no', 'INV-RV-8')->where('invoices.0.hasReceipt', false));
    }
}
