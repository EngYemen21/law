<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * بوّابة الدفع Moyasar (نظام الفواتير المستضاف): بدء الدفع، التحقّق الخادميّ عند العودة (callback)،
 * وإشعارات الويب (webhook) بتحقّق secret_token وidempotency. لا دفع حقيقي — Http::fake.
 * بلا مفتاح مهيّأ (phpunit) تُرفض نقطة الدفع (503) — لا تزوير.
 */
class MoyasarPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function pendingConsult(User $client, int $total = 518): Consult
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PAY-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'مستشار', 'status' => 'بانتظار السداد', 'price' => 450, 'vat' => $total - 450, 'total' => $total,
        ]);
        $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-PAY-1', 'description' => 'استشارة CN-PAY-1',
            'amount' => $total, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);

        return $consult->fresh();
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
    }

    public function test_pay_requires_gateway_configured(): void
    {
        // بلا مفاتيح ميسّر (phpunit) → الدفع مرفوض (503)، لا تزوير
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);

        $this->actingAs($client)->post(route('consults.pay', $consult))->assertStatus(503);

        $consult->refresh();
        $this->assertSame('بانتظار السداد', $consult->status);
        $this->assertNull($consult->paid_at);
    }

    public function test_pay_initiates_moyasar_and_redirects_when_configured(): void
    {
        $this->configureMoyasar();
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_123', 'url' => 'https://moyasar.test/pay/inv_123'], 201)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);

        $this->actingAs($client)->post(route('consults.pay', $consult))
            ->assertRedirect('https://moyasar.test/pay/inv_123');

        $consult->refresh();
        // لا تُدفع قبل التأكيد (webhook/callback مصدر الحقيقة)
        $this->assertSame('بانتظار السداد', $consult->status);
        $this->assertNull($consult->paid_at);
        $this->assertSame('inv_123', $consult->invoice->fresh()->gateway_ref);
    }

    public function test_webhook_marks_paid_on_valid_secret_and_paid_status(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        // الـwebhook يعيد جلب الدفعة من ميسّر (لا يثق بجسم الحدث) — نزيّف استجابة API.
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-PAY-1', 'consult_id' => (string) $consult->id],
        ], 200)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1',
            'type' => 'payment_paid',
            'data' => ['id' => 'pay_1'], // المعرّف فقط؛ الباقي يأتي من الجلب الموثوق
        ])->assertOk();

        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNotNull($consult->paid_at);
        $invoice = $consult->invoice->fresh();
        $this->assertTrue($invoice->paid);
        $this->assertSame('pay_1', $invoice->gateway_payment_id);
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'WRONG',
            'data' => ['id' => 'pay_x', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
                'metadata' => ['invoice_number' => 'INV-PAY-1']],
        ])->assertForbidden();

        $this->assertNull($consult->fresh()->paid_at);
    }

    public function test_webhook_is_idempotent(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-PAY-1', 'consult_id' => (string) $consult->id],
        ], 200)]);
        $payload = ['secret_token' => 'whsec_1', 'data' => ['id' => 'pay_1']];

        $this->postJson(route('webhooks.moyasar'), $payload)->assertOk();
        $this->postJson(route('webhooks.moyasar'), $payload)->assertOk();

        // دُفعت مرّة واحدة: إشعار سداد واحد فقط
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('tone', 't-green')->count());
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        // الجلب الموثوق يُرجع مبلغاً لا يطابق الفاتورة → لا تسوية
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 10000, 'currency' => 'SAR', // مبلغ خاطئ
            'metadata' => ['invoice_number' => 'INV-PAY-1', 'consult_id' => (string) $consult->id],
        ], 200)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1',
            'data' => ['id' => 'pay_1'],
        ])->assertOk(); // نقبل الحدث لكن لا نُسوّي

        $this->assertNull($consult->fresh()->paid_at);
        $this->assertFalse($consult->invoice->fresh()->paid);
    }

    public function test_webhook_returns_502_and_skips_settle_when_fetch_fails(): void
    {
        // فشل جلب الدفعة من ميسّر → لا تسوية، والردّ 502 ليعيد ميسّر الإرسال
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([], 500)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1',
            'data' => ['id' => 'pay_1'],
        ])->assertStatus(502);

        $this->assertNull($consult->fresh()->paid_at);
        $this->assertFalse($consult->invoice->fresh()->paid);
    }

    public function test_callback_verifies_via_api_and_settles(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $consult->invoice->update(['gateway_ref' => 'inv_cb1']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR', 'invoice_id' => 'inv_cb1',
            'metadata' => ['invoice_number' => 'INV-PAY-1', 'consult_id' => (string) $consult->id],
        ], 200)]);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult).'?id=pay_1')
            ->assertRedirect(route('myconsults'))->assertSessionHas('success');

        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNotNull($consult->paid_at);
    }

    public function test_callback_rejects_amount_mismatch(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $consult->invoice->update(['gateway_ref' => 'inv_cb1']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 10000, 'currency' => 'SAR', 'invoice_id' => 'inv_cb1', // مبلغ خاطئ
            'metadata' => ['invoice_number' => 'INV-PAY-1', 'consult_id' => (string) $consult->id],
        ], 200)]);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult).'?id=pay_1')
            ->assertRedirect(route('myconsults'))->assertSessionHas('error');

        $this->assertNull($consult->fresh()->paid_at);
    }

    public function test_callback_rejects_payment_bound_to_another_invoice(): void
    {
        // إصلاح confused deputy: دفعة معرّف فاتورتها لا يطابق فاتورة هذه الاستشارة → لا تسوية
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $consult->invoice->update(['gateway_ref' => 'inv_mine']);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_x', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR', 'invoice_id' => 'inv_other',
            'metadata' => ['invoice_number' => 'INV-PAY-1'],
        ], 200)]);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult).'?id=pay_x')
            ->assertRedirect(route('myconsults'))->assertSessionHas('error');

        $this->assertNull($consult->fresh()->paid_at);
    }

    public function test_pay_reuses_existing_moyasar_invoice(): void
    {
        // إصلاح double-invoice: بوجود gateway_ref لفاتورة قائمة غير مدفوعة → يُعاد استخدامها بلا إنشاء نسخة
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $consult->invoice->update(['gateway_ref' => 'inv_existing']);
        Http::fake([
            'api.moyasar.com/v1/invoices/inv_existing' => Http::response(['id' => 'inv_existing', 'url' => 'https://moyasar.test/pay/inv_existing', 'status' => 'initiated'], 200),
            'api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_NEW', 'url' => 'https://moyasar.test/pay/inv_NEW'], 201),
        ]);

        $this->actingAs($client)->post(route('consults.pay', $consult))
            ->assertRedirect('https://moyasar.test/pay/inv_existing'); // أُعيدت القائمة لا الجديدة

        $this->assertSame('inv_existing', $consult->invoice->fresh()->gateway_ref); // لم يتغيّر
        Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_ends_with($req->url(), '/v1/invoices'));
    }

    public function test_callback_is_owner_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);

        $this->assertPageRefused($this->actingAs($intruder)->get(route('consults.pay.callback', $consult).'?id=pay_1'));
    }
}
