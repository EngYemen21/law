<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FakePaymentGateway;
use Tests\TestCase;

/**
 * **عقد بوّابة الدفع** (مراجعة بوّابات الدفع 2026-09-30): ميسّر أوّل تطبيقٍ له، والتسوية والمتحكّمات لا
 * تعرف إلّا الشكل الموحّد — فتُضاف بوّابةٌ ثانية بصنفٍ وسطر إعداد، ويثبت ذلك `FakePaymentGateway`.
 */
class PaymentGatewayContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePaymentGateway::$payments = [];
    }

    private function useFakeGateway(): void
    {
        config([
            'services.payments.gateways.'.FakePaymentGateway::NAME => FakePaymentGateway::class,
            'services.payments.default' => FakePaymentGateway::NAME,
        ]);
    }

    private function invoice(User $client, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $client->id, 'number' => 'INV-GW-1', 'description' => 'أتعاب', 'amount' => 750,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ], $attributes));
    }

    private function payableCase(User $client): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-GW-1', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);
        $this->invoice($client, ['case_id' => $case->id, 'number' => 'INV-CASE-GW', 'amount' => 10350]);

        return $case;
    }

    public function test_moyasar_is_the_registered_default_and_maps_its_raw_payment(): void
    {
        $gateway = app(PaymentGateways::class)->default();
        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertSame(MoyasarGateway::NAME, $gateway->name());

        $paid = MoyasarGateway::toGatewayPayment([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 75000, 'currency' => 'SAR', 'invoice_id' => 'inv_1',
            'metadata' => ['invoice_number' => 'INV-1', 'consult_id' => '7'],
        ]);
        $this->assertTrue($paid->isPaid);
        $this->assertSame([75000, 'SAR', 'inv_1', 'INV-1', 7], [$paid->amountHalalas, $paid->currency, $paid->gatewayInvoiceId, $paid->invoiceNumber(), $paid->consultId()]);

        $failed = MoyasarGateway::toGatewayPayment(['id' => 'pay_2', 'status' => 'failed', 'consult_id' => '']);
        $this->assertFalse($failed->isPaid);
        $this->assertNull($failed->gatewayInvoiceId);
        $this->assertNull($failed->invoiceNumber());
    }

    public function test_a_second_gateway_checks_out_and_settles_through_the_callback_without_domain_changes(): void
    {
        $this->useFakeGateway();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client);

        $this->actingAs($client)->post(route('invoices.checkout', $invoice))
            ->assertRedirect('https://fakepay.test/checkout/fk_'.$invoice->id);
        $this->assertSame(FakePaymentGateway::NAME, $invoice->fresh()->gateway);

        FakePaymentGateway::pay($invoice->fresh(), 'fk_pay_1');
        $this->actingAs($client)->get(route('invoices.checkout.callback', $invoice).'?payment=fk_pay_1')
            ->assertRedirect(route('invoices'))->assertSessionHas('success');

        $this->assertTrue((bool) $invoice->fresh()->paid);
        $ledger = Payment::where('gateway_payment_id', 'fk_pay_1')->firstOrFail();
        $this->assertSame(FakePaymentGateway::NAME, $ledger->gateway);
        $this->assertNotNull($ledger->reconciled_at);
        $this->assertSame('بوّابة الدفع (بوّابة وهميّة)', $ledger->methodLabel());
    }

    public function test_a_second_gateway_settles_through_its_own_webhook_route(): void
    {
        $this->useFakeGateway();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client, ['gateway' => FakePaymentGateway::NAME, 'gateway_ref' => 'fk_x']);
        FakePaymentGateway::pay($invoice, 'fk_pay_2');
        $url = route('webhooks.payment', FakePaymentGateway::NAME);

        $this->postJson($url, ['payment_id' => 'fk_pay_2'])->assertForbidden();
        $this->assertFalse((bool) $invoice->fresh()->paid);

        $this->postJson($url, ['payment_id' => 'fk_pay_2'], ['X-Fake-Signature' => 'fk_secret'])->assertOk();
        $this->assertTrue((bool) $invoice->fresh()->paid);
    }

    public function test_the_amount_guard_stays_on_the_platform_for_every_gateway(): void
    {
        $this->useFakeGateway();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client, ['gateway' => FakePaymentGateway::NAME, 'gateway_ref' => 'fk_y']);
        FakePaymentGateway::pay($invoice, 'fk_pay_short', halalas: 100);

        $this->actingAs($client)->get(route('invoices.checkout.callback', $invoice).'?payment=fk_pay_short')
            ->assertSessionHas('error');

        $this->assertFalse((bool) $invoice->fresh()->paid);
    }

    public function test_an_unregistered_gateway_has_no_webhook_receiver(): void
    {
        $this->postJson(route('webhooks.payment', 'nopay'), ['payment_id' => 'x'])->assertNotFound();
    }

    /** قرار المالك: قاعدة الانتماء الموحّدة — مرجع البوّابة، أو رقم الفاتورة لفاتورةٍ لم يُخزَّن مرجعها بعد. */
    public function test_case_callback_accepts_a_payment_matched_by_invoice_number_before_the_ref_is_stored(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_gw_c', 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR', 'invoice_id' => 'inv_unstored',
            'metadata' => ['invoice_number' => 'INV-CASE-GW'],
        ])]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id=pay_gw_c')
            ->assertRedirect(route('cases.show', $case))->assertSessionHas('success');

        $this->assertSame('paid', $case->fresh()->fee_status);
    }

    public function test_case_callback_still_refuses_a_payment_of_another_clients_invoice(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        $other = $this->invoice(User::factory()->create(['role' => Role::Client]), ['number' => 'INV-OTHER', 'gateway_ref' => 'inv_other']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_gw_o', 'status' => 'paid', 'amount' => 75000, 'currency' => 'SAR', 'invoice_id' => 'inv_other',
            'metadata' => ['invoice_number' => 'INV-OTHER'],
        ])]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id=pay_gw_o')->assertSessionHas('error');

        $this->assertFalse((bool) $other->fresh()->paid);
        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }

    public function test_moyasar_records_itself_as_the_gateway_of_the_invoice_it_opens(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->invoice($client);
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_m1', 'url' => 'https://moyasar.test/pay/inv_m1'], 201)]);

        $this->assertSame('https://moyasar.test/pay/inv_m1', app(MoyasarGateway::class)->hostedUrlForInvoice($invoice, 'https://app.test/cb'));
        $this->assertSame(['moyasar', 'inv_m1'], [$invoice->fresh()->gateway, $invoice->fresh()->gateway_ref]);
    }
}
