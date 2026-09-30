<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Payments\MoyasarGateway;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **تدقيق الدفع سطراً سطراً** (2026-09-30): كلّ اختبارٍ هنا فشل على الكود قبل إصلاحه — عيوبٌ ثبتت
 * لا افتراضات. الحرف في اسم الاختبار هو رقم العيب في تقرير التدقيق.
 */
class PaymentAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function payableCase(User $client, int $amount = 10350, string $suffix = '1'): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-AUD-'.$suffix, 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);
        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-AUD-'.$suffix, 'description' => 'أتعاب',
            'amount' => $amount, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);

        return $case->fresh();
    }

    /**
     * ميسّر وهميّة تُنشئ فاتورةً بكلّ POST وتردّ كلَّ فاتورةٍ أنشأتها مفتوحةً بمبلغها.
     *
     * @param  array<string, int>  $created  معرّف فاتورة البوّابة ← مبلغها بالهللة
     */
    private function fakeHostedInvoices(array &$created): void
    {
        Http::fake(function ($request) use (&$created) {
            if ($request->method() === 'POST') {
                $id = 'inv_'.(count($created) + 1);
                $created[$id] = (int) $request['amount'];

                return Http::response(['id' => $id, 'url' => "https://moyasar.test/{$id}"], 201);
            }

            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['id' => $id, 'url' => "https://moyasar.test/{$id}", 'status' => 'initiated', 'amount' => $created[$id] ?? 0]);
        });
    }

    /** A — كان يُعاد رابط السداد الكامل (10,350) للدفعة الأولى (3,450)، فيُخصم كاملاً ولا يُسوّى. */
    public function test_a_switching_to_installments_opens_a_link_for_the_installment_amount(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        $created = [];
        $this->fakeHostedInvoices($created);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'full'])->assertRedirect('https://moyasar.test/inv_1');
        $this->actingAs($client)->post(route('cases.pay', $case->fresh()), ['plan' => 'install'])->assertRedirect('https://moyasar.test/inv_2');

        $first = Invoice::where('case_id', $case->id)->where('installment_no', 1)->firstOrFail();
        $this->assertSame(['inv_1' => 1035000, 'inv_2' => 345000], $created);
        $this->assertSame('inv_2', $first->gateway_ref);
    }

    /** A — على مستوى البوّابة: فاتورةٌ تغيّر مبلغها لا تُعاد إلى رابطها القديم، والمطابقة تُعاد إليه. */
    public function test_a_an_open_gateway_invoice_is_reused_only_at_the_same_amount(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->payableCase($client)->invoices()->firstOrFail();
        $created = [];
        $this->fakeHostedInvoices($created);
        $gateway = app(MoyasarGateway::class);

        $this->assertSame('https://moyasar.test/inv_1', $gateway->hostedUrlForInvoice($invoice, 'https://app.test/cb'));
        $this->assertSame('https://moyasar.test/inv_1', $gateway->hostedUrlForInvoice($invoice->fresh(), 'https://app.test/cb'));

        $invoice->fresh()->update(['amount' => 5000]);
        $this->assertSame('https://moyasar.test/inv_2', $gateway->hostedUrlForInvoice($invoice->fresh(), 'https://app.test/cb'));
        $this->assertSame(500000, $created['inv_2']);
    }

    /** عدد تنبيهات الاسترداد التي وصلت المدير. */
    private function refundAlerts(User $admin): int
    {
        return UserNotification::where('user_id', $admin->id)->where('body', 'like', '%استرداد%')->count();
    }

    /** E — دفع العميل لدى البوّابة ولم يصل الإشعار، فضغط الدفع ثانيةً: كانت تُنشأ فاتورة بوّابةٍ ثانية (خصمٌ مزدوج). */
    public function test_e_paying_again_before_the_webhook_settles_the_first_payment_instead_of_charging_twice(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        Invoice::where('case_id', $case->id)->update(['gateway' => 'moyasar', 'gateway_ref' => 'inv_paid']);
        Http::fake([
            'api.moyasar.com/v1/invoices/inv_paid' => Http::response([
                'id' => 'inv_paid', 'url' => 'https://moyasar.test/inv_paid', 'status' => 'paid', 'amount' => 1035000,
                'payments' => [['id' => 'pay_failed', 'status' => 'failed'], ['id' => 'pay_ok', 'status' => 'paid']],
            ]),
            'api.moyasar.com/v1/payments/pay_ok' => Http::response([
                'id' => 'pay_ok', 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR', 'invoice_id' => 'inv_paid',
            ]),
            'api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_second', 'url' => 'https://moyasar.test/inv_second'], 201),
        ]);

        $callback = route('cases.pay.callback', $case).'?id=pay_ok';
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'full'])->assertRedirect($callback);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');

        $this->actingAs($client)->get($callback)->assertSessionHas('success');
        $this->assertSame('paid', $case->fresh()->fee_status);
    }

    /** E — مبلغٌ غير مطابق على فاتورة قضيّة: كان سطر سجلٍّ فقط؛ الآن تنبيه استردادٍ واحد ولو وصل الإشعار والعودة معاً. */
    public function test_e_a_mismatched_amount_on_a_case_invoice_alerts_the_admins_once(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        Invoice::where('case_id', $case->id)->update(['gateway_ref' => 'inv_x']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_short', 'status' => 'paid', 'amount' => 100, 'currency' => 'SAR', 'invoice_id' => 'inv_x',
        ])]);

        $this->postJson(route('webhooks.moyasar'), ['secret_token' => 'whsec', 'data' => ['id' => 'pay_short']])->assertOk();
        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id=pay_short')->assertSessionHas('error');

        $this->assertSame(1, $this->refundAlerts($admin));
        $this->assertNotNull(Payment::where('gateway_payment_id', 'pay_short')->value('refund_required_at'));
        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }

    /** E — دفعةٌ على فاتورة قضيّةٍ ملغاة، ودفعةٌ ثانية على مسدَّدة: مالٌ لم يُطبَّق ⇒ تنبيه استرداد لكلٍّ منهما. */
    public function test_e_money_on_a_cancelled_or_already_paid_case_invoice_is_flagged_for_refund(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        $invoice = $case->invoices()->firstOrFail();
        $pay = fn (string $id) => MoyasarGateway::toGatewayPayment([
            'id' => $id, 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR', 'metadata' => ['invoice_number' => $invoice->number],
        ]);

        $this->assertTrue(PaymentReconciler::settle($pay('pay_1'), 'webhook'));
        $this->assertTrue(PaymentReconciler::settle($pay('pay_1'), 'callback'));
        $this->assertSame(0, $this->refundAlerts($admin), 'الدفعة نفسها مرّتين نجاحٌ مكرّر');

        PaymentReconciler::settle($pay('pay_2'), 'webhook');
        $this->assertSame(1, $this->refundAlerts($admin));
        $this->assertSame('pay_1', $invoice->fresh()->gateway_payment_id);

        $other = $this->payableCase(User::factory()->create(['role' => Role::Client]), suffix: '2')->invoices()->firstOrFail();
        $other->update(['number' => 'INV-AUD-CXL', 'status' => InvoiceStatus::Cancelled->value]);
        $this->assertFalse(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_3', 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR', 'metadata' => ['invoice_number' => 'INV-AUD-CXL'],
        ]), 'webhook'));
        $this->assertSame(2, $this->refundAlerts($admin));
    }
}
