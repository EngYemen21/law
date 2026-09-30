<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Support\CaseFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **مطابقة توثيق ميسّر** (2026-09-30) — بالصيغ الموثّقة: معرّفات UUID، و`callback_url` الفاتورة إشعارٌ خادميّ
 * بكائن الفاتورة (لا تحويلُ متصفّح)، وإشعار اللوحة `{type, secret_token, data: payment}`.
 */
class MoyasarDocsConformanceTest extends TestCase
{
    use RefreshDatabase;

    private const INVOICE_ID = '8e15b386-e0a9-420f-8167-e363818f6b35';

    private const PAYMENT_ID = '79cced57-9deb-4c4b-8f48-59c124f79688';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_docs']);
    }

    /** @return array{0: LegalCase, 1: Invoice, 2: User} */
    private function caseWithGatewayInvoice(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-DOC-1', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-DOC-1', 'description' => 'أتعاب',
            'amount' => 10350, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
            'gateway' => 'moyasar', 'gateway_ref' => self::INVOICE_ID,
        ]);

        return [$case, $invoice, $client];
    }

    /** كائن الفاتورة كما تردّه ميسّر. @param  list<array<string, mixed>>  $payments */
    private function gatewayInvoice(string $status, array $payments): array
    {
        return [
            'id' => self::INVOICE_ID, 'status' => $status, 'amount' => 1035000, 'currency' => 'SAR',
            'description' => 'أتعاب', 'url' => 'https://checkout.moyasar.com/invoices/'.self::INVOICE_ID,
            'callback_url' => route('webhooks.payment.invoice', 'moyasar'), 'payments' => $payments,
        ];
    }

    private function paidPayment(): array
    {
        return [
            'id' => self::PAYMENT_ID, 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR',
            'invoice_id' => self::INVOICE_ID, 'metadata' => ['invoice_number' => 'INV-DOC-1'],
        ];
    }

    /** `callback_url` لإشعار الخادم، و`success_url`/`back_url` لعودة العميل — كانت الثلاثة رابطَ العودة نفسه. */
    public function test_a_hosted_invoice_is_created_with_the_documented_urls(): void
    {
        [$case, , $client] = $this->caseWithGatewayInvoice();
        Invoice::where('case_id', $case->id)->update(['gateway' => null, 'gateway_ref' => null]);
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => self::INVOICE_ID, 'url' => 'https://checkout.moyasar.com/x'], 201)]);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'full'])->assertRedirect('https://checkout.moyasar.com/x');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['callback_url'] === route('webhooks.payment.invoice', 'moyasar')
            && str_contains($r['success_url'], "/cases/{$case->number}/pay/callback")
            && $r['back_url'] === $r['success_url']
            && $r['amount'] === 1035000 && $r['currency'] === 'SAR'
            && $r['metadata']['invoice_number'] === 'INV-DOC-1');
    }

    /** إشعار الفاتورة المدفوعة يسوّي — بلا دخولٍ ولا CSRF — ومرّةً واحدة وإن تكرّر. */
    public function test_the_invoice_paid_notification_settles_once_from_refetched_data(): void
    {
        [$case, $invoice] = $this->caseWithGatewayInvoice();
        Http::fake([
            'api.moyasar.com/v1/invoices/'.self::INVOICE_ID => Http::response($this->gatewayInvoice('paid', [$this->paidPayment()])),
            'api.moyasar.com/v1/payments/'.self::PAYMENT_ID => Http::response($this->paidPayment()),
        ]);
        $body = $this->gatewayInvoice('paid', [$this->paidPayment()]);

        $this->postJson(route('webhooks.payment.invoice', 'moyasar'), $body)->assertOk();
        $this->postJson(route('webhooks.payment.invoice', 'moyasar'), $body)->assertOk();

        $this->assertTrue((bool) $invoice->fresh()->paid);
        $this->assertSame('paid', $case->fresh()->fee_status);
        $ledger = Payment::where('gateway_payment_id', self::PAYMENT_ID)->sole();
        $this->assertSame('invoice_notice', $ledger->source_channel);
        $this->assertNotNull($ledger->receipt_no);
    }

    /** إشعارٌ مزوَّر يدّعي السداد وميسّر ترى الفاتورة غير مدفوعة: لا تسوية. */
    public function test_a_forged_invoice_notification_settles_nothing(): void
    {
        [$case, $invoice] = $this->caseWithGatewayInvoice();
        Http::fake(['api.moyasar.com/v1/invoices/*' => Http::response($this->gatewayInvoice('initiated', []))]);

        $this->postJson(route('webhooks.payment.invoice', 'moyasar'), $this->gatewayInvoice('paid', [$this->paidPayment()]))->assertOk();

        $this->assertFalse((bool) $invoice->fresh()->paid);
        $this->assertSame('pending_payment', $case->fresh()->fee_status);
        $this->assertSame(0, Payment::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/payments/'));
    }

    /** فاتورةٌ لا تعرفها ميسّر: ردٌّ بلا أثر. */
    public function test_an_unknown_invoice_notification_has_no_effect(): void
    {
        Http::fake(['api.moyasar.com/v1/invoices/*' => Http::response(['type' => 'record_not_found', 'message' => 'not found'], 404)]);

        $this->postJson(route('webhooks.payment.invoice', 'moyasar'), ['id' => 'b1f2c3d4-0000-4000-8000-000000000000'])->assertOk();

        $this->assertSame(0, Payment::count());
    }

    /** إشعار اللوحة بصيغته الموثّقة: `data` كائن الدفعة بمعرّف UUID. */
    public function test_the_dashboard_webhook_in_its_documented_shape_settles(): void
    {
        [, $invoice] = $this->caseWithGatewayInvoice();
        Http::fake(['api.moyasar.com/v1/payments/'.self::PAYMENT_ID => Http::response($this->paidPayment())]);

        $this->postJson(route('webhooks.moyasar'), [
            'id' => '2b8e3a2a-7c1d-4f5e-9a1b-3c4d5e6f7a8b', 'type' => 'payment_paid', 'created_at' => '2026-09-30T10:00:00.000Z',
            'secret_token' => 'whsec_docs', 'account_name' => 'مكتب', 'live' => false, 'data' => $this->paidPayment(),
        ])->assertOk();

        $this->assertTrue((bool) $invoice->fresh()->paid);
    }

    /** البند ٢ — السرّ من الجسم أو الترويسة وحدهما؛ كان `?secret_token=` في العنوان يُقبل. */
    public function test_the_webhook_secret_is_refused_from_the_query_string(): void
    {
        [, $invoice] = $this->caseWithGatewayInvoice();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response($this->paidPayment())]);

        $this->postJson(route('webhooks.moyasar').'?secret_token=whsec_docs', ['type' => 'payment_paid', 'data' => $this->paidPayment()])
            ->assertForbidden();
        $this->assertFalse((bool) $invoice->fresh()->paid);

        $this->postJson(route('webhooks.moyasar'), ['type' => 'payment_paid', 'data' => $this->paidPayment()], ['X-Moyasar-Secret-Token' => 'whsec_docs'])
            ->assertOk();
        $this->assertTrue((bool) $invoice->fresh()->paid);
    }

    /** البند ٣ — العودة تحمل معرّف **الفاتورة** لا الدفعة: تُجلب الفاتورة ودفعتها المدفوعة، ويُقال للعميل الصحيح. */
    public function test_a_return_carrying_the_gateway_invoice_id_is_confirmed(): void
    {
        [$case, , $client] = $this->caseWithGatewayInvoice();
        Http::fake([
            'api.moyasar.com/v1/invoices/'.self::INVOICE_ID => Http::response($this->gatewayInvoice('paid', [$this->paidPayment()])),
            'api.moyasar.com/v1/payments/'.self::PAYMENT_ID => Http::response($this->paidPayment()),
        ]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id='.self::INVOICE_ID.'&status=paid&message=Succeeded')
            ->assertSessionHas('success', 'تم تأكيد سداد الأتعاب وتفعيل القضية.');
        $this->assertSame('paid', $case->fresh()->fee_status);
    }

    /** البند ٤ — بطاقةٌ مرفوضة: «لم يُخصم أيّ مبلغ» لا «إن كان قد خُصم…»، والمحاولة في الدفتر بلا سند. */
    public function test_a_declined_card_tells_the_client_nothing_was_charged(): void
    {
        [$case, , $client] = $this->caseWithGatewayInvoice();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(['status' => 'failed'] + $this->paidPayment())]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id='.self::PAYMENT_ID.'&status=failed')
            ->assertSessionHas('error', 'رُفضت عملية الدفع ولم يُخصم أيّ مبلغ — يمكنك المحاولة مجدداً.');

        $ledger = Payment::where('gateway_payment_id', self::PAYMENT_ID)->sole();
        $this->assertSame('failed', $ledger->status);
        $this->assertNull($ledger->receipt_no);
    }

    /** البند ٥ — الدفعة الأولى من خطّة: الرسالة تسمّيها، لا «تم تأكيد سداد الأتعاب». */
    public function test_the_first_installment_return_names_the_installment(): void
    {
        [$case, $invoice, $client] = $this->caseWithGatewayInvoice();
        $this->assertNotNull(CaseFee::openInstallmentPlan($case));
        $first = $invoice->fresh();
        $first->update(['gateway' => 'moyasar', 'gateway_ref' => self::INVOICE_ID]);
        $payment = ['amount' => (int) $first->amount * 100, 'metadata' => ['invoice_number' => $first->number]] + $this->paidPayment();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response($payment)]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id='.self::PAYMENT_ID)
            ->assertSessionHas('success', 'تم تأكيد سداد الدفعة 1 من 3 وتفعيل القضية.');
    }

    /** البند ٦ — الإشعار سبق العودة: الدفتر يبقى على قناته الأولى. */
    public function test_the_ledger_keeps_the_first_channel_that_reported_the_payment(): void
    {
        [$case, , $client] = $this->caseWithGatewayInvoice();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response($this->paidPayment())]);

        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec_docs', 'type' => 'payment_paid', 'data' => $this->paidPayment()])->assertOk();
        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id='.self::PAYMENT_ID)->assertSessionHas('success');

        $this->assertSame('webhook', Payment::where('gateway_payment_id', self::PAYMENT_ID)->sole()->source_channel);
    }

    /** وإن رُفضت البطاقة والعودة تحمل معرّف الفاتورة: تُقرأ آخر محاولةٍ منها فتُسجَّل ويُقال للعميل إنّه لم يُخصم شيء. */
    public function test_a_declined_card_returning_with_the_invoice_id_is_reported_as_declined(): void
    {
        [$case, , $client] = $this->caseWithGatewayInvoice();
        $failed = ['status' => 'failed'] + $this->paidPayment();
        Http::fake([
            'api.moyasar.com/v1/invoices/'.self::INVOICE_ID => Http::response($this->gatewayInvoice('initiated', [$failed])),
            'api.moyasar.com/v1/payments/'.self::PAYMENT_ID => Http::response($failed),
        ]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id='.self::INVOICE_ID.'&status=failed')
            ->assertSessionHas('error', 'رُفضت عملية الدفع ولم يُخصم أيّ مبلغ — يمكنك المحاولة مجدداً.');
        $this->assertSame('failed', Payment::where('gateway_payment_id', self::PAYMENT_ID)->sole()->status);
        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }
}
