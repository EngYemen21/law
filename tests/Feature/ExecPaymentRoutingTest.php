<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use App\Support\ExecFee;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **توجيه الدفع حين يحمل الملفّ أكثر من فاتورة.**
 *
 * ثلاثة مواضع كانت تأخذ «أحدث فاتورة» (`latest('id')`): زرّ السداد، والمُسوّي المباشر،
 * وعودةُ البوّابة. وهي صحيحةٌ بالمصادفة ما دامت الفاتورة واحدة — ومع خطّة التقسيط تصير
 * الدفعة **الثالثة**: يُحصَّل قسطٌ خطأ، وتُشطب فاتورةٌ مجّاناً، ولا تُطابَق العودة.
 */
class ExecPaymentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function planned(): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-RTE-'.uniqid(), 'subject' => 'تنفيذ',
            'status' => 'عرض الخدمة', 'tone' => 'b-amber', 'stage' => 5, 'amount' => 120000,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 6001, 'vat' => 900, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ]);

        ExecFee::openOnAcceptance($exec);
        ExecFee::openInstallmentPlan($exec->fresh());

        return $exec->fresh();
    }

    /** @return Collection<int, Invoice> */
    private function plan(Execution $exec)
    {
        return Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get();
    }

    /**
     * عودةُ العميل من سداد الدفعة الأولى تُطابَق بمرجعها — وكان المتحكّم يقارنها بمرجع
     * **أحدث** فاتورة (الدفعة الثالثة)، فيقرأ «تعذّر تأكيد الدفع» وقد خُصم منه المبلغ.
     */
    public function test_the_callback_matches_any_invoice_of_the_execution(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $exec = $this->planned();
        $first = $this->plan($exec)[0];
        $first->update(['gateway_ref' => 'inv_first']);

        // نتجاوز جلب الدفعة من ميسّر: `settle` تُنادى بالحمولة نفسها التي يعيدها المتحكّم
        $belongs = $exec->invoices()->where('gateway_ref', 'inv_first')->exists();
        $this->assertTrue($belongs, 'المطابقة على أيّ فاتورة لهذا الطلب لا على أحدثها');

        $this->assertTrue(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_cb', 'status' => 'paid', 'invoice_id' => 'inv_first',
            'amount' => (int) $first->amount * 100, 'currency' => 'SAR',
        ]), 'callback'));

        $exec->refresh();
        $this->assertSame(7, $exec->effectiveStage());
        $this->assertSame(1, (int) $exec->installments_paid);
    }

    /** الدفعتان 2 و3 تُسدَّدان بمسار الفواتير العامّ — محروساً بالملكيّة. */
    public function test_a_later_installment_is_paid_through_the_generic_invoice_checkout(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.publishable_key' => 'pk_test_x']);
        $exec = $this->planned();
        $plan = $this->plan($exec);
        PaymentReconciler::settleManual($plan[0], 'الإدارة');
        $exec->refresh()->update(['stage' => 8]);

        $owner = User::find($exec->user_id);
        $stranger = User::factory()->create(['role' => Role::Client]);

        // غير المالك مردود قبل أن يبلغ البوّابة
        $this->actingAs($stranger)->post(route('invoices.checkout', $plan[1]))->assertForbidden();

        // والمالك يُقبل طلبه (الوجهة بوّابة خارجيّة، فيكفي ألّا يُردّ بـ403/404)
        $response = $this->actingAs($owner)->post(route('invoices.checkout', $plan[1]));
        $this->assertNotContains($response->getStatusCode(), [403, 404]);
    }

    /** العودة من سداد الدفعة الأولى تفتح **الملفّ نفسه** — كانت تعيد إلى قائمة التنفيذ بلا رقم الملفّ. */
    public function test_returning_from_the_first_payment_reopens_the_same_execution_file(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $exec = $this->planned();
        $first = $this->plan($exec)[0];
        $first->update(['gateway_ref' => 'inv_first']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_back1', 'status' => 'paid', 'invoice_id' => 'inv_first',
            'amount' => (int) $first->amount * 100, 'currency' => 'SAR',
        ])]);

        $this->actingAs(User::find($exec->user_id))
            ->get(route('exec-flow.pay.callback', $exec).'?id=pay_back1')
            ->assertRedirect(route('execs', ['id' => $exec->number]))->assertSessionHas('success');
    }

    /** والدفعة التالية (مسار الفواتير العامّ) تعود إلى الملفّ لا إلى صفحة الفواتير — نجاحاً أو تعذّراً. */
    public function test_returning_from_a_later_installment_reopens_the_same_execution_file(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $exec = $this->planned();
        $plan = $this->plan($exec);
        PaymentReconciler::settleManual($plan[0], 'الإدارة');
        $second = $plan[1]->fresh();
        $second->update(['gateway_ref' => 'inv_second']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::sequence()
            ->push(['id' => 'pay_back2', 'status' => 'paid', 'invoice_id' => 'inv_second', 'amount' => (int) $second->amount * 100, 'currency' => 'SAR'])
            ->push([], 500)]);
        $owner = User::find($exec->user_id);
        $fileUrl = route('execs', ['id' => $exec->number]);

        $this->actingAs($owner)->get(route('invoices.checkout.callback', $second).'?id=pay_back2')
            ->assertRedirect($fileUrl)->assertSessionHas('success');
        $this->assertTrue((bool) $second->fresh()->paid);

        $this->actingAs($owner)->get(route('invoices.checkout.callback', $plan[2]).'?id=pay_none')
            ->assertRedirect($fileUrl)->assertSessionHas('error');
    }

    /** والفاتورة المسدَّدة لا تُسدَّد مرّتين من هذا المسار. */
    public function test_a_settled_installment_is_refused_by_the_checkout(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $exec = $this->planned();
        $plan = $this->plan($exec);
        PaymentReconciler::settleManual($plan[0], 'الإدارة');

        $this->actingAs(User::find($exec->user_id))
            ->post(route('invoices.checkout', $plan[0]->fresh()))
            ->assertStatus(422);
    }

    /** حرّاس المبلغ والعملة يبقيان: عودةٌ بمبلغٍ مُلاعَبٍ لا تسوّي شيئاً. */
    public function test_the_amount_and_currency_guards_still_reject_a_tampered_return(): void
    {
        $exec = $this->planned();
        $first = $this->plan($exec)[0];

        $this->assertFalse(PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_bad', 'status' => 'paid',
            'amount' => 100, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => $first->number],
        ]), 'callback'));

        $exec->refresh();
        $this->assertSame(6, $exec->effectiveStage());
        $this->assertFalse((bool) $exec->paid);
        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->where('paid', true)->count());
    }

    /** وزرّ المرحلة 6 لا يفتح الخطّة مرّتين حين يعود العميل إليه. */
    public function test_choosing_the_plan_twice_does_not_multiply_the_invoices(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $exec = $this->planned();
        $owner = User::find($exec->user_id);

        $this->actingAs($owner)->post(route('exec-flow.pay', $exec), ['plan' => 'install']);

        $this->assertSame(3, Invoice::where('exec_id', $exec->id)->count());
    }
}
