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

    public function test_bi_revenue_calculates_firm_wide_gross_and_net_flow(): void
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

        // الإجمالي المتوقع:
        // bookingRevenue = 1000
        // collected = 3000
        // totalExecFees = 4000 + 2000 = 6000
        // totalFirmGross = 1000 + 3000 + 6000 = 10000
        // salaryTotal = 5000
        // netCashFlow = 10000 - 5000 = 5000

        $res = $this->actingAs($admin)->get(route('admin.revenue'));
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p->component('admin/revenue')
            ->where('bookingRevenue', 1000)
            ->where('collected', 3000)
            ->where('due', 2000)
            ->where('execFixedFees', 4000)
            ->where('execPercentFees', 2000)
            ->where('totalExecFees', 6000)
            ->where('totalDebtEnforced', 150000)
            ->where('totalCollectedDebts', 60000)
            ->where('collectionRate', 40) // 60000 / 150000 = 40%
            ->where('totalFirmGross', 10000)
            ->where('netCashFlow', 5000)
            ->where('salaryTotal', 5000));
    }
}
