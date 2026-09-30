<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\MoyasarGateway;
use App\Support\ExecService;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **نموذج «نسبة من المحصّل»** (قرار المالك 2026-09-12: لا مقدَّم، والملفّ يُفتح فور قبول
 * العرض، وفاتورة أتعابٍ مع كلّ تحصيل).
 *
 * كان الخيار معروضاً على العميل في قائمةٍ لا يقرؤها كود: يختاره المكتب فتصدر فاتورةٌ واحدة
 * بكامل الأتعاب ويُحبس الملفّ حتى تُسدَّد — عكس ما تقوله الشاشة تماماً.
 */
class ExecPercentageFeeTest extends TestCase
{
    use RefreshDatabase;

    private function percentOffer(float $pct = 10, int $claim = 120000): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-PCT-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => 'عرض الخدمة', 'tone' => 'b-amber', 'stage' => 5, 'amount' => $claim,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 0, 'vat' => 0, 'fee_approved' => true,
            'fee_mode' => 'percent', 'collection_fee_pct' => $pct,
        ]);
    }

    /** يقدّم الملفّ إلى المرحلة 8 حيث يقع التحصيل فعلاً. */
    private function underway(Execution $exec): Execution
    {
        $exec->update(['stage' => 8, 'status' => 'قيد التنفيذ', 'registered_at' => now()->subDays(3)->toDateString()]);

        return $exec->fresh();
    }

    public function test_accepting_a_percentage_offer_opens_the_file_with_no_invoice(): void
    {
        $exec = $this->percentOffer();

        ExecService::acceptOffer($exec);

        $exec->refresh();
        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->count(), 'لا مبلغ مقدَّم ⇒ لا فاتورة');
        $this->assertSame('', (string) $exec->invoice_no);
        $this->assertSame(7, $exec->effectiveStage(), 'الملفّ يُفتح بالقبول لا بالسداد');
        $this->assertTrue((bool) $exec->paid, 'لا التزام سدادٍ معلّق على فتح الملفّ');
        $this->assertNotEmpty($exec->exec_no);
        $this->assertSame(1, $exec->procedures()->count());
        $this->assertSame('نسبة من المحصّل', $exec->pay_method);
    }

    public function test_every_collection_issues_its_own_fee_invoice(): void
    {
        $exec = $this->underway($this->percentOffer(10));

        ExecService::addCollection($exec, 25000);
        ExecService::addCollection($exec->fresh(), 15000);

        $invoices = Invoice::where('exec_id', $exec->id)->orderBy('id')->get();
        $this->assertCount(2, $invoices);
        $this->assertSame([2875, 1725], $invoices->pluck('amount')->all()); // 2500+375 · 1500+225
        $this->assertSame([null, null], $invoices->pluck('installment_no')->all(), 'ليست دفعات خطّة');
        $this->assertStringContainsString('10% من تحصيل 25,000', (string) $invoices[0]->description);
        $this->assertStringContainsString('7 أيام', (string) $invoices[0]->due_label);

        $exec->refresh();
        $this->assertSame(40000, (int) $exec->collected, 'المحصَّل يُراكَم كما كان');
        $this->assertSame(2, $exec->procedures()->where('type', 'تحصيل')->count());
    }

    public function test_the_fee_base_is_rounded_to_whole_riyals_and_vat_follows_the_setting(): void
    {
        Setting::put('vat_rate', 15);
        $exec = $this->underway($this->percentOffer(7.5));

        ExecService::addCollection($exec, 3333);

        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();
        // 249.975 ⇒ 250 ، وضريبة 37.5 ⇒ 38 — والإجمالي يساوي الأساس والضريبة بالضبط
        $this->assertSame(288, (int) $invoice->amount);
        $this->assertStringContainsString('7.5% من تحصيل 3,333', (string) $invoice->description);
    }

    /** أتعابٌ دون الريال لا تُفوتَر — فاتورةٌ بصفرٍ أسوأ من لا فاتورة. */
    public function test_a_fee_rounding_below_one_riyal_issues_no_invoice(): void
    {
        $exec = $this->underway($this->percentOffer(0.01));

        ExecService::addCollection($exec, 50);

        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->count());
        $this->assertSame(50, (int) $exec->fresh()->collected, 'والتحصيل يُسجَّل رغم ذلك');
    }

    /** ملفٌّ بنموذجٍ ثابت يُسجَّل تحصيله ولا يُفوتَر — ولا يُرمى استثناء. */
    public function test_a_collection_on_a_fixed_fee_file_is_never_invoiced(): void
    {
        $exec = $this->percentOffer();
        $exec->update(['fee_mode' => 'fixed', 'collection_fee_pct' => null, 'fee' => 6000, 'vat' => 900]);
        $exec = $this->underway($exec->fresh());

        ExecService::addCollection($exec, 25000);

        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->count());
        $this->assertSame(25000, (int) $exec->fresh()->collected);
    }

    public function test_settling_a_collection_invoice_does_not_touch_the_file_state(): void
    {
        $exec = $this->percentOffer(10);
        ExecService::acceptOffer($exec);
        $exec = $this->underway($exec->fresh());
        ExecService::addCollection($exec, 25000);

        $execNo = $exec->fresh()->exec_no;
        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();

        $settled = PaymentReconciler::settle(MoyasarGateway::toGatewayPayment([
            'id' => 'pay_collection', 'status' => 'paid',
            'amount' => (int) $invoice->amount * 100, 'currency' => 'SAR',
            'metadata' => ['invoice_number' => $invoice->number],
        ]), 'webhook');

        $this->assertTrue($settled);
        $exec->refresh();
        $this->assertSame(8, $exec->effectiveStage(), 'فاتورة أتعابٍ عن تحصيل لا تحرّك المرحلة');
        $this->assertSame($execNo, $exec->exec_no);
        $this->assertSame(0, (int) $exec->installments_paid, 'ولا تُحسب دفعةً في خطّة');
    }

    /** العرض النسبيّ يُعتمد بنسبته — وحارس «لا عرض بصفر» يبقى على النموذج الثابت. */
    public function test_approval_accepts_a_percentage_offer_and_still_refuses_a_zero_fixed_fee(): void
    {
        $exec = $this->percentOffer(10);
        $exec->update(['stage' => 4, 'fee_approved' => false]);

        ExecService::approveFee($exec->fresh());
        $this->assertSame(5, $exec->fresh()->effectiveStage());
        $this->assertTrue((bool) $exec->fresh()->fee_approved);

        $fixed = $this->percentOffer(10);
        $fixed->update(['stage' => 4, 'fee_approved' => false, 'fee_mode' => 'fixed', 'collection_fee_pct' => null, 'fee' => 0]);

        $this->expectExceptionMessage('حدّد أتعاب التنفيذ قبل اعتماد العرض.');
        ExecService::approveFee($fixed->fresh());
    }

    /** والنموذج النسبيّ بلا نسبةٍ لا يُعتمد — عرضٌ بلا سعر بأيّ صورة. */
    public function test_a_percentage_offer_without_a_percentage_is_refused(): void
    {
        $exec = $this->percentOffer(10);
        $exec->update(['stage' => 4, 'fee_approved' => false, 'collection_fee_pct' => null]);

        $this->expectExceptionMessage('حدّد نسبة الأتعاب من المحصّل قبل اعتماد العرض.');
        ExecService::approveFee($exec->fresh());
    }

    /** العنوان المعروض للعميل يُشتقّ فلا يخالف المحرّك — والنسبة تُخزَّن ولا تضيع في المتصفّح. */
    public function test_the_offer_label_can_never_contradict_the_engine(): void
    {
        $exec = $this->percentOffer();
        $exec->update(['stage' => 3, 'fee_approved' => false, 'fee_mode' => null, 'collection_fee_pct' => null]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'feeMode' => 'percent', 'feePct' => 10, 'duration' => '30 يوم',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame('percent', $exec->fee_mode);
        $this->assertSame('نسبة من المحصّل', $exec->pay_method);
        $this->assertSame(10.0, (float) $exec->collection_fee_pct);
        $this->assertSame(0, (int) $exec->fee, 'النموذج النسبيّ بلا مبلغ ثابت');
        $this->assertSame(0, (int) $exec->vat);

        // وإعادة التسعير بنموذجٍ ثابت تُسقط النسبة — لا بقايا نموذجٍ سابق.
        // (إعادة التسعير على المرحلة 5 مشروطةٌ برفض العميل أو استفساره — `feeStages`.)
        $exec->update(['offer_status' => 'مرفوض']);
        $this->actingAs($admin)->post(route('exec-flow.act', $exec->fresh()), [
            'action' => 'setFee', 'feeMode' => 'fixed', 'fee' => 6000, 'duration' => '30 يوم',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame('fixed', $exec->fee_mode);
        $this->assertNull($exec->collection_fee_pct);
        $this->assertSame('دفعة واحدة', $exec->pay_method);
        $this->assertSame(6000, (int) $exec->fee);
    }

    /** التحقّق يتبع النموذج: نسبةٌ بلا رقم مردودة، ومبلغٌ ثابت بصفرٍ مردود. */
    public function test_pricing_validation_follows_the_chosen_model(): void
    {
        $exec = $this->percentOffer();
        $exec->update(['stage' => 3, 'fee_approved' => false, 'fee_mode' => null, 'collection_fee_pct' => null]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'feeMode' => 'percent'])
            ->assertSessionHasErrors('feePct');

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'feeMode' => 'percent', 'feePct' => 90])
            ->assertSessionHasErrors('feePct');

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'feeMode' => 'fixed', 'fee' => 0])
            ->assertSessionHasErrors('fee');
    }

    /** والعرض النسبيّ يُطبع بنسبته — كان حارس `fee > 0` يمنع طباعته أصلاً. */
    public function test_the_offer_pdf_prints_the_model_instead_of_a_zero_total(): void
    {
        $exec = $this->percentOffer(10);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->givePermissionTo(Permission::findOrCreate('إدارة القضايا والأتعاب'));

        $this->actingAs($admin)->get(route('exec-flow.offer.pdf', $exec))->assertSuccessful();
    }
}
