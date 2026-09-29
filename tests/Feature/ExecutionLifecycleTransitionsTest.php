<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureExecReasonCode;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Execution\FileExecutionNajiz;
use App\Domain\Journey\Workflow;
use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\ExecFee;
use App\Support\ExecFlow;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsLegacyExecutions;
use Tests\TestCase;

class ExecutionLifecycleTransitionsTest extends TestCase
{
    use BuildsLegacyExecutions;
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function lawyer(string $name = 'المحامي سعد'): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'name' => $name]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_execution_status_enum_matches_catalog_and_stages(): void
    {
        $this->assertSame(0, ExecutionStatus::NewRequest->stage());
        $this->assertSame(1, ExecutionStatus::AiAnalysis->stage());
        $this->assertSame(2, ExecutionStatus::UnderStudy->stage());
        $this->assertSame(3, ExecutionStatus::FeeEstimation->stage());
        $this->assertSame(4, ExecutionStatus::AdminApproval->stage());
        $this->assertSame(5, ExecutionStatus::ServiceOffer->stage());
        $this->assertSame(6, ExecutionStatus::Payment->stage());
        $this->assertSame(7, ExecutionStatus::PendingNajiz->stage());
        $this->assertSame(8, ExecutionStatus::InProgress->stage());
        $this->assertSame(9, ExecutionStatus::Closed->stage());

        $this->assertFalse(ExecutionStatus::NewRequest->isClosed());
        $this->assertTrue(ExecutionStatus::Closed->isClosed());
        $this->assertNull(ExecutionStatus::tryFrom('مكتمل'), '«مكتمل» القديمة حُذفت (2026-09-19)');

        $this->assertSame(ExecutionStatus::NewRequest, ExecutionStatus::fromStage(0));
        $this->assertSame(ExecutionStatus::InProgress, ExecutionStatus::fromStage(8));
        $this->assertSame(ExecutionStatus::Closed, ExecutionStatus::fromStage(9));
    }

    public function test_closure_exec_reason_code_enum_has_all_official_reasons(): void
    {
        $cases = array_map(fn (ClosureExecReasonCode $c) => $c->value, ClosureExecReasonCode::cases());
        foreach (ExecFlow::CLOSE_REASONS as $reason) {
            $this->assertContains($reason, $cases);
        }
    }

    public function test_full_fixed_fee_lifecycle_creates_journey_transitions_audit_trail(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();

        // 1. التقديم — المرحلة 0
        $exec = $this->legacyExecution($client, [
            'sanad' => 'حكم قضائي',
            'subject' => 'تنفيذ مطالبة مالية عمالية',
            'defendant' => 'شركة المقاولات الحديثة',
            'amount' => 50000,
        ]);

        $this->assertSame(1, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::AiAnalysis->value, $exec->status);

        // 2. تطبيق نتائج التحليل الذكي مع اكتمال السند (advance) — المرحلة 2
        ExecService::applyAnalysis($exec, [
            'summary' => 'السند التنفيذي مكتمل ومستوفٍ للأركان.',
            'missing' => [],
            'procedures' => ['تقديم طلب تنفيذ ناجز'],
            'source' => AiSource::AiSuccess->value,
        ]);

        $exec->refresh();
        $this->assertSame(2, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::UnderStudy->value, $exec->status);

        // 3. إسناد محامٍ للملف
        ExecService::assignLawyer($exec, $lawyer, $admin);
        $exec->refresh();
        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id);

        // 4. دراسة الملف وقبوله من المحامي — الانتقال إلى المرحلة 3 (تحديد الأتعاب)
        ExecService::accept($exec, $lawyer);
        $exec->refresh();
        $this->assertSame(3, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::FeeEstimation->value, $exec->status);

        // 5. المحامي يحدد الأتعاب ويرسلها لاعتماد الإدارة — المرحلة 4 (اعتماد الإدارة)
        ExecService::saveFee($exec, 5000, '30 يوماً', 'fixed', null, $lawyer);
        $exec->refresh();
        $this->assertSame(4, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::AdminApproval->value, $exec->status);
        $this->assertSame(5000, (int) $exec->fee);

        // 6. الإدارة تعتمد الأتعاب وترسل العرض للعميل — المرحلة 5 (عرض الخدمة)
        ExecService::approveFee($exec, 5000, $admin);
        $exec->refresh();
        $this->assertSame(5, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::ServiceOffer->value, $exec->status);
        $this->assertTrue((bool) $exec->fee_approved);

        // 7. العميل يقبل العرض — المرحلة 6 (السداد)
        ExecService::acceptOffer($exec);
        $exec->refresh();
        $this->assertSame(6, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::Payment->value, $exec->status);

        // 8. سداد الأتعاب وفتح الملف — المرحلة 7 (بانتظار الرفع في ناجز)
        ExecFee::settleInvoice($exec);
        $exec->refresh();
        $this->assertSame(7, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::PendingNajiz->value, $exec->status);
        $this->assertTrue((bool) $exec->paid);

        // 9. رفع الطلب في ناجز — المرحلة 8 (قيد التنفيذ)
        ExecService::fileNajiz($exec, 'REQ-NAJIZ-101', now()->toDateString(), $lawyer);
        $exec->refresh();
        $this->assertSame(8, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::InProgress->value, $exec->status);
        $this->assertSame('REQ-NAJIZ-101', $exec->najiz_request_no);

        // 10. قيد الطلب لدى محكمة التنفيذ
        ExecService::registerNajiz($exec, 'محكمة التنفيذ بالرياض', 'الدائرة الثانية', now()->toDateString(), $lawyer);
        $exec->refresh();
        $this->assertSame('محكمة التنفيذ بالرياض', $exec->court);
        $this->assertSame('الدائرة الثانية', $exec->circuit);

        // 11. إبلاغ المنفّذ ضده
        ExecService::notifyDebtor($exec, now()->toDateString(), $lawyer);
        $exec->refresh();
        $this->assertNotNull($exec->notified_at);
        $this->assertNotNull($exec->pay_due_at);

        // 12. اتخاذ إجراءات عدم الوفاء (المادة 46)
        ExecService::applyMeasures($exec, ['منع السفر', 'إيقاف الخدمات الحكومية'], $lawyer);
        $exec->refresh();
        $this->assertContains('منع السفر', $exec->measures);

        // 13. إثبات تحصيل مبالغ
        ExecService::addCollection($exec, 25000, 'تحصيل جزئي عبر حجز الحسابات', $lawyer);
        $exec->refresh();
        $this->assertSame(25000, (int) $exec->collected);

        // 14. إغلاق ملف التنفيذ بسداد كامل
        ExecService::close($exec, 'سداد كامل', $lawyer);
        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::Closed->value, $exec->status);
        $this->assertSame('سداد كامل', $exec->closed_reason);
        $this->assertTrue($exec->isClosed());

        // التحقق من تدوين سجل الانتقالات بالكامل في journey_transitions
        $transitions = JourneyTransition::where('entity_type', 'Execution')
            ->where('entity_id', $exec->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(10, $transitions->count());

        $names = $transitions->pluck('transition')->all();
        $this->assertContains('exec.apply_analysis', $names);
        $this->assertContains('exec.assign_lawyer', $names);
        $this->assertContains('exec.study', $names);
        $this->assertContains('exec.set_fee', $names);
        $this->assertContains('exec.approve_fee', $names);
        $this->assertContains('exec.file_najiz', $names);
        $this->assertContains('exec.register_najiz', $names);
        $this->assertContains('exec.notify_debtor', $names);
        $this->assertContains('exec.apply_measures', $names);
        $this->assertContains('exec.add_collection', $names);
        $this->assertContains('exec.close', $names);
    }

    public function test_percentage_fee_mode_accept_offer_transitions_directly_to_pending_najiz(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();

        $exec = $this->legacyExecution($client, [
            'sanad' => 'سند لأمر',
            'subject' => 'تحصيل كمبيالات تجارية',
            'amount' => 100000,
        ]);

        // نقله إلى دراسة المحامي وإسناده
        ExecService::refer($exec, $admin);
        ExecService::assignLawyer($exec, $lawyer, $admin);
        ExecService::accept($exec, $lawyer);

        // تسعير بنموذج نسبة من المحصل (10%)
        ExecService::setFee($exec, 0, '45 يوماً', 'percent', 10.0, $admin);
        $exec->refresh();
        $this->assertSame(5, (int) $exec->stage);
        $this->assertSame('percent', $exec->feeMode());
        $this->assertSame(10.0, (float) $exec->collection_fee_pct);

        // العميل يقبل العرض النسبي — لا فاتورة مقدمة، والانتقال مباشرة إلى المرحلة 7
        ExecService::acceptOffer($exec);
        $exec->refresh();
        $this->assertSame(7, (int) $exec->stage);
        $this->assertSame(ExecutionStatus::PendingNajiz->value, $exec->status);
        $this->assertTrue((bool) $exec->paid);
    }

    public function test_rejected_study_cannot_be_priced_and_only_admin_can_close_it(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();

        $exec = $this->legacyExecution($client, [
            'sanad' => 'حكم قضائي',
            'subject' => 'سند تنفيذي باطل',
            'amount' => 30000,
        ]);

        ExecService::refer($exec, $admin);
        ExecService::assignLawyer($exec, $lawyer, $admin);

        // المحامي يرفض الطلب بعد دراسته
        ExecService::reject($exec, $lawyer);
        $exec->refresh();
        $this->assertSame('مرفوض', $exec->decision);
        $this->assertSame(2, (int) $exec->stage);

        // لا يمكن تسعير الطلب المرفوض
        $this->expectException(\Exception::class);
        ExecService::saveFee($exec, 3000, '30 يوماً', 'fixed', null, $lawyer);
    }

    public function test_lawyer_cannot_close_rejected_execution_but_admin_can(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();

        $exec = $this->legacyExecution($client, [
            'sanad' => 'حكم قضائي',
            'subject' => 'سند مرفوض',
            'amount' => 30000,
        ]);

        ExecService::refer($exec, $admin);
        ExecService::assignLawyer($exec, $lawyer, $admin);
        ExecService::reject($exec, $lawyer);
        $exec->refresh();

        // المحامي يحاول إغلاق الطلب المرفوض — يُرفض 403 (محصور في الإدارة)
        try {
            ExecService::close($exec, 'أخرى', $lawyer);
            $this->fail('Expected lawyer closure of rejected execution to throw 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // الإدارة تغلقه بنجاح
        ExecService::close($exec, 'أخرى', $admin);
        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertTrue($exec->isClosed());
    }

    public function test_illegal_state_jump_is_denied(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();

        $exec = $this->legacyExecution($client, [
            'sanad' => 'حكم قضائي',
            'subject' => 'قفز غير قانوني',
            'amount' => 10000,
        ]);

        $this->assertSame(1, (int) $exec->stage);

        // محاولة رفع الملف في ناجز (المرحلة 8) مباشرة من المرحلة 1
        $this->expectException(TransitionDenied::class);
        Workflow::run(new FileExecutionNajiz, $exec, $lawyer, [
            'request_no' => 'REQ-INVALID',
            'filed_at' => now()->toDateString(),
        ]);
    }
}
