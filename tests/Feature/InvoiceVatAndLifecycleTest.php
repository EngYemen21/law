<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Invoice\CancelInvoice;
use App\Domain\Journey\Transitions\Invoice\IssueInvoice;
use App\Domain\Journey\Transitions\Invoice\RecordPartialPayment;
use App\Domain\Journey\Transitions\Invoice\RefundInvoice;
use App\Domain\Journey\Transitions\Invoice\SettleInvoice;
use App\Domain\Journey\Transitions\Invoice\WriteOffInvoice;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\User;
use App\Support\CaseFee;
use App\Support\ConsultBooking;
use App\Support\ExecFee;
use App\Support\ExecService;
use App\Support\Finance\InvoiceFactory;
use App\Support\Finance\RevenueSnapshot;
use App\Support\Finance\TaxInvoiceDocument;
use App\Support\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الفاتورة الضريبيّة ودورة حياتها في المحرّك** — م١ وم٢ من خطّة النظام الماليّ.
 *
 * ما يحرسه هذا الملفّ، عطلاً عطلاً:
 *
 * - **ب٢:** الفاتورة لم تكن تحمل ضريبةً إطلاقاً — `amount` إجماليٌّ شامل بلا أساسٍ ولا نسبة.
 * - **ب١:** بلا `paid_at` لم يكن في النظام **أيُّ** تقريرٍ ماليٍّ بفترة.
 * - **ع٣:** `StateWriteGuard` كان يعفي كلّ فاتورةٍ ليست فاتورة استشارة، فسدادُ فواتير القضايا
 *   والتنفيذ — أكبر مبالغ المكتب — لا يترك سطراً في `journey_transitions`.
 * - **ع٤:** `paid` و`status` مفهومٌ واحد في عمودين، ولا حارس يمنع انزلاق أحدهما عن الآخر.
 */
class InvoiceVatAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ════════════════════════ أدوات التهيئة ════════════════════════

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    /** الحارس الأهمّ: لا ريالَ يضيع في الكسور ولا يُخترع. */
    private function assertBalanced(Invoice $invoice, string $site): void
    {
        $invoice = $invoice->fresh();

        $this->assertNotNull($invoice->subtotal, "{$site}: الفاتورة بلا أساسٍ قبل الضريبة");
        $this->assertNotNull($invoice->vat_amount, "{$site}: الفاتورة بلا ضريبة");
        $this->assertNotNull($invoice->vat_rate, "{$site}: الفاتورة بلا نسبةٍ مجمَّدة");
        $this->assertSame(
            (int) $invoice->amount,
            (int) $invoice->subtotal + (int) $invoice->vat_amount,
            "{$site}: الأساس + الضريبة ≠ الإجماليّ"
        );
        $this->assertNotNull($invoice->issued_at, "{$site}: الفاتورة بلا تاريخ إصدار");
    }

    private function pricedConsult(User $client, User $actor, int $price = 600): Invoice
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-VAT-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => '—', 'status' => 'بانتظار التسعير',
        ]);

        return ConsultBooking::setPrice($consult, $price, $actor);
    }

    private function payableCase(User $client, int $amount = 10350): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-VAT-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'assigned_lawyer' => 'أ. سارة',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        InvoiceFactory::fromTotal($amount, [
            'user_id' => $client->id, 'case_id' => $case->id,
            'description' => 'أتعاب قضية', 'due_label' => 'خلال 14 يوماً',
        ]);

        return $case->fresh();
    }

    private function fixedFeeOffer(User $client, int $fee = 4000): Execution
    {
        return Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-VAT-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => 'عرض الخدمة', 'tone' => 'b-amber', 'stage' => 5, 'amount' => 90000,
            'fee' => $fee, 'vat' => (int) round($fee * 15 / 100), 'fee_approved' => true,
            'fee_mode' => 'fixed', 'pay_plan' => 'full',
        ]);
    }

    // ════════════ ١ — مواضع الإصدار الستّة: الأساس + الضريبة = الإجماليّ ════════════

    /** ١/٦ — `Transitions/Consult/PriceConsult` (تسعير الاستشارة). */
    public function test_pricing_a_consult_issues_a_balanced_invoice(): void
    {
        Setting::put('vat_rate', 15);
        $invoice = $this->pricedConsult($this->client(), $this->admin(), 600);

        $this->assertBalanced($invoice, 'تسعير استشارة');
        $this->assertSame(600, (int) $invoice->subtotal);
        $this->assertSame(90, (int) $invoice->vat_amount);
        $this->assertSame(690, (int) $invoice->amount);
    }

    /** ٢/٦ — `Admin/CaseController::setFee` (اعتماد أتعاب القضيّة). */
    public function test_setting_a_case_fee_issues_a_balanced_invoice(): void
    {
        Setting::put('vat_rate', 15);
        $client = $this->client();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-FEE-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'assigned_lawyer' => 'أ. سارة',
            'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber', 'pleading_status' => 'none',
        ]);

        $this->actingAs($this->admin())->post(route('admin.cases.fee', $case), ['fee' => 9000])->assertRedirect();

        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();
        $this->assertBalanced($invoice, 'اعتماد أتعاب قضيّة');
        $this->assertSame(9000, (int) $invoice->subtotal);
        $this->assertSame(10350, (int) $invoice->amount);
    }

    /** ٣/٦ — `CaseFee::openInstallmentPlan` — **والأمّ تُعاد هيكلتها فتُقسَّم ضريبتُها معها**. */
    public function test_a_case_installment_plan_balances_every_share_including_the_master(): void
    {
        Setting::put('vat_rate', 15);
        $case = $this->payableCase($this->client(), 10350);

        CaseFee::openInstallmentPlan($case);

        $plan = Invoice::where('case_id', $case->id)->orderBy('installment_no')->get();
        $this->assertCount(3, $plan);
        foreach ($plan as $share) {
            $this->assertBalanced($share, 'دفعة خطّة قضيّة '.$share->installment_no);
        }
        // والمجموع يساوي الأتعاب بالضبط — القسمة لا تُضيّع ريالاً ولا تخترعه
        $this->assertSame(10350, (int) $plan->sum('amount'));
        $this->assertSame(10350, (int) $plan->sum('subtotal') + (int) $plan->sum('vat_amount'));
    }

    /** ٤/٦ — `ExecFee::openOnAcceptance` (قبول عرض أتعابٍ ثابتة). */
    public function test_accepting_a_fixed_execution_offer_issues_a_balanced_invoice(): void
    {
        Setting::put('vat_rate', 15);
        $exec = $this->fixedFeeOffer($this->client(), 4000);

        ExecService::acceptOffer($exec);

        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();
        $this->assertBalanced($invoice, 'قبول عرض تنفيذ');
        $this->assertSame(4600, (int) $invoice->amount);
    }

    /** ٥/٦ — `ExecFee::openInstallmentPlan`. */
    public function test_an_execution_installment_plan_balances_every_share(): void
    {
        Setting::put('vat_rate', 15);
        $exec = $this->fixedFeeOffer($this->client(), 4000);
        ExecService::acceptOffer($exec);

        ExecFee::openInstallmentPlan($exec->fresh());

        $plan = Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get();
        $this->assertCount(3, $plan);
        foreach ($plan as $share) {
            $this->assertBalanced($share, 'دفعة خطّة تنفيذ '.$share->installment_no);
        }
        $this->assertSame(4600, (int) $plan->sum('amount'));
    }

    /** ٦/٦ — `ExecFee::issueCollectionFee` (أتعابٌ عن مبلغٍ محصَّل). */
    public function test_a_collection_fee_invoice_is_balanced(): void
    {
        Setting::put('vat_rate', 15);
        $exec = Execution::create([
            'user_id' => $this->client()->id, 'number' => 'EXE-PCT-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => 'قيد التنفيذ', 'tone' => 'b-amber', 'stage' => 8, 'amount' => 120000,
            'fee' => 0, 'vat' => 0, 'fee_approved' => true, 'fee_mode' => 'percent', 'collection_fee_pct' => 7.5,
        ]);

        $invoice = ExecFee::issueCollectionFee($exec, 3333);

        $this->assertNotNull($invoice);
        $this->assertBalanced($invoice, 'أتعاب عن تحصيل');
        $this->assertSame(250, (int) $invoice->subtotal, 'النسبة على المحصَّل مقرَّبةً لأقرب ريال');
    }

    // ════════════ ٢ — النسبة مجمَّدةٌ يوم الإصدار ════════════

    /**
     * **تعديل نسبة الإعداد لا يمسّ فاتورةً صدرت.** لو قُرئت النسبة من الإعداد عند العرض
     * لتغيّرت ضريبةُ كلّ فواتير الماضي حين يعدّلها المكتب — أي لتغيّر مستندٌ ضريبيٌّ سُلّم
     * للعميل بالفعل.
     */
    public function test_the_vat_rate_is_frozen_on_the_invoice_and_ignores_later_setting_changes(): void
    {
        Setting::put('vat_rate', 15);
        $old = $this->pricedConsult($this->client(), $this->admin(), 1000);

        $this->assertSame(15, (int) $old->vat_rate);
        $this->assertSame(1150, (int) $old->amount);

        Setting::put('vat_rate', 5);

        $old = $old->fresh();
        $this->assertSame(15, (int) $old->vat_rate, 'نسبةُ فاتورةٍ صدرت لا تتبع الإعداد');
        $this->assertSame(150, (int) $old->vat_amount);
        $this->assertSame(1150, (int) $old->amount);
        $this->assertSame(15, (int) $old->taxBreakdown()['vat_rate'], 'والمطبوع يقرأ الصفّ لا الإعداد');

        // والجديدة وحدها تحمل النسبة الجديدة
        $new = $this->pricedConsult($this->client(), $this->admin(), 1000);
        $this->assertSame(5, (int) $new->vat_rate);
        $this->assertSame(1050, (int) $new->amount);
    }

    // ════════════ ٣ — تقريرٌ بفترة (لم يكن ممكناً قبل `paid_at`) ════════════

    /**
     * **أوّل رقمٍ ماليٍّ بفترةٍ في النظام.** قبل `paid_at` كان البديل الوحيد `updated_at` وهو
     * يتحرّك مع كلّ تعديلٍ غير ذي صلة، فـ«إيراد سبتمبر» لم يكن له جوابٌ صادق (ب١).
     */
    public function test_a_period_report_counts_only_what_was_collected_inside_it(): void
    {
        $client = $this->client();

        $inside = $this->paidInvoiceAt($client, 1150, 100, '2026-09-10');
        $this->paidInvoiceAt($client, 2300, 300, '2026-08-31'); // قبل الفترة
        $this->paidInvoiceAt($client, 4600, 600, '2026-10-01'); // بعدها
        $onEdge = $this->paidInvoiceAt($client, 575, 75, '2026-09-30'); // آخر يومٍ فيها — مشمول

        // وفاتورةٌ صدرت في الفترة ولم تُحصَّل: خارج الدخل — المعيار التحصيل لا الإصدار (ق٧)
        InvoiceFactory::fromTotal(9999, [
            'user_id' => $client->id, 'description' => 'لم تُسدَّد', 'due_label' => 'خلال 3 أيام',
        ]);

        $period = RevenueSnapshot::collectedBetween('2026-09-01', '2026-09-30');

        $this->assertSame(1150 + 575, $period['total']);
        $this->assertSame(100 + 75, $period['vat']);
        $this->assertSame(1050 + 500, $period['subtotal']);
        $this->assertSame(2, $period['count']);

        // وحدّا الفترة يقعان على الصفّين المقصودين لا على غيرهما
        $this->assertSame($inside->amount + $onEdge->amount, $period['total']);
    }

    private function paidInvoiceAt(User $client, int $amount, int $vat, string $paidAt): Invoice
    {
        return Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-P-'.uniqid(), 'description' => 'محصَّلة',
            'amount' => $amount, 'subtotal' => $amount - $vat, 'vat_rate' => 15, 'vat_amount' => $vat,
            'status' => InvoiceStatus::Paid->value, 'tone' => 'b-green', 'due_label' => '—',
            'paid' => true, 'paid_at' => $paidAt.' 12:00:00', 'issued_at' => $paidAt.' 09:00:00',
        ]);
    }

    // ════════════ ٤ — الفاتورة الضريبيّة المطبوعة ════════════

    public function test_the_printed_invoice_separates_base_vat_and_total_and_shows_the_office_vat_number(): void
    {
        Setting::put('vat_rate', 15);
        Setting::put('office_vat_number', '300000000000003');

        $invoice = $this->pricedConsult($this->client(), $this->admin(), 1000);
        $html = TaxInvoiceDocument::html($invoice);

        $this->assertStringContainsString(TaxInvoiceDocument::SUBTOTAL_LABEL, $html);
        $this->assertStringContainsString('1,000 ر.س', $html, 'الأساس قبل الضريبة');
        $this->assertStringContainsString('ضريبة القيمة المضافة (15%)', $html);
        $this->assertStringContainsString('150 ر.س', $html, 'الضريبة بمبلغها');
        $this->assertStringContainsString(TaxInvoiceDocument::TOTAL_LABEL, $html);
        $this->assertStringContainsString('1,150 ر.س', $html, 'الإجماليّ شامل الضريبة');

        $this->assertStringContainsString(TaxInvoiceDocument::VAT_NUMBER_LABEL, $html);
        $this->assertStringContainsString('300000000000003', $html);
    }

    /** **ولا رقمَ وهميّاً حين لا يكون مضبوطاً** — لا سطرَ أصلاً، لا شرطةٌ ولا منقوش. */
    public function test_the_printed_invoice_omits_the_vat_number_line_when_it_is_not_set(): void
    {
        Setting::put('vat_rate', 15);
        $invoice = $this->pricedConsult($this->client(), $this->admin(), 1000);

        $html = TaxInvoiceDocument::html($invoice);

        $this->assertStringNotContainsString(TaxInvoiceDocument::VAT_NUMBER_LABEL, $html);
        // وبقيّة المستند كما هي — الغياب لا يُسقط تفصيل المبلغ
        $this->assertStringContainsString(TaxInvoiceDocument::TOTAL_LABEL, $html);
    }

    /** وصفٌّ قديم بلا أعمدة ضريبة يُطبع بعكس الحساب لا بصفرٍ يوهم بفاتورةٍ بلا ضريبة. */
    public function test_a_legacy_invoice_without_vat_columns_still_prints_a_breakdown(): void
    {
        Setting::put('vat_rate', 15);
        $invoice = Invoice::create([
            'user_id' => $this->client()->id, 'number' => 'INV-OLD-1', 'description' => 'صفٌّ قديم',
            'amount' => 1150, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);

        $money = $invoice->taxBreakdown();

        $this->assertSame(1000, $money['subtotal']);
        $this->assertSame(150, $money['vat_amount']);
        $this->assertSame(1150, $money['subtotal'] + $money['vat_amount']);
    }

    // ════════════ ٥ — سدادُ فواتير القضايا والتنفيذ يُسجَّل في المحرّك (ع٣) ════════════

    /** **لم يكن يقع قبل م٢ إطلاقاً**: الحارس كان يعفي كلّ فاتورةٍ ليست فاتورة استشارة. */
    public function test_settling_a_case_invoice_records_a_journey_transition(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        $this->assertTrue(PaymentReconciler::settleManual($invoice, 'الإدارة'));

        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'transition' => 'invoice.settle',
            'to_state' => InvoiceStatus::Paid->value,
        ]);
        $this->assertNotNull($invoice->fresh()->paid_at, 'وتاريخ السداد يُكتب في الكتابة نفسها');
    }

    public function test_settling_an_execution_invoice_records_a_journey_transition(): void
    {
        $exec = $this->fixedFeeOffer($this->client(), 4000);
        ExecService::acceptOffer($exec);
        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();

        $this->assertTrue(PaymentReconciler::settleManual($invoice, 'الإدارة'));

        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'transition' => 'invoice.settle',
        ]);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    /** وفاتورة الاستشارة كذلك — كانت تُكتب من انتقال الاستشارة بلا سطرٍ لها هي. */
    public function test_settling_a_consult_invoice_records_a_journey_transition(): void
    {
        $invoice = $this->pricedConsult($this->client(), $this->admin(), 600);

        $this->assertTrue(PaymentReconciler::settleManual($invoice, 'الإدارة'));

        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'transition' => 'invoice.settle',
        ]);
    }

    /** وكلُّ فتحٍ يبدأ سجلَّه: الإنشاء ليس انتقالاً لكنّه أوّل سطرٍ في رحلة الفاتورة. */
    public function test_issuing_an_invoice_opens_its_journey(): void
    {
        $invoice = $this->pricedConsult($this->client(), $this->admin(), 600);

        $this->assertDatabaseHas('journey_transitions', [
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'transition' => InvoiceFactory::OPENED,
            'from_state' => null,
            'to_state' => InvoiceStatus::Due->value,
        ]);
    }

    // ════════════ ٦ — الانتقالات ترفض ما يجب رفضه ════════════

    public function test_writing_off_a_paid_invoice_is_refused(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();
        PaymentReconciler::settleManual($invoice, 'الإدارة');

        $this->expectException(TransitionDenied::class);
        Workflow::run(new WriteOffInvoice, $invoice->fresh(), $this->admin(), ['reason' => 'تعذّر التحصيل']);
    }

    public function test_writing_off_without_a_reason_is_refused(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        try {
            Workflow::run(new WriteOffInvoice, $invoice, $this->admin(), ['reason' => '   ']);
            $this->fail('شطبٌ بلا تعليل مرّ');
        } catch (TransitionDenied $denied) {
            $this->assertSame(WriteOffInvoice::NO_REASON, $denied->getMessage());
        }

        $this->assertSame(InvoiceStatus::Due->value, $invoice->fresh()->status);
    }

    public function test_writing_off_a_due_invoice_records_the_reason_and_the_date(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        Workflow::run(new WriteOffInvoice, $invoice, $this->admin(), ['reason' => 'أفلس المدين']);

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::WrittenOff->value, $invoice->status);
        $this->assertSame('أفلس المدين', $invoice->written_off_reason);
        $this->assertNotNull($invoice->written_off_at);
        $this->assertFalse((bool) $invoice->paid, 'الشطب لا يُدخل المال');
    }

    public function test_issuing_an_already_due_invoice_is_refused(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        $this->expectException(TransitionDenied::class);
        Workflow::run(new IssueInvoice, $invoice, $this->admin());
    }

    public function test_a_draft_is_not_payable_and_becomes_due_when_issued(): void
    {
        $this->assertFalse(InvoiceStatus::Draft->isPayable(), 'المسوّدة لم تُرسَل بعد');
        $this->assertFalse(InvoiceStatus::WrittenOff->isPayable());
        $this->assertTrue(InvoiceStatus::PartiallyPaid->isPayable(), 'بقيّةُ الجزئيّة مطالبةٌ قائمة');

        $draft = InvoiceFactory::fromBase(1000, [
            'user_id' => $this->client()->id, 'description' => 'مسوّدة', 'due_label' => '—',
            'status' => InvoiceStatus::Draft->value,
        ]);

        Workflow::run(new IssueInvoice, $draft, $this->admin());

        $this->assertSame(InvoiceStatus::Due->value, $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->issued_at);
    }

    /** السداد الجزئيّ بنيةً: الحالة تتحرّك وتبقى الفاتورة قابلةً للسداد. */
    public function test_a_partial_payment_moves_the_status_and_keeps_the_invoice_payable(): void
    {
        $case = $this->payableCase($this->client(), 5000);
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        Workflow::run(new RecordPartialPayment, $invoice, $this->admin(), ['amount' => 3000]);

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid->value, $invoice->status);
        $this->assertFalse((bool) $invoice->paid);
        $this->assertTrue(InvoiceStatus::from($invoice->status)->isPayable());

        // ثمّ تُحصَّل بقيّتُها فتصير مدفوعة
        Workflow::run(new SettleInvoice, $invoice, $this->admin());
        $this->assertTrue((bool) $invoice->fresh()->paid);
    }

    /** ومبلغٌ يغطّي الفاتورة كاملةً ليس سداداً جزئيّاً — وإلّا بقيت خارج الدخل وداخل الذمم. */
    public function test_a_partial_payment_covering_the_whole_invoice_is_refused(): void
    {
        $case = $this->payableCase($this->client(), 5000);
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();

        $this->expectException(TransitionDenied::class);
        Workflow::run(new RecordPartialPayment, $invoice, $this->admin(), ['amount' => 5000]);
    }

    public function test_a_refund_returns_the_claim_or_ends_it_by_payload(): void
    {
        $client = $this->client();
        $case = $this->payableCase($client);
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();
        PaymentReconciler::settleManual($invoice, 'الإدارة');

        Workflow::run(new RefundInvoice, $invoice->fresh(), $this->admin(), ['reason' => 'دفعة خاطئة']);

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Due->value, $invoice->status);
        $this->assertFalse((bool) $invoice->paid);
        $this->assertNull($invoice->paid_at, 'وإلّا بقيت داخل إيراد الفترة وهي غير مدفوعة');

        // والاسترداد على خدمةٍ أُلغيت يُنهي المطالبة
        PaymentReconciler::settleManual($invoice, 'الإدارة');
        Workflow::run(new RefundInvoice, $invoice->fresh(), $this->admin(), ['cancel' => true]);
        $this->assertSame(InvoiceStatus::Cancelled->value, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->cancelled_at);
    }

    /** ولا تُلغى مدفوعة: ردُّ مالٍ قُبض استردادٌ لا إلغاء. */
    public function test_cancelling_a_paid_invoice_is_refused(): void
    {
        $case = $this->payableCase($this->client());
        $invoice = Invoice::where('case_id', $case->id)->firstOrFail();
        PaymentReconciler::settleManual($invoice, 'الإدارة');

        $this->expectException(TransitionDenied::class);
        Workflow::run(new CancelInvoice, $invoice->fresh(), $this->admin());
    }

    // ════════════ ٧ — حارس `paid` ⟺ `status` على كلّ مسارات السداد (ع٤) ════════════

    /**
     * **مفهومٌ واحد في عمودين لا ينزلق أحدهما عن الآخر.** `AccountingController` يصفّي بـ`paid`
     * و`InvoiceStatus::isPayable` يقرأ `status` — فانزلاقهما يعني شاشةً تقول «محصَّلة» وبوّابةً
     * تقبل الدفع عليها. بعد م٢ الكاتب واحد (`SettleInvoice`)، وهذا يثبته على المسارات كلّها.
     */
    public function test_paid_and_status_never_drift_on_any_settlement_path(): void
    {
        $client = $this->client();
        $admin = $this->admin();

        // مسار الاستشارة (تحصيلٌ يدويّ عبر المحرّك)
        PaymentReconciler::settleManual($this->pricedConsult($client, $admin, 600), 'الإدارة');

        // مسار القضيّة
        $case = $this->payableCase($client);
        PaymentReconciler::settleManual(Invoice::where('case_id', $case->id)->firstOrFail(), 'الإدارة');

        // مسار التنفيذ
        $exec = $this->fixedFeeOffer($client, 4000);
        ExecService::acceptOffer($exec);
        PaymentReconciler::settleManual(Invoice::where('exec_id', $exec->id)->firstOrFail(), 'الإدارة');

        // مسار خطّة تقسيطٍ (دفعةٌ واحدة تُسدَّد، وأختاها تبقيان مستحقّتين)
        $planCase = $this->payableCase($client, 9000);
        CaseFee::openInstallmentPlan($planCase);
        PaymentReconciler::settleManual(CaseFee::nextInstallment($planCase->fresh()), 'الإدارة');

        // استشارة + قضيّة + تنفيذ + ثلاث دفعاتٍ من خطّة = ستّ فواتير على أربعة مسارات
        $this->assertSame(6, Invoice::count());

        foreach (Invoice::all() as $invoice) {
            $this->assertSame(
                (bool) $invoice->paid,
                $invoice->status === InvoiceStatus::Paid->value,
                "الفاتورة {$invoice->number}: `paid` و«مدفوعة» انزلق أحدهما عن الآخر (paid={$invoice->paid}، status={$invoice->status})"
            );

            // والمدفوعة وحدها تحمل تاريخ سداد — ولا مدفوعةَ بلا تاريخ
            $this->assertSame((bool) $invoice->paid, $invoice->paid_at !== null, "الفاتورة {$invoice->number}: تاريخ السداد لا يطابق حالتها");
        }
    }
}
