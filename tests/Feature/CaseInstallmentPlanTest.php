<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * خطّة التقسيط بعد تأمينها.
 *
 * كانت «ميزة مستقلّة لا تمرّ ببوّابة الدفع»: العميل ينقر «سداد على 3 دفعات» فتُفعَّل القضية
 * فوراً وتُكتب رسالة «تم استلام الدفعة الأولى» — بلا بوّابة ولا فاتورة ولا صفّ دفع. ونقرتان
 * لاحقتان تُصيّران فاتورة الأتعاب كاملةً «مدفوعة» فتدخل إجمالي المحصَّل. أي خدمة كاملة بصفر ريال.
 *
 * التصميم الآمن: الخطّة تُقسّم الأتعاب إلى فواتير حقيقية، وكل دفعة تمرّ بميسّر (أو تحصيل
 * إداريّ يدويّ يقيّد الدفتر)، والتفعيل لا يقع إلا بتسوية الدفعة الأولى فعلاً.
 */
class CaseInstallmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private function payableCase(User $client, int $fee = 9000): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-0900', 'type' => 'تجاري',
            'title' => 'قضية', 'court' => 'المحكمة',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => $fee, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-CASE-900',
            'description' => 'أتعاب القضية', 'amount' => $fee, 'status' => 'مستحقة',
            'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false,
        ]);

        return $case->fresh();
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
        Http::fake(['api.moyasar.com/*' => Http::response(['id' => 'inv_gw', 'url' => 'https://moyasar.test/pay'], 201)]);
    }

    /** 🔴 اختيار التقسيط لم يعد يفعّل القضية — التفعيل بالسداد لا بالنيّة. */
    public function test_choosing_installments_does_not_activate_the_case(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $case->refresh();
        $this->assertSame('installments', $case->fee_status);
        $this->assertSame(0, $case->installments_paid, 'احتُسبت دفعة لم تُدفع.');
        $this->assertSame('بانتظار سداد الأتعاب', $case->status, 'فُعّلت القضية بلا سداد.');
        $this->assertSame('none', $case->pleading_status);
    }

    /** 🔴 ولا يوجد بعد اليوم مسار تقسيط بلا بوّابة — كما هو حال السداد الكامل. */
    public function test_installments_require_a_configured_gateway(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);

        $this->actingAs($client)
            ->post(route('cases.pay', $case), ['plan' => 'install'])
            ->assertStatus(503);

        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }

    /** الخطّة تُنشئ فواتير حقيقية مجموعها يساوي الأتعاب بالضبط (لا كسر ضائع). */
    public function test_plan_splits_the_fee_into_real_invoices(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client, 10000);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $invoices = Invoice::where('case_id', $case->id)->orderBy('id')->get();
        $this->assertCount(3, $invoices);
        $this->assertSame(10000, $invoices->sum('amount'), 'مجموع الأقساط لا يساوي الأتعاب.');
        $this->assertTrue($invoices->every(fn (Invoice $i) => ! $i->paid));
    }

    /** تسوية الدفعة الأولى (عبر البوّابة) هي ما يفعّل القضية. */
    public function test_settling_the_first_installment_activates_the_case(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client, 9000);
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $first = Invoice::where('case_id', $case->id)->orderBy('id')->first();

        PaymentReconciler::settle([
            'id' => 'pay_i1', 'status' => 'paid', 'amount' => $first->amount * 100, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => $first->number],
        ], 'webhook');

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status, 'لم تُفعَّل القضية بعد سداد الدفعة الأولى.');
        $this->assertSame(1, $case->installments_paid);
        $this->assertSame('installments', $case->fee_status, 'اكتمل السداد بدفعة واحدة.');
    }

    /** واكتمال الدفعات الثلاث هو ما يُنهي الأتعاب. */
    public function test_settling_every_installment_completes_the_fee(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client, 9000);
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        foreach (Invoice::where('case_id', $case->id)->orderBy('id')->get() as $i => $invoice) {
            PaymentReconciler::settle([
                'id' => 'pay_x'.$i, 'status' => 'paid', 'amount' => $invoice->amount * 100, 'currency' => 'SAR',
                'metadata' => ['invoice_number' => $invoice->number],
            ], 'webhook');
        }

        $case->refresh();
        $this->assertSame('paid', $case->fee_status);
        $this->assertSame(3, $case->installments_paid);
        $this->assertSame(3, Payment::count(), 'الدفعات لم تُقيَّد في الدفتر.');
    }

    /** 🔴 زرّ «سداد الدفعة التالية» لم يعد يُقرّ استلاماً — يبدأ دفعة حقيقية. */
    public function test_next_installment_button_cannot_self_declare_payment(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $before = $case->fresh()->installments_paid;

        $this->actingAs($client)->post(route('cases.pay-installment', $case));

        $this->assertSame($before, $case->fresh()->installments_paid, 'زادت الدفعات بلا سداد.');
        $this->assertSame(0, Invoice::where('case_id', $case->id)->where('paid', true)->count());
    }

    /** والتحصيل الإداريّ اليدويّ (نقداً/تحويلاً) يمرّ بالدفتر ويقدّم الخطّة كذلك. */
    public function test_manual_collection_of_an_installment_advances_the_plan(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client, 9000);
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $first = Invoice::where('case_id', $case->id)->orderBy('id')->first();

        PaymentReconciler::settleManual($first, 'الإدارة');

        $case->refresh();
        $this->assertSame(1, $case->installments_paid);
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertSame(1, Payment::count(), 'التحصيل اليدويّ لم يُقيَّد في الدفتر.');
    }
}
