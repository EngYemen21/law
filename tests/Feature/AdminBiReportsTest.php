<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\ClosureExecReasonCode;
use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Finance\RevenueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBiReportsTest extends TestCase
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

    public function test_bi_reports_aggregates_tickets_cases_and_executions_accurately(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        // 1. تذاكر: 1 محولة لقضية، 1 مغلقة بسبب، 1 قيد التحليل
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'TCK-001',
            'type' => 'تجاري',
            'department' => 'القسم التجاري',
            'status' => TicketStatus::ConvertedToCase->value,
            'is_frozen' => true,
        ]);
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'TCK-002',
            'type' => 'تجاري',
            'department' => 'القسم التجاري',
            'status' => TicketStatus::Closed->value,
            'closure_reason_code' => ClosureReasonCode::SettledAmicably->value,
            'is_frozen' => true,
        ]);
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'TCK-003',
            'type' => 'عمالي',
            'department' => 'القسم العمالي',
            'status' => 'قيد التحليل',
            'is_frozen' => false,
        ]);

        // 2. قضايا: 1 نشطة، 1 صدر فيها حكم ومستأنفة
        LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CAS-001',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'status' => CaseStatus::InCourt->value,
        ]);
        LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CAS-002',
            'type' => 'نزاع عمالي',
            'department' => 'القسم العمالي',
            'status' => CaseStatus::Judged->value,
            'appeal_status' => 'appealed',
        ]);

        // 3. تنفيذ: 1 قيد إجراءات المحكمة، 1 منتهي ومحصل
        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-001',
            'subject' => 'سند لأمر تجاري',
            'stage' => 8,
            'status' => ExecutionStatus::InProgress->value,
            'amount' => 50000,
            'collected' => 20000,
        ]);
        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-002',
            'subject' => 'شيك بدون رصيد',
            'stage' => 9,
            'status' => ExecutionStatus::Closed->value,
            'closed_reason' => ClosureExecReasonCode::FullSettlement->value,
            'amount' => 30000,
            'collected' => 30000,
        ]);

        // 4. حركات انتقال
        JourneyTransition::create([
            'entity_type' => Ticket::class,
            'entity_id' => 1,
            'entity_ref' => 'TCK-001',
            'transition' => 'ticket.convert_to_case',
            'from_state' => 'جاهزة لاتخاذ القرار',
            'to_state' => TicketStatus::ConvertedToCase->value,
            'actor_id' => $admin->id,
        ]);

        $res = $this->actingAs($admin)->get(route('admin.reports'));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->component('admin/reports')
            ->where('stats.totalTickets', 3)
            ->where('stats.convertedToCase', 1)
            ->where('stats.conversionRate', 33) // 1 / 3
            ->where('stats.totalCases', 2)
            ->where('stats.activeCases', 1)
            ->where('stats.ruledCases', 1)
            ->where('stats.caseRulingRate', 50) // 1 / 2
            ->where('stats.appealedCases', 1)
            ->where('stats.totalExecutions', 2)
            ->where('stats.activeExecutions', 1)
            ->where('stats.completedExecutions', 1)
            ->where('stats.totalDebtEnforced', 80000)
            ->where('stats.totalCollectedDebts', 50000)
            ->where('stats.collectionSuccessRate', 63) // 50000 / 80000 = 62.5 -> 63
            ->where('stats.totalTransitions', 1)
            ->has('byClosureReason')
            ->has('executionsByStage')
            ->has('recentTransitions', 1));
    }

    /**
     * **الحارس الذي كان سيكشف العطل: إجمالي الدخل = مجموع الفواتير المدفوعة بالضبط.**
     *
     * استشارةٌ مسدَّدة وقضيّةٌ مسدَّدة وتنفيذٌ مسدَّد معاً — وكان `totalFirmGross` يجمع
     * إيراد الاستشارات من `consults` + كلّ فاتورةٍ مدفوعة + أتعاب التنفيذ من `executions`،
     * وهي مجموعاتٌ متقاطعة: فالاستشارة تُعَدّ مرّتين والتنفيذ مرّتين، ونسبةُ التحصيل تُحسب
     * حساباً بلا فاتورةٍ ولا سداد. الرقم قبل الإصلاح 16,336 والفواتير المدفوعة 11,818.
     */
    public function test_total_income_equals_the_sum_of_paid_invoices_exactly(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        // ١) استشارة مسدَّدة بفاتورتها
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-INC-1', 'subject' => 'استشارة تجارية',
            'type' => 'عام', 'channel' => 'مرئية', 'lawyer' => 'أ. سعد',
            'price' => 450, 'vat' => 68, 'total' => 518, 'paid_at' => now(),
        ]);
        Invoice::create([
            'user_id' => $client->id, 'consult_id' => $consult->id, 'number' => 'INV-INC-C',
            'description' => 'استشارة', 'amount' => 518, 'status' => 'مدفوعة', 'tone' => 'b-green',
            'due_label' => '—', 'paid' => true,
        ]);

        // ٢) قضيّة مسدَّدة أتعابها
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'C-INC-1', 'title' => 'قضية', 'court' => 'المحكمة',
            'type' => 'قضية تجارية', 'status' => 'قيد التحضير', 'tone' => 'b-blue', 'fee_status' => 'paid',
        ]);
        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-INC-L',
            'description' => 'أتعاب قضية', 'amount' => 9000, 'status' => 'مدفوعة', 'tone' => 'b-green',
            'due_label' => '—', 'paid' => true,
        ]);

        // ٣) تنفيذٌ بأتعابٍ ثابتة مسدَّدة — الفاتورة والمبلغ على `executions` وجهان لمالٍ واحد
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-INC-1', 'subject' => 'حكم تجاري',
            'fee_mode' => 'fixed', 'fee' => 2000, 'vat' => 300, 'paid' => true,
            'amount' => 100000, 'collected' => 40000,
        ]);
        Invoice::create([
            'user_id' => $client->id, 'exec_id' => $exec->id, 'number' => 'INV-INC-E',
            'description' => 'أتعاب تنفيذ', 'amount' => 2300, 'status' => 'مدفوعة', 'tone' => 'b-green',
            'due_label' => '—', 'paid' => true,
        ]);

        // ٤) تنفيذٌ بنسبةٍ من المحصَّل بلا فاتورةٍ ولا سداد — كان يدخل الدخل حساباً (20000×10%)
        Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-INC-2', 'subject' => 'سند لأمر',
            'fee_mode' => 'percent', 'collection_fee_pct' => 10.0, 'amount' => 50000, 'collected' => 20000,
        ]);

        $paidInvoices = (int) Invoice::where('paid', true)->sum('amount');
        $this->assertSame(11818, $paidInvoices);

        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/revenue')
                // لا أكثر ولا أقلّ — كان 16,336 (518 + 11,818 + 4,000)
                ->where('totalIncome', $paidInvoices)
                // والتصنيف تقسيمٌ للمجموع نفسه لا مصادرُ تُجمَع
                ->where('consultIncome', 518)
                ->where('caseIncome', 9000)
                ->where('execIncome', 2300)
                ->where('otherIncome', 0)
                // ومؤشّرات التنفيذ باقية خارج الدخل: محسوبةٌ لا محصَّلة
                ->where('execFixedFees', 2000)
                ->where('execPercentFees', 2000)
                ->where('totalDebtEnforced', 150000)
                ->where('totalCollectedDebts', 60000)
                ->where('collectionRate', 40));
    }

    /** ومجموع الأقسام يساوي الإجمالي بحكم البناء — حتى مع فاتورةٍ لا ترتبط بملفّ. */
    public function test_income_split_always_adds_up_to_the_total(): void
    {
        $client = $this->client();
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-ORPH', 'description' => 'رسوم متفرّقة',
            'amount' => 700, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => '—', 'paid' => true,
        ]);

        $snap = RevenueSnapshot::build();

        $this->assertSame(700, $snap->totalIncome);
        $this->assertSame(700, $snap->otherIncome);
        $this->assertSame(
            $snap->totalIncome,
            $snap->consultIncome + $snap->caseIncome + $snap->execIncome + $snap->otherIncome
        );
    }

    /**
     * **الفاتورة الملغاة ليست ذمّةً على أحد.**
     * كان «الصادر» يجمعها و«الذمم» = الصادر − المحصَّل، فتبقى منفوخةً بمبلغ كلّ ملغاة.
     */
    public function test_cancelled_invoices_leave_both_issued_and_due_untouched(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-CNL-PAID', 'description' => 'أتعاب',
            'amount' => 4000, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => '—', 'paid' => true,
        ]);
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-CNL-DUE', 'description' => 'أتعاب',
            'amount' => 1000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-CNL-X', 'description' => 'أتعاب أُلغيت بإعادة التسعير',
            'amount' => 6516, 'status' => 'ملغاة', 'tone' => 'b-grey', 'due_label' => '—', 'paid' => false,
        ]);

        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/revenue')
                ->where('issued', 5000)   // كان 11,516
                ->where('due', 1000)      // كان 7,516
                ->where('totalIncome', 4000));
    }

    /**
     * **حارس المصدر الواحد:** الشاشة وتقرير PDF يقرآن اللقطة نفسها، فلا يفترقان.
     * كان الحساب مكتوباً مرّتين حرفيّاً — فإصلاح أحدهما يترك الآخر يكذب.
     */
    public function test_screen_and_pdf_report_show_the_same_income_figure(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PDF-1', 'description' => 'أتعاب',
            'amount' => 12345, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => '—', 'paid' => true,
        ]);
        // تنفيذٌ بأتعاب محسوبة بلا فاتورة — كان التقرير يضيفها للدخل وحده دون الشاشة (أو العكس)،
        // فلولا وجودُه لَما كشف الحارسُ افتراقَ الحسابين
        Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-PDF-1', 'subject' => 'سند',
            'fee_mode' => 'percent', 'collection_fee_pct' => 10.0, 'amount' => 50000, 'collected' => 20000,
        ]);

        $screen = null;
        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertOk()
            ->assertInertia(function ($p) use (&$screen) {
                $screen = $p->toArray()['props']['totalIncome'];
            });

        $printed = RevenueSnapshot::build()->printCellRows()[0][0][1];

        $this->assertSame(12345, $screen);
        $this->assertSame(number_format($screen).' ر.س', $printed);

        // والتقرير نفسه يخرج PDF سليماً بهذه الأرقام
        $pdf = $this->actingAs($admin)->get(route('admin.revenue.pdf'));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    /** المشهد التاريخيّ للعطل كما كان مكتوباً — بأرقامه الصحيحة الآن. */
    public function test_bi_revenue_counts_each_riyal_once(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        // 1. استشارة مدفوعة
        Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-100',
            'subject' => 'استشارة تجارية',
            'type' => 'عام',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سعد المحامي',
            'day' => 'الأحد',
            'time' => '10:00 ص',
            'total' => 1000,
            'paid_at' => now(),
        ]);

        // 2. فواتير قضايا: 5000 صادرة، 3000 محصلة
        Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-100',
            'description' => 'أتعاب قضية',
            'amount' => 3000,
            'due_label' => 'اليوم',
            'paid' => true,
        ]);
        Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-101',
            'description' => 'أتعاب قضية',
            'amount' => 2000,
            'due_label' => 'خلال 14 يوماً',
            'paid' => false,
        ]);

        // 3. أتعاب تنفيذ: أتعاب ثابتة 4000 مسددة + نسبة تحصيل
        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-100',
            'subject' => 'حكم تجاري صادر',
            'fee_mode' => 'fixed',
            'fee' => 4000,
            'paid' => true,
            'amount' => 100000,
            'collected' => 40000,
        ]);
        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-101',
            'subject' => 'سند لأمر',
            'fee_mode' => 'percent',
            'collection_fee_pct' => 10.0,
            'amount' => 50000,
            'collected' => 20000, // 10% of 20000 = 2000
        ]);

        // 4. موظف براتب ثابت
        User::factory()->create([
            'role' => Role::Employee,
            'salary' => 5000,
            'status' => 'active',
        ]);

        // **كان المتوقَّع:** totalFirmGross = 1000 (استشارة) + 3000 (فواتير) + 6000 (تنفيذ) = 10,000،
        // وnetCashFlow = 10,000 − 5,000. والفواتير المدفوعة في هذا المشهد **3,000 فقط**.
        // والاستشارة هنا مسدَّدة بلا فاتورة: خللُ بياناتٍ يُعلَن بعدده ولا يُحسب مالاً.

        $res = $this->actingAs($admin)->get(route('admin.revenue'));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->component('admin/revenue')
            ->where('totalIncome', 3000)
            ->where('consultIncome', 0)
            ->where('otherIncome', 3000)
            ->where('bookings', 1)
            ->where('unbilledPaidConsults', 1)
            ->where('issued', 5000)
            ->where('due', 2000)
            ->where('execFixedFees', 4000)
            ->where('execPercentFees', 2000)
            ->where('totalDebtEnforced', 150000)
            ->where('totalCollectedDebts', 60000)
            ->where('collectionRate', 40) // 60000 / 150000 = 40%
            ->where('salaryTotal', 5000)
            ->missing('netCashFlow'));   // حُذف: رواتب شهرٍ من إيراد العمر كلّه
    }
}
