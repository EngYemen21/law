<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سداد أتعاب القضية عبر بوّابة ميسّر (نظام الفواتير المستضاف): بدء الدفع، webhook، callback.
 * بلا مفتاح مهيّأ (phpunit) تُرفض نقطة الدفع الكامل (503). لا دفع حقيقي — Http::fake.
 */
class CaseFeePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payableCase(User $client): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-PAY-1', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);
        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-CASE-1', 'description' => 'أتعاب قضية',
            'amount' => 10350, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false,
        ]);

        return $case->fresh();
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
    }

    public function test_full_pay_initiates_moyasar_when_configured(): void
    {
        $this->configureMoyasar();
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_c1', 'url' => 'https://moyasar.test/pay/inv_c1'], 201)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'full'])
            ->assertRedirect('https://moyasar.test/pay/inv_c1');

        $case->refresh();
        $this->assertSame('pending_payment', $case->fee_status); // لا تُدفع قبل التأكيد
        $this->assertSame('inv_c1', Invoice::where('case_id', $case->id)->first()->gateway_ref);
    }

    public function test_full_pay_requires_gateway_configured(): void
    {
        // بلا مفاتيح ميسّر (phpunit) → السداد الكامل مرفوض (503)، لا تفعيل بلا دفع حقيقي
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);

        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'full'])->assertStatus(503);

        $this->assertSame('pending_payment', $case->fresh()->fee_status);
    }

    public function test_webhook_settles_case_fee_and_activates(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        // الـwebhook يعيد جلب الدفعة من ميسّر (لا يثق بجسم الحدث) — نزيّف استجابة API.
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_c1', 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-CASE-1'],
        ], 200)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1',
            'data' => ['id' => 'pay_c1'],
        ])->assertOk();

        $case->refresh();
        $this->assertSame('paid', $case->fee_status);
        $this->assertSame('قيد التحضير', $case->status);
        $invoice = Invoice::where('case_id', $case->id)->first();
        $this->assertTrue($invoice->paid);
        $this->assertSame('pay_c1', $invoice->gateway_payment_id);
    }

    public function test_callback_verifies_and_settles_case_fee(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);
        Invoice::where('case_id', $case->id)->update(['gateway_ref' => 'inv_c1']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_c1', 'status' => 'paid', 'amount' => 1035000, 'currency' => 'SAR', 'invoice_id' => 'inv_c1',
            'metadata' => ['invoice_number' => 'INV-CASE-1'],
        ], 200)]);

        $this->actingAs($client)->get(route('cases.pay.callback', $case).'?id=pay_c1')
            ->assertRedirect(route('cases.show', $case))->assertSessionHas('success');

        $this->assertSame('paid', $case->fresh()->fee_status);
    }

    /** الأقساط تمرّ بالبوّابة كالسداد الكامل — كان هذا الاختبار يثبّت مسار «بلا بوّابة». */
    public function test_installments_go_through_the_gateway(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->payableCase($client);

        // بلا بوّابة مهيّأة لا خطّة تقسيط أصلاً (كان يُفعّل القضية مجاناً)
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install'])->assertStatus(503);
        $this->assertSame('pending_payment', $case->fresh()->fee_status);

        $this->configureMoyasar();
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);

        $this->assertSame('installments', $case->fresh()->fee_status);
        $this->assertSame(0, $case->fresh()->installments_paid);
    }
}
