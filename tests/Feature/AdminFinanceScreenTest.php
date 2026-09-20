<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transitions\Invoice\WriteOffInvoice;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Payment;
use App\Models\User;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **شاشة «المالية والمحاسبة» `/admin/finance`** — م٣ من خطّة النظام الماليّ.
 *
 * ما تحرسه هذه الاختبارات، وما الذي يكسره غيابها:
 *
 * 1. **التبويب خادميّ** — تبويبٌ يُرشَّح في المتصفّح يرشّح الصفحة الحاليّة وحدها فيكذب مع الترقيم.
 * 2. **`/admin/accounting` يعيد توجيهاً لا ٤٠٤** — روابطُه محفوظةٌ ومكتوبةٌ في إشعاراتٍ أُرسلت (خ٨).
 * 3. **التصفية بالفترة** — أوّل ما صار ممكناً بعد عمود `invoices.paid_at` (م١)، وهو الفرق
 *    الحقيقيّ بين هذه الشاشة وما قبلها.
 * 4. **الأعمار بشرط `Invoice::isOverdue` نفسه** — وإلّا ظهرت فاتورةٌ «متأخّرة» في الأعمار
 *    و«مستحقّة» في الجدول.
 * 5. **الضريبة: الملغاة والمعدومة خارج الإقرار** — رقمٌ يُقدَّم للهيئة لا يحتمل تقريباً.
 * 6. **الإجراء يمرّ بالمحرّك** — شطبٌ بلا صفٍّ في `journey_transitions` إسقاطُ مالٍ بلا أثر.
 */
class AdminFinanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoice(User $client, array $attributes = []): Invoice
    {
        static $seq = 0;
        $seq++;

        $amount = (int) ($attributes['amount'] ?? 1150);
        // الأساس والضريبة متّسقان مع المبلغ دائماً (`subtotal + vat_amount = amount` — قاعدة م١)،
        // فلا يختبر أحدُ الاختبارات مجموعاً على صفٍّ مستحيل
        $tax = InvoiceFactory::taxFromTotal($amount);

        return Invoice::create(array_merge([
            'user_id' => $client->id,
            'number' => 'INV-F-'.$seq,
            'description' => 'أتعاب',
            'amount' => $amount,
            'subtotal' => $tax['subtotal'],
            'vat_rate' => $tax['vat_rate'],
            'vat_amount' => $tax['vat_amount'],
            'status' => InvoiceStatus::Due->value,
            'tone' => InvoiceStatus::Due->tone(),
            'due_label' => 'خلال 14 يوماً',
            'paid' => false,
            'issued_at' => now(),
        ], $attributes));
    }

    // ─────────────────────────── ١. التبويبات ───────────────────────────

    public function test_every_tab_renders_with_its_own_payload(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $paid = $this->invoice($client, ['paid' => true, 'status' => InvoiceStatus::Paid->value, 'paid_at' => now()]);
        Payment::create([
            'invoice_id' => $paid->id, 'gateway' => 'manual', 'gateway_payment_id' => 'manual-'.$paid->id,
            'status' => 'paid', 'amount' => 1150, 'amount_halalas' => 115000, 'currency' => 'SAR',
            'source_channel' => 'admin', 'raw' => ['actor' => 'مدير المكتب'], 'reconciled_at' => now(),
        ]);
        $this->invoice($client, ['due_at' => now()->subDays(45)->toDateString()]);

        $expect = [
            'dashboard' => 'dashboard.topDebtors',
            'invoices' => 'invoices.data',
            'receipts' => 'receipts.rows.data',
            'aging' => 'aging.rows',
            'vat' => 'vat.months',
        ];

        foreach ($expect as $tab => $key) {
            $this->actingAs($admin)->get(route('admin.finance', ['tab' => $tab]))
                ->assertOk()
                ->assertInertia(fn ($p) => $p->component('admin/finance')->where('tab', $tab)->has($key));
        }

        // «التقارير» روابطُ نقل — لا حمولةَ له، وهذا مقصود: لا تكرارَ لحسابٍ يعيش في شاشته
        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'reports']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('tab', 'reports')->where('dashboard', null));
    }

    /** **تبويبٌ مجهول يسقط إلى الافتراضيّ** — مَعلمةٌ مكتوبةٌ خطأً لا تستحقّ صفحة خطأ. */
    public function test_an_unknown_tab_falls_back_to_the_default_instead_of_erroring(): void
    {
        $this->actingAs($this->admin())->get(route('admin.finance', ['tab' => 'لا-وجود-له']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('tab', FinanceBoard::DEFAULT_TAB)->has('dashboard'));
    }

    /** وفترةٌ مجهولة كذلك — ومدىً مخصّصٌ بلا تاريخين يعود إلى الافتراضيّ. */
    public function test_an_unknown_period_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin())->get(route('admin.finance', ['period' => 'عقد']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('period.key', FinanceBoard::DEFAULT_PERIOD));

        $this->actingAs($this->admin())->get(route('admin.finance', ['period' => 'custom']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('period.key', FinanceBoard::DEFAULT_PERIOD));
    }

    // ─────────────────────────── ٢. المسار القديم ───────────────────────────

    /** **لا ٤٠٤ على رابطٍ محفوظ** — المسار يبقى مسجَّلاً ويُعيد التوجيه إلى تبويب الفواتير. */
    public function test_the_legacy_accounting_path_redirects_and_is_not_gone(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.accounting'));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('/admin/finance', $location, 'المسار المحفوظ يصل الشاشة الجديدة');
        $this->assertStringContainsString('tab=invoices', $location, 'ويصل تبويبَه هو لا لوحةً أخرى');
    }

    // ─────────────────────────── ٣. التصفية بالفترة ───────────────────────────

    /**
     * **الفرق الحقيقيّ بين هذه الشاشة وما قبلها.** قبل عمود `paid_at` (م١) كان كلّ رقمٍ ماليّ
     * «منذ البداية»، فسؤال «كم دخلنا هذا الشهر؟» بلا جواب.
     */
    public function test_only_invoices_settled_inside_the_period_are_counted(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $admin = $this->admin();
        $client = $this->client();

        $this->invoice($client, ['paid' => true, 'status' => InvoiceStatus::Paid->value, 'amount' => 1150, 'paid_at' => '2026-09-03 09:00:00']);
        $this->invoice($client, ['paid' => true, 'status' => InvoiceStatus::Paid->value, 'amount' => 2300, 'paid_at' => '2026-06-03 09:00:00']);

        // هذا الشهر: التي سُدّدت في سبتمبر وحدها
        $this->actingAs($admin)->get(route('admin.finance', ['period' => 'month']))
            ->assertInertia(fn ($p) => $p->where('dashboard.collected', 1150)->where('dashboard.collectedCount', 1));

        // والربع الثالث يضمّ سبتمبر ولا يضمّ يونيو (الربع الثاني)
        $this->actingAs($admin)->get(route('admin.finance', ['period' => 'quarter']))
            ->assertInertia(fn ($p) => $p->where('dashboard.collected', 1150));

        // والسنة تضمّهما معاً
        $this->actingAs($admin)->get(route('admin.finance', ['period' => 'year']))
            ->assertInertia(fn ($p) => $p->where('dashboard.collected', 3450)->where('dashboard.collectedCount', 2));

        // ومدىً مخصّصٌ يحصر يونيو وحده
        $this->actingAs($admin)->get(route('admin.finance', ['period' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($p) => $p->where('dashboard.collected', 2300));

        Carbon::setTestNow();
    }

    // ─────────────────────────── ٤. الأعمار ───────────────────────────

    /** **فاتورةٌ عمرها ٤٥ يوماً تقع في ٣١–٦٠ لا في غيرها.** */
    public function test_a_forty_five_day_old_receivable_lands_in_the_thirty_one_to_sixty_bucket_only(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $this->invoice($client, ['amount' => 500, 'due_at' => now()->subDays(45)->toDateString()]);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'aging']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('aging.rows.0.b1', 0)
                ->where('aging.rows.0.b2', 500)
                ->where('aging.rows.0.b3', 0)
                ->where('aging.rows.0.b4', 0)
                ->where('aging.rows.0.notYetDue', 0)
                ->where('aging.rows.0.total', 500));
    }

    /** **وفاتورةٌ تستحقّ اليوم ليست متأخّرة** — الشرط نفسه في `Invoice::isOverdue`: حتى نهاية اليوم. */
    public function test_an_invoice_due_today_is_not_overdue_and_not_in_any_bucket(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $this->invoice($client, ['amount' => 700, 'due_at' => now()->toDateString()]);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'aging']))
            ->assertInertia(fn ($p) => $p
                ->where('aging.rows.0.notYetDue', 700)
                ->where('aging.rows.0.overdue', 0)
                ->where('aging.rows.0.b1', 0));

        $this->actingAs($admin)->get(route('admin.finance'))
            ->assertInertia(fn ($p) => $p->where('dashboard.overdue', 0)->where('dashboard.overdueCount', 0));
    }

    /** والحدود نفسها تُقرأ من مصدرٍ واحد — فاتورةُ ٣٠ يوماً في الأولى و٦١ في الثالثة و٩١ في المفتوحة. */
    public function test_the_bucket_boundaries_are_exact(): void
    {
        $today = now()->startOfDay();

        $this->assertSame('b1', FinanceBoard::bucketOf($today->copy()->subDays(1), $today));
        $this->assertSame('b1', FinanceBoard::bucketOf($today->copy()->subDays(30), $today));
        $this->assertSame('b2', FinanceBoard::bucketOf($today->copy()->subDays(31), $today));
        $this->assertSame('b2', FinanceBoard::bucketOf($today->copy()->subDays(60), $today));
        $this->assertSame('b3', FinanceBoard::bucketOf($today->copy()->subDays(61), $today));
        $this->assertSame('b3', FinanceBoard::bucketOf($today->copy()->subDays(90), $today));
        $this->assertSame('b4', FinanceBoard::bucketOf($today->copy()->subDays(91), $today));
        // بلا تاريخ استحقاق لا تأخّر — وإدراجُها في شريحةٍ يجعل المكتب يلاحق ما لم يَعِد أحدٌ بموعده
        $this->assertSame('notYetDue', FinanceBoard::bucketOf(null, $today));
    }

    /** **والذمّة تعريفٌ واحد**: الملغاة والمعدومة خارجها — وإلّا بقيت منفوخةً بما لا يُطالَب به. */
    public function test_cancelled_and_written_off_invoices_leave_the_receivables(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        $this->invoice($client, ['amount' => 1000, 'due_at' => now()->subDays(10)->toDateString()]);
        $this->invoice($client, ['amount' => 4000, 'status' => InvoiceStatus::Cancelled->value, 'due_at' => now()->subDays(10)->toDateString()]);
        $this->invoice($client, ['amount' => 9000, 'status' => InvoiceStatus::WrittenOff->value, 'due_at' => now()->subDays(10)->toDateString()]);

        $this->actingAs($admin)->get(route('admin.finance'))
            ->assertInertia(fn ($p) => $p
                ->where('dashboard.receivables', 1000)
                ->where('dashboard.receivablesCount', 1)
                ->where('dashboard.overdue', 1000));
    }

    // ─────────────────────────── ٥. الضريبة ───────────────────────────

    /** **إقرار الفترة = مجموع `vat_amount` لفواتيرها المحصَّلة، والملغاة والمعدومة خارجه.** */
    public function test_the_period_vat_sums_only_its_collected_invoices(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $admin = $this->admin();
        $client = $this->client();

        $this->invoice($client, ['amount' => 1150, 'subtotal' => 1000, 'vat_amount' => 150, 'paid' => true, 'status' => InvoiceStatus::Paid->value, 'paid_at' => '2026-09-02 09:00:00']);
        $this->invoice($client, ['amount' => 2300, 'subtotal' => 2000, 'vat_amount' => 300, 'paid' => true, 'status' => InvoiceStatus::Paid->value, 'paid_at' => '2026-09-20 09:00:00']);
        // خارج الفترة
        $this->invoice($client, ['amount' => 5750, 'subtotal' => 5000, 'vat_amount' => 750, 'paid' => true, 'status' => InvoiceStatus::Paid->value, 'paid_at' => '2026-08-20 09:00:00']);
        // ملغاة ومعدومة — لا تُحصَّلان أصلاً فلا تدخلان الإقرار
        $this->invoice($client, ['amount' => 11500, 'subtotal' => 10000, 'vat_amount' => 1500, 'status' => InvoiceStatus::Cancelled->value]);
        $this->invoice($client, ['amount' => 23000, 'subtotal' => 20000, 'vat_amount' => 3000, 'status' => InvoiceStatus::WrittenOff->value]);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'vat', 'period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('vat.vat', 450)
                ->where('vat.subtotal', 3000)
                ->where('vat.total', 3450)
                ->where('vat.count', 2)
                ->has('vat.months', 1)
                ->where('vat.months.0.vat', 450)
                ->where('vat.invoices.meta.total', 2));

        Carbon::setTestNow();
    }

    // ─────────────────────────── ٦. الإجراءات عبر المحرّك ───────────────────────────

    /** **الشطب من الشاشة يُنشئ صفّاً في `journey_transitions`** — إسقاطُ مالٍ بلا أثرٍ لا يُدافَع عنه. */
    public function test_writing_off_from_the_screen_records_a_journey_transition(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoice($this->client(), ['amount' => 3000]);

        $this->actingAs($admin)
            ->post(route('admin.invoices.write-off', $invoice), ['reason' => 'تعذّر التحصيل بعد سنة'])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::WrittenOff->value, $invoice->status);
        $this->assertSame('تعذّر التحصيل بعد سنة', $invoice->written_off_reason);
        $this->assertNotNull($invoice->written_off_at);

        $row = JourneyTransition::where('entity_type', 'Invoice')
            ->where('entity_id', $invoice->id)
            ->where('transition', 'invoice.write_off')
            ->first();

        $this->assertNotNull($row, 'الشطب بلا سطرٍ في سجلّ الانتقالات إسقاطُ مالٍ بلا أثر');
        $this->assertSame(InvoiceStatus::Due->value, $row->from_state);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('تعذّر التحصيل بعد سنة', $row->reason);
    }

    /** **ولا شطبَ بلا سبب** — لا إسقاطَ مالٍ بلا تعليل، والتحقّق يردّه قبل المحرّك. */
    public function test_a_write_off_without_a_reason_is_refused(): void
    {
        $invoice = $this->invoice($this->client());

        $this->actingAs($this->admin())
            ->post(route('admin.invoices.write-off', $invoice), ['reason' => '  '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(InvoiceStatus::Due->value, $invoice->fresh()->status);
    }

    /** الإلغاء من الشاشة كذلك — انتقالٌ مسجَّل، وتاريخُ إلغاءٍ على الصفّ. */
    public function test_cancelling_from_the_screen_records_a_transition_and_stamps_the_date(): void
    {
        $invoice = $this->invoice($this->client());

        $this->actingAs($this->admin())
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => 'إعادة تسعير'])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled->value, $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertDatabaseHas('journey_transitions', [
            'entity_id' => $invoice->id, 'transition' => 'invoice.cancel', 'to_state' => InvoiceStatus::Cancelled->value,
        ]);
    }

    /** وإصدار المسوّدة — الحالة التي أُضيفت في م٢ بلا بابٍ يخرجها، وهذا بابها. */
    public function test_issuing_a_draft_makes_it_due(): void
    {
        $invoice = $this->invoice($this->client(), [
            'status' => InvoiceStatus::Draft->value, 'tone' => InvoiceStatus::Draft->tone(), 'issued_at' => null,
        ]);

        $this->actingAs($this->admin())->post(route('admin.invoices.issue', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Due->value, $invoice->status);
        $this->assertNotNull($invoice->issued_at);
        $this->assertDatabaseHas('journey_transitions', ['entity_id' => $invoice->id, 'transition' => 'invoice.issue']);
    }

    /** **وأزرارُ الصفّ من حارس الانتقال نفسه** — فلا يَعِد زرٌّ بما يردّه الخادم. */
    public function test_the_row_buttons_come_from_the_transition_guards(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $this->invoice($client, ['number' => 'INV-PAID-9', 'paid' => true, 'status' => InvoiceStatus::Paid->value, 'paid_at' => now()]);

        // مدفوعة: لا إصدارَ ولا تحصيلَ ولا إلغاءَ ولا شطب — الحارس نفسه هو من أخفى الأزرار
        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'invoices', 'status' => InvoiceStatus::Paid->value]))
            ->assertInertia(fn ($p) => $p->where('invoices.data.0.can', []));

        $this->invoice($client, ['number' => 'INV-DUE-9']);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'invoices', 'status' => InvoiceStatus::Due->value]))
            ->assertInertia(fn ($p) => $p
                ->has('invoices.data', 1)
                ->where('invoices.data.0.can', ['invoice.settle', 'invoice.cancel', 'invoice.write_off']));

        // ومسوّدةٌ تُصدَر ولا تُحصَّل (لم تُرسَل بعد، فمبلغٌ يصل عليها مالٌ بلا مطالبة)
        $this->invoice($client, ['number' => 'INV-DRAFT-9', 'status' => InvoiceStatus::Draft->value, 'tone' => InvoiceStatus::Draft->tone()]);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'invoices', 'status' => InvoiceStatus::Draft->value]))
            ->assertInertia(fn ($p) => $p->where('invoices.data.0.can', ['invoice.issue', 'invoice.cancel']));
    }

    // ─────────────────────────── ٧. الترشيح بالنوع ───────────────────────────

    public function test_the_kind_filter_is_applied_server_side(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-FIN-1', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        $this->invoice($client, ['number' => 'INV-K-CASE', 'case_id' => $case->id]);
        $this->invoice($client, ['number' => 'INV-K-PLAIN']);

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'invoices', 'kind' => 'case']))
            ->assertInertia(fn ($p) => $p->has('invoices.data', 1)
                ->where('invoices.data.0.no', 'INV-K-CASE')
                ->where('invoices.data.0.kind', 'قضية'));
    }

    // ─────────────────────────── ٨. لون الحالة: مصدرٌ واحد ───────────────────────────

    /**
     * **كلّ حالةٍ لها لونٌ معرَّفٌ فعلاً في `babylon.css`** (خ٥).
     *
     * الصنف غير المعرَّف لا يُسقط بناءً ولا يُنتج تحذيراً — تُصيَّر الشارة بلا لون ويمرّ صامتاً.
     */
    public function test_every_invoice_status_has_a_tone_defined_in_the_stylesheet(): void
    {
        $css = file_get_contents(base_path('resources/css/babylon.css'));

        foreach (InvoiceStatus::cases() as $case) {
            $this->assertMatchesRegularExpression('/^\.'.preg_quote($case->tone(), '/').'\{/m', $css,
                "لون الحالة «{$case->value}» ({$case->tone()}) غير معرَّف في babylon.css");
        }
    }

    /** واللون الذي يكتبه الانتقال هو لون الحالة — لا مصفوفةٌ ثانية تتباعد. */
    public function test_the_transition_writes_the_status_tone(): void
    {
        $invoice = $this->invoice($this->client());

        Workflow::run(new WriteOffInvoice, $invoice, $this->admin(), ['reason' => 'تعذّر التحصيل']);

        $this->assertSame(InvoiceStatus::WrittenOff->tone(), $invoice->fresh()->tone);
    }
}
