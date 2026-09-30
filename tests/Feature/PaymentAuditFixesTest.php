<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transitions\Invoice\CancelInvoice;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Payments\MoyasarGateway;
use App\Support\CaseFee;
use App\Support\ExecFee;
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

    /** قضيّةٌ بخطّة تقسيطٍ مفتوحة. @return list<Invoice> دفعاتها بالترتيب */
    private function casePlan(LegalCase $case): array
    {
        $this->assertNotNull(CaseFee::openInstallmentPlan($case));

        return Invoice::where('case_id', $case->id)->orderBy('installment_no')->get()->all();
    }

    /** ملفّ تنفيذٍ بعرضٍ ثابت مقبول ثمّ خطّة تقسيط. @return list<Invoice> */
    private function execPlan(int $fee = 6001, int $vat = 900): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-AUD-'.uniqid(), 'subject' => 'تنفيذ سند لأمر',
            'status' => 'عرض الخدمة', 'tone' => 'b-amber', 'stage' => 5, 'amount' => 120000,
            'fee' => $fee, 'vat' => $vat, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ]);
        ExecFee::openOnAcceptance($exec);
        $this->assertNotNull(ExecFee::openInstallmentPlan($exec->fresh()));

        return Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get()->all();
    }

    private function cancel(Invoice $invoice): void
    {
        Workflow::run(new CancelInvoice, $invoice->fresh(), User::factory()->create(['role' => Role::Admin]), ['reason' => 'تنازل']);
    }

    /** B — قسطٌ أُلغي **قبل** سداد الباقي: كانت القضيّة تبقى «دفعة 2 من 3» أبداً. */
    public function test_b_a_case_plan_completes_when_its_cancelled_installment_is_skipped(): void
    {
        $case = $this->payableCase(User::factory()->create(['role' => Role::Client]));
        [$first, $second, $third] = $this->casePlan($case);

        $this->cancel($third);
        PaymentReconciler::settleManual($first->fresh(), 'الإدارة');
        PaymentReconciler::settleManual($second->fresh(), 'الإدارة');

        $case->refresh();
        $this->assertSame(['paid', 2, 2], [$case->fee_status, (int) $case->installments_paid, (int) $case->installments_total]);
    }

    /** B — قسطٌ أُلغي **بعد** سداد الباقي: لا دفعةَ بعده تُعيد العدّ، فالإلغاء نفسه يُكملها. */
    public function test_b_cancelling_the_last_outstanding_installment_completes_the_case_and_execution_plans(): void
    {
        $case = $this->payableCase(User::factory()->create(['role' => Role::Client]));
        [$first, $second, $third] = $this->casePlan($case);
        PaymentReconciler::settleManual($first->fresh(), 'الإدارة');
        PaymentReconciler::settleManual($second->fresh(), 'الإدارة');
        $this->assertSame('installments', $case->fresh()->fee_status);

        $this->cancel($third);
        $this->assertSame('paid', $case->fresh()->fee_status);

        [$e1, $e2, $e3] = $this->execPlan();
        PaymentReconciler::settleManual($e1->fresh(), 'الإدارة');
        PaymentReconciler::settleManual($e2->fresh(), 'الإدارة');
        $this->cancel($e3);
        $exec = Execution::findOrFail($e1->exec_id);
        $this->assertSame([2, 2], [(int) $exec->installments_paid, (int) $exec->installments_total]);
        $this->assertTrue($exec->feeFullySettled());
    }

    /** H — مبلغٌ أقلّ من عدد الأقساط كان يُصدر أقساطاً بصفر ريال لا تقبلها البوّابة. */
    public function test_h_a_fee_smaller_than_the_installment_count_opens_no_plan(): void
    {
        $case = $this->payableCase(User::factory()->create(['role' => Role::Client]), amount: 2);

        $this->assertNull(CaseFee::openInstallmentPlan($case));
        $this->assertSame(1, Invoice::where('case_id', $case->id)->count());
        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }

    /** D — الأقساط تقسم أساسَ الفاتورة الأمّ وضريبتَها المجمَّدين: لا ريالَ يضيع، ولا نسبةَ اليوم تحلّ محلّ نسبتها. */
    public function test_d_installments_split_the_frozen_base_and_vat_of_the_issued_invoice(): void
    {
        $case = $this->payableCase(User::factory()->create(['role' => Role::Client]), amount: 5003);
        Invoice::where('case_id', $case->id)->update(['subtotal' => 4350, 'vat_rate' => 15, 'vat_amount' => 653]);
        Setting::put('vat_rate', 5); // النسبة تغيّرت بعد إصدار الفاتورة

        $plan = collect($this->casePlan($case));

        $this->assertSame(5003, (int) $plan->sum('amount'));
        $this->assertSame(4350, (int) $plan->sum('subtotal'));
        $this->assertSame(653, (int) $plan->sum('vat_amount'));
        $this->assertSame([15, 15, 15], $plan->pluck('vat_rate')->map(fn ($r) => (int) $r)->all());
        $plan->each(fn (Invoice $i) => $this->assertSame((int) $i->amount, (int) $i->subtotal + (int) $i->vat_amount));
    }

    /** G — دفعتان تُسوَّيان معاً فيرى كلا العدّين «المدفوع 2»: كانت القضيّة لا تُفعَّل أبداً. */
    public function test_g_two_installments_settled_together_still_activate_the_case(): void
    {
        $case = $this->payableCase(User::factory()->create(['role' => Role::Client]));
        [$first, $second] = $this->casePlan($case);

        // الفاتورتان تُسوَّيان قبل أن يعدّ أيٌّ من الطلبين الخطّة — ترتيبُ السباق نفسه
        Workflow::run(new SettleInvoice, $first->fresh(), null, ['channel' => 'ميسّر']);
        Workflow::run(new SettleInvoice, $second->fresh(), null, ['channel' => 'ميسّر']);
        CaseFee::markInstallmentPaid($case->fresh());
        CaseFee::markInstallmentPaid($case->fresh());

        $case->refresh();
        $this->assertSame(2, (int) $case->installments_paid);
        $this->assertNotSame('none', $case->pleading_status, 'القضيّة لم تُفعَّل');
    }
}
