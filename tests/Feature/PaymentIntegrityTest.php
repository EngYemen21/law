<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use App\Support\CaseFee;
use App\Support\InvoiceNumber;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * سلامة المال (المرحلة 2): الشحن المزدوج · تصادم أرقام الفواتير · تسوية الفاتورة المقصودة.
 */
class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function paidConsultInvoice(User $client, string $paymentId = 'pay_first'): Invoice
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-DUP-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'مستشار', 'status' => 'بانتظار السداد', 'price' => 450, 'vat' => 68, 'total' => 518,
        ]);
        $invoice = $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-DUP-1', 'description' => 'استشارة',
            'amount' => 518, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);

        // التسوية الأولى (الدفعة الحقيقية)
        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => $paymentId, 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-DUP-1'],
        ]), 'webhook');

        return $invoice->fresh();
    }

    /**
     * 🔴 settle() كان يفحص حالة الدفعة والمبلغ والعملة ولا يفحص أن الفاتورة مدفوعة أصلاً.
     * عميل يدفع ثم يعيد المحاولة قبل وصول الـwebhook ⇒ شحنتان حقيقيتان، والثانية تطمس
     * مرجع الأولى فيضيع أثر الدفعة الأصلية ولا شيء ينبّه أحداً.
     */
    public function test_a_second_distinct_payment_never_overwrites_the_first(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->paidConsultInvoice($client);

        $this->assertTrue($invoice->paid);
        $this->assertSame('pay_first', $invoice->gateway_payment_id);

        Log::spy();

        // شحنة ثانية بمعرّف مختلف على نفس الفاتورة
        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_second', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-DUP-1'],
        ]), 'webhook');

        $this->assertSame(
            'pay_first',
            $invoice->fresh()->gateway_payment_id,
            'الشحنة الثانية طمست مرجع الدفعة الأصلية.'
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'duplicate'))
            ->once();
    }

    /** 🔴 بوّابة الدفع كانت تُنشئ فاتورة جديدة لفاتورة مدفوعة — نصف الشحن المزدوج الآخر. */
    public function test_gateway_refuses_a_new_hosted_invoice_for_a_paid_invoice(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->paidConsultInvoice($client);

        Http::fake(['api.moyasar.com/*' => Http::response(['id' => 'inv_new', 'url' => 'https://moyasar.test/x'], 201)]);

        $url = app(MoyasarGateway::class)->hostedUrlForInvoice($invoice, 'https://app.test/callback');

        $this->assertNull($url, 'أُنشئت فاتورة بوّابة جديدة لفاتورة مدفوعة.');
        Http::assertNothingSent();
    }

    /**
     * 🔴 أربعة مواضع تولّد INV-YYYY-NNNN من random_int(1,9999) على عمود unique بلا إعادة
     * محاولة — التصادم كان يُجهض التسعير بخطأ 500 ويُرجع الاستشارة لحالتها السابقة.
     */
    public function test_invoice_number_generator_avoids_existing_numbers(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $year = now()->format('Y');

        // نحجز كل المساحة تقريباً ثم نطلب رقماً جديداً
        for ($n = 1; $n <= 9998; $n++) {
            Invoice::insert([
                'user_id' => $client->id,
                'number' => 'INV-'.$year.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'description' => 'حجز', 'amount' => 1, 'status' => 'مستحقة', 'tone' => 'b-amber',
                'due_label' => '—', 'paid' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // الخاصيّة المطلوبة ليست شكل الرقم بل أنّه **غير مستعمل** مهما ضاقت المساحة
        $number = InvoiceNumber::next();

        $this->assertFalse(Invoice::where('number', $number)->exists(), 'المولّد أعاد رقماً محجوزاً.');
        $this->assertStringStartsWith('INV-'.$year.'-', $number);
    }

    /**
     * 🔴 الاختبار السابق نادى markInvoicePaid **مباشرةً** فمرّ أخضر بينما مسار الإنتاج
     * ما زال معطلاً: settleInvoice كان يستقبل الفاتورة ويُسقطها، وmarkPaid ينادي
     * markInvoicePaid بلا معامل فتعود لاحتياط «أحدث فاتورة غير مدفوعة» — لكن
     * PaymentReconciler كان قد قلب الفاتورة الحقيقية إلى مدفوعة قبل سطر واحد، فالاحتياط
     * يلتقط **فاتورة أخرى ويشطبها بلا مقابل**.
     *
     * هذا الاختبار يمرّ بالمسار الحقيقي: PaymentReconciler::settle.
     */
    public function test_settling_through_the_reconciler_leaves_other_invoices_untouched(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'C-2026-77', 'title' => 'قضية', 'court' => 'المحكمة',
            'type' => 'قضية تجارية', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        $target = Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-REC-A',
            'description' => 'أتعاب', 'amount' => 1000, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => '—', 'paid' => false,
        ]);
        $extra = Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-REC-B',
            'description' => 'أتعاب تكميلية', 'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => '—', 'paid' => false,
        ]);

        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_rec_1', 'status' => 'paid', 'amount' => 100000, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-REC-A'],
        ]), 'webhook');

        $this->assertTrue($target->fresh()->paid);
        $this->assertFalse($extra->fresh()->paid, 'الفاتورة التكميلية شُطبت بلا سداد.');
        $this->assertSame('paid', $case->fresh()->fee_status);
    }

    /**
     * 🔴 markInvoicePaid كان يصفّر **كل** فواتير الكيان غير المدفوعة لا المقصودة —
     * فاتورة تكميلية على نفس القضية تُشطب بلا مقابل.
     */
    public function test_settling_one_case_invoice_leaves_a_second_unpaid_invoice_alone(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'C-2026-1', 'title' => 'قضية', 'court' => 'المحكمة',
            'type' => 'قضية تجارية',
            'status' => 'قيد التحضير', 'tone' => 'b-blue', 'fee_status' => 'pending_payment',
        ]);

        $target = Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-CASE-A',
            'description' => 'أتعاب', 'amount' => 1000, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => '—', 'paid' => false,
        ]);
        $extra = Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-CASE-B',
            'description' => 'أتعاب تكميلية', 'amount' => 500, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => '—', 'paid' => false,
        ]);

        CaseFee::markInvoicePaid($case, $target);

        $this->assertTrue($target->fresh()->paid);
        $this->assertFalse($extra->fresh()->paid, 'فاتورة ثانية شُطبت بلا سداد.');
    }

    /**
     * **سباق الخطّاف والعودة على فاتورة الاستشارة:** كلاهما قرأ الفاتورة غير مدفوعة، فسدّد الأوّل
     * وانتقل بالاستشارة، ورُفض انتقال الثاني. كان الثاني يكتب قيداً حرجاً «تتطلّب استرداداً» ويُنبّه
     * الإدارة على دفعةٍ سليمة. هنا: نسخةٌ قديمة من الفاتورة (قبل التسوية) تُسوّى بعد أن سُدّدت.
     */
    public function test_the_losing_side_of_a_webhook_callback_race_is_not_a_refund(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Admin]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RACE-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'مستشار', 'status' => 'بانتظار السداد', 'price' => 450, 'vat' => 68, 'total' => 518,
        ]);
        $stale = $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-RACE-1', 'description' => 'استشارة',
            'amount' => 518, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);

        PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_race', 'status' => 'paid', 'amount' => 51800, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => 'INV-RACE-1'],
        ]), 'webhook');
        $this->assertTrue($stale->fresh()->paid);

        $settleConsult = new \ReflectionMethod(PaymentReconciler::class, 'settleConsult');
        $this->assertTrue($settleConsult->invoke(null, $stale, $consult->fresh(), 'callback'), 'الخاسر في السباق نجاحٌ مكرّر');

        $this->assertSame(0, AuditLog::where('action', 'دفعة على فاتورة لا تقبل السداد')->count(), 'لا قيد استردادٍ على دفعةٍ سليمة');
    }
}
