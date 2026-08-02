<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دفتر مدفوعات ميسّر (payments): صفّ لكلّ حدث دفع يصل من البوّابة — يُسجَّل تلقائيّاً من PaymentReconciler.
 * يلتقط المدفوع والمرفوض (تدقيق)، وidempotent عبر gateway_payment_id، ويختم reconciled_at عند التسوية.
 */
class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function pendingConsult(User $client, int $total = 518): Consult
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-LEDGER-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'مستشار', 'status' => 'بانتظار السداد', 'price' => 450, 'vat' => $total - 450, 'total' => $total,
        ]);
        $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-LEDGER-1', 'description' => 'استشارة CN-LEDGER-1',
            'amount' => $total, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
            'gateway_ref' => 'inv_led1',
        ]);

        return $consult->fresh();
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
    }

    /** @param  array<string,mixed>  $overrides */
    private function fakePayment(Consult $consult, array $overrides = []): void
    {
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(array_merge([
            'id' => 'pay_led_1', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR', 'invoice_id' => 'inv_led1',
            'metadata' => ['invoice_number' => 'INV-LEDGER-1', 'consult_id' => (string) $consult->id],
        ], $overrides), 200)]);
    }

    public function test_webhook_records_ledger_row_with_channel_and_reconciled(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $this->fakePayment($consult);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1', 'data' => ['id' => 'pay_led_1'],
        ])->assertOk();

        $this->assertSame(1, Payment::count());
        $payment = Payment::first();
        $this->assertSame('pay_led_1', $payment->gateway_payment_id);
        $this->assertSame('inv_led1', $payment->gateway_invoice_id);
        $this->assertSame($consult->invoice->id, $payment->invoice_id);
        $this->assertSame('moyasar', $payment->gateway);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(51800, $payment->amount);
        $this->assertSame('SAR', $payment->currency);
        $this->assertSame('webhook', $payment->source_channel);
        $this->assertNotNull($payment->reconciled_at);
        $this->assertSame('INV-LEDGER-1', $payment->raw['metadata']['invoice_number']);
    }

    public function test_callback_records_ledger_row_with_callback_channel(): void
    {
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $this->fakePayment($consult);

        $this->actingAs($client)->get(route('consults.pay.callback', $consult).'?id=pay_led_1')
            ->assertRedirect(route('myconsults'));

        $this->assertSame(1, Payment::count());
        $payment = Payment::first();
        $this->assertSame('callback', $payment->source_channel);
        $this->assertNotNull($payment->reconciled_at);
    }

    public function test_rejected_payment_is_recorded_for_audit_but_not_reconciled(): void
    {
        // عدم تطابق المبلغ → يُسجَّل في الدفتر (تدقيق) لكن دون تسوية ودون ختم reconciled_at
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $this->fakePayment($consult, ['amount' => 10000]); // مبلغ خاطئ

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1', 'data' => ['id' => 'pay_led_1'],
        ])->assertOk();

        $this->assertSame(1, Payment::count());
        $payment = Payment::first();
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('webhook', $payment->source_channel);
        $this->assertNull($payment->reconciled_at); // لم تُسوَّ
        $this->assertNull($consult->fresh()->paid_at); // المجال لم يُدفع
    }

    public function test_ledger_is_idempotent_across_webhook_and_callback(): void
    {
        // نفس الدفعة عبر webhook ثم callback → صفّ دفتر واحد، وreconciled_at يحتفظ بأوّل طابع
        $this->configureMoyasar();
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pendingConsult($client);
        $this->fakePayment($consult);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1', 'data' => ['id' => 'pay_led_1'],
        ])->assertOk();
        $firstReconciled = Payment::first()->reconciled_at;

        $this->actingAs($client)->get(route('consults.pay.callback', $consult).'?id=pay_led_1')
            ->assertRedirect(route('myconsults'));

        $this->assertSame(1, Payment::count());
        $this->assertEquals($firstReconciled, Payment::first()->reconciled_at); // لم يُدهَس
    }
}
