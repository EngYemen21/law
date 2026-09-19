<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ExecFee;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **خطّة تقسيط أتعاب التنفيذ** (قرار المالك 2026-09-12: ثلاث دفعات، والملفّ يُفتح بأوّل
 * دفعةٍ مسوّاة فعلاً).
 *
 * كانت قائمة «طريقة السداد» تعرض «دفعات» ولا يقرؤها كود: تصدر فاتورةٌ واحدة كاملة مهما
 * اختار المكتب أو العميل. وكلّ اختبارٍ هنا يمثّل فرقاً بين ما كانت الشاشة تَعِد به وما يقع.
 */
class ExecInstallmentPlanTest extends TestCase
{
    use RefreshDatabase;

    /** ملفٌّ في المرحلة 6 بعرضٍ ثابت مقبول وفاتورةٍ واحدة — نقطة انطلاق التقسيط. */
    private function accepted(int $fee = 6001, int $vat = 900): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-INS-'.uniqid(), 'subject' => 'تنفيذ سند لأمر',
            'status' => 'عرض الخدمة', 'tone' => 'b-amber', 'stage' => 5, 'amount' => 120000,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => $fee, 'vat' => $vat, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ]);

        ExecFee::openOnAcceptance($exec);

        return $exec->fresh();
    }

    /** يسوّي فاتورةً عبر مسار البوّابة الحقيقيّ — لا `update(paid)` يدويّ يتجاوز المُسوّي. */
    private function settle(Invoice $invoice, string $paymentId): bool
    {
        return PaymentReconciler::settle([
            'id' => $paymentId,
            'status' => 'paid',
            'amount' => (int) $invoice->amount * 100,
            'currency' => 'SAR',
            'metadata' => ['invoice_number' => $invoice->number],
        ], 'webhook');
    }

    /** @return Collection<int, Invoice> */
    private function plan(Execution $exec)
    {
        return Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get();
    }

    public function test_opening_the_plan_creates_three_real_invoices_summing_to_the_fee(): void
    {
        $exec = $this->accepted(6001, 900);   // 6901 — كسرٌ لا يقبل القسمة على ثلاثة

        $this->assertNotNull(ExecFee::openInstallmentPlan($exec));

        $plan = $this->plan($exec->fresh());
        $this->assertCount(3, $plan);
        $this->assertSame([1, 2, 3], $plan->pluck('installment_no')->all());
        // الكسر في الأولى فيساوي المجموعُ الأتعابَ بالضبط — لا ريال يضيع ولا يُستحدث
        $this->assertSame([2301, 2300, 2300], $plan->pluck('amount')->all());
        $this->assertSame(6901, (int) $plan->sum('amount'));
        $this->assertSame([false, false, false], $plan->pluck('paid')->map(fn ($p) => (bool) $p)->all());

        $exec->refresh();
        $this->assertSame(6, $exec->effectiveStage(), 'اختيار الخطّة لا يفتح الملفّ');
        $this->assertFalse((bool) $exec->paid);
        $this->assertEmpty($exec->exec_no);
        $this->assertSame(0, $exec->procedures()->count());
        $this->assertSame(3, (int) $exec->installments_total);
        $this->assertSame(0, (int) $exec->installments_paid);
        $this->assertSame($plan[0]->number, $exec->invoice_no, 'رقم فاتورة فتح الملفّ هو الدفعة الأولى');
        $this->assertSame('دفعات', $exec->pay_method, 'العنوان المعروض يتبع ما يقع');
    }

    public function test_settling_the_first_installment_opens_the_file(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);
        $plan = $this->plan($exec->fresh());

        $this->assertTrue($this->settle($plan[0], 'pay_first'));

        $exec->refresh();
        $this->assertSame(7, $exec->effectiveStage());
        $this->assertTrue((bool) $exec->paid);
        $this->assertNotEmpty($exec->exec_no);
        $this->assertSame(1, (int) $exec->installments_paid);
        $this->assertSame(1, $exec->procedures()->count());
        $this->assertFalse($exec->feeFullySettled(), 'دفعةٌ من ثلاث ليست سداداً كاملاً');

        // **الشطب المجّانيّ**: كان `latest('id')` يُصيّر الدفعة الثالثة مدفوعةً مع الأولى
        $this->assertSame(1, Invoice::where('exec_id', $exec->id)->where('paid', true)->count());
    }

    public function test_later_installments_never_rewind_the_stage_or_remint_the_reference(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);
        $plan = $this->plan($exec->fresh());
        $this->settle($plan[0], 'pay_1');

        // الملفّ تقدّم إلى «قيد التنفيذ» قبل أن تحلّ الدفعة الثانية
        $exec->refresh()->update(['stage' => 8]);
        $execNo = $exec->fresh()->exec_no;
        $procedures = $exec->procedures()->count();

        $this->settle($plan[1], 'pay_2');
        $exec->refresh();
        $this->assertSame(8, $exec->effectiveStage(), 'دفعةٌ لاحقة لا تسحب الملفّ إلى المرحلة 7');
        $this->assertSame($execNo, $exec->exec_no, 'ولا تسكّ رقماً مرجعياً ثانياً');
        $this->assertSame($procedures, $exec->procedures()->count());
        $this->assertSame(2, (int) $exec->installments_paid);
        $this->assertFalse($exec->feeFullySettled());

        $this->settle($plan[2], 'pay_3');
        $exec->refresh();
        $this->assertSame(8, $exec->effectiveStage());
        $this->assertSame(3, (int) $exec->installments_paid);
        $this->assertTrue($exec->feeFullySettled(), 'الدفعة الأخيرة تُنهي الخطّة');
    }

    public function test_a_duplicate_webhook_does_not_advance_the_plan(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);
        $plan = $this->plan($exec->fresh());

        $this->settle($plan[0], 'pay_1');
        $this->settle($plan[0], 'pay_1'); // تكرار تسليم الطابور

        $this->assertSame(1, (int) $exec->fresh()->installments_paid);
        $this->assertSame(1, $exec->fresh()->procedures()->count());
    }

    public function test_a_manually_collected_installment_advances_the_plan(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);
        $plan = $this->plan($exec->fresh());
        $this->settle($plan[0], 'pay_1');

        $this->assertTrue(PaymentReconciler::settleManual($plan[1]->fresh(), 'الإدارة'));

        $this->assertSame(2, (int) $exec->fresh()->installments_paid);
        $this->assertSame(1, $plan[1]->fresh()->payments()->count(), 'التحصيل اليدويّ يقيّد الدفتر');
    }

    /** زرّ المرحلة 6 كان يأخذ `latest('id')` — أي الدفعة **الثالثة**. */
    public function test_the_payment_button_targets_the_oldest_unpaid_installment(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);
        $plan = $this->plan($exec->fresh());

        $this->assertSame($plan[0]->id, ExecFee::nextPayable($exec->fresh())?->id);

        $this->settle($plan[0], 'pay_1');
        $this->assertSame($plan[1]->id, ExecFee::nextPayable($exec->fresh())?->id);
    }

    /** خطّةٌ مفتوحة لا تُفتح مرّتين — نقرتان لا تُنتجان ست فواتير. */
    public function test_the_plan_cannot_be_opened_twice(): void
    {
        $exec = $this->accepted();
        ExecFee::openInstallmentPlan($exec);

        $this->assertNull(ExecFee::openInstallmentPlan($exec->fresh()));
        $this->assertSame(3, Invoice::where('exec_id', $exec->id)->count());
    }
}
