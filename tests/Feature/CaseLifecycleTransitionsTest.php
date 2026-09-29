<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Enums\Role;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Jobs\DraftCasePleadingJob;
use App\Models\AuditLog;
use App\Models\CaseDocument;
use App\Models\CaseHearing;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseFee;
use App\Support\CaseJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **انتقالاتُ القضيّة — الدفعة ٢ من إصلاح مراحلها (2026-09-11).**
 *
 * قرارات المالك: التنفيذ يُفتح من «مغلقة» أيضاً، والأتعاب صفراً قضيّةٌ بلا أتعاب تُفعَّل
 * مباشرة. ومعها: الإغلاق والأرشفة في سجلّ التدقيق، وإعادة إسناد المحامي، والأرشيف للقراءة.
 */
class CaseLifecycleTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private function caseOf(array $attrs = []): LegalCase
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        return LegalCase::create(array_merge([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'number' => 'CASE-TR-'.uniqid(), 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'fee' => 1000, 'fee_status' => 'paid',
            'pleading_status' => 'approved',
        ], $attrs));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    // ═════ ٢.١ التنفيذ من «مغلقة» ═════

    public function test_execution_opens_from_a_closed_case_but_not_an_archived_one(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf();

        $this->actingAs($admin)->post(route('admin.cases.close', $case), ['closure_reason' => 'RULING_FINALIZED'])->assertRedirect();
        $closing = (string) $case->messages()->where('role', 'إغلاق')->value('body');
        $this->assertStringNotContainsString('وتنفيذه', $closing, 'لم يُفتح تنفيذ — فلا يُقال «وتنفيذه»');

        // الإغلاق قبل التنفيذ لم يعد يمنعه نهائياً
        $this->actingAs($admin)->post(route('admin.cases.execute', $case->fresh()))->assertRedirect();
        $this->assertTrue(Execution::where('case_id', $case->id)->exists());

        $archived = $this->caseOf(['status' => 'مؤرشفة']);
        $this->actingAs($admin)->post(route('admin.cases.execute', $archived))->assertStatus(422);
    }

    // ═════ ٢.٢ أتعابٌ صفر ═════

    public function test_a_zero_fee_activates_the_case_without_an_invoice(): void
    {
        // توليد مسودّة اللائحة وحده خارج الموضوع — وسجلّ التدقيق يمرّ بالطابور فلا يُزيَّف
        Queue::fake([DraftCasePleadingJob::class]);
        $admin = $this->admin();
        $case = $this->caseOf(['status' => 'بانتظار اعتماد الأتعاب', 'fee' => null, 'fee_status' => 'none', 'pleading_status' => 'none']);

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 0, 'lawyer_pct' => 0])->assertRedirect();

        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status, 'تُفعَّل مباشرةً — كانت تعلق بانتظار سداد فاتورةٍ بصفر');
        $this->assertSame('waived', $case->fee_status);
        $this->assertSame('pending_lawyer', $case->pleading_status);
        $this->assertFalse(Invoice::where('case_id', $case->id)->exists(), 'ولا فاتورة بصفر ريال');
        $this->assertTrue(AuditLog::where('action', 'قضية بلا أتعاب')->exists());
        // والتفعيل نفسه يجري (تدقيق 2026-09-29): كان الانتقال يضبط حالة اللائحة قبل `CaseFee::activate`
        // فيظنّها مفعّلةً سابقاً ويعود — فلا مسوّدة لائحة ولا رسالة تفعيل ولا خطّة عمل ولا سطر تدقيق
        Queue::assertPushed(DraftCasePleadingJob::class);
        $this->assertTrue($case->messages()->where('role', 'تفعيل')->exists(), 'رسالة التفعيل');
        $this->assertTrue(AuditLog::where('action', 'تفعيل القضية')->exists());
    }

    public function test_a_positive_fee_still_issues_the_invoice(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf(['status' => 'بانتظار اعتماد الأتعاب', 'fee' => null, 'fee_status' => 'none', 'pleading_status' => 'none']);

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 5000, 'lawyer_pct' => 20])->assertRedirect();

        $this->assertSame('بانتظار سداد الأتعاب', $case->fresh()->status);
        $this->assertTrue(Invoice::where('case_id', $case->id)->exists());
    }

    // ═════ ٢.٣ الإغلاق والأرشفة في التدقيق ═════

    public function test_closing_and_archiving_are_audited(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf();

        $this->actingAs($admin)->post(route('admin.cases.close', $case), ['closure_reason' => 'RULING_FINALIZED'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.cases.archive', $case))->assertRedirect();

        $this->assertTrue(AuditLog::where('action', 'إغلاق قضية')->where('auditable_ref', $case->number)->exists());
        $this->assertTrue(AuditLog::where('action', 'أرشفة قضية')->where('auditable_ref', $case->number)->exists());
    }

    // ═════ ٢.٤ إعادة إسناد المحامي ═════

    public function test_the_admin_reassigns_the_case_lawyer(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf(['status' => 'منظورة']);
        $newLawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. خالد المالكي']);

        $this->actingAs($admin)->post(route('admin.cases.lawyer', $case), ['lawyer_id' => $newLawyer->id])->assertRedirect();

        $case->refresh();
        $this->assertSame($newLawyer->id, $case->assigned_lawyer_id);
        $this->assertSame('أ. خالد المالكي', $case->assigned_lawyer);
        $this->assertTrue(AuditLog::where('action', 'إعادة إسناد قضية')->exists());

        // والمحامي الجديد يصل القضيّة، والسابق لا
        $this->actingAs($newLawyer)->get(route('lawyer.cases.show', $case))->assertOk();

        $archived = $this->caseOf(['status' => 'مؤرشفة']);
        $this->actingAs($admin)->post(route('admin.cases.lawyer', $archived), ['lawyer_id' => $newLawyer->id])->assertStatus(422);
    }

    // ═════ ٢.٥ الأرشيف للقراءة ═════

    public function test_an_archived_case_refuses_office_replies(): void
    {
        $case = $this->caseOf(['status' => 'مؤرشفة']);
        $lawyer = User::find($case->assigned_lawyer_id);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.reply', $case), ['body' => 'ردّ'])->assertStatus(422);
        $this->actingAs($employee)->post(route('employee.cases.reply', $case), ['body' => 'ردّ'])->assertStatus(422);
        $this->assertSame(0, $case->messages()->where('body', 'ردّ')->count());

        // والمغلقة يبقى للمكتب فيها الردّ لاستكمال ما بعد الإغلاق
        $closed = $this->caseOf(['status' => 'مغلقة']);
        $this->actingAs(User::find($closed->assigned_lawyer_id))->post(route('lawyer.cases.reply', $closed), ['body' => 'متابعة'])->assertSuccessful();
    }

    // ═════ ٢.٦ التدقيق المالي والقضائي (المرحلة الأولى) ═════

    public function test_a_positive_fee_is_audited(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf(['status' => 'بانتظار اعتماد الأتعاب', 'fee' => null, 'fee_status' => 'none', 'pleading_status' => 'none']);

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 5000, 'lawyer_pct' => 20])->assertRedirect();

        $this->assertTrue(AuditLog::where('action', 'تحديد أتعاب القضية')->where('auditable_ref', $case->number)->exists());
    }

    public function test_payment_and_activation_are_audited(): void
    {
        Queue::fake([DraftCasePleadingJob::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseOf([
            'user_id' => $client->id,
            'status' => 'بانتظار سداد الأتعاب',
            'fee' => 5000,
            'fee_status' => 'pending_payment',
            'pleading_status' => 'none',
        ]);
        $invoice = Invoice::create([
            'user_id' => $client->id,
            'case_id' => $case->id,
            'number' => 'INV-TEST-'.uniqid(),
            'description' => 'أتعاب قضية',
            'amount' => 5750,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 14 يوماً',
            'paid' => false,
        ]);

        CaseFee::markPaid($case, $invoice);

        $this->assertTrue(AuditLog::where('action', 'سداد كامل أتعاب القضية')->where('auditable_ref', $case->number)->exists());
        $this->assertTrue(AuditLog::where('action', 'تفعيل القضية')->where('auditable_ref', $case->number)->exists());
    }

    public function test_installment_payment_is_audited(): void
    {
        Queue::fake([DraftCasePleadingJob::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseOf([
            'user_id' => $client->id,
            'status' => 'بانتظار سداد الأتعاب',
            'fee' => 6000,
            'fee_status' => 'pending_payment',
            'pleading_status' => 'none',
        ]);
        $inv1 = Invoice::create([
            'user_id' => $client->id,
            'case_id' => $case->id,
            'number' => 'INV-INST-1',
            'description' => 'دفعة أتعاب 1',
            'installment_no' => 1,
            'amount' => 2000,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 14 يوماً',
            'paid' => false,
        ]);
        $inv2 = Invoice::create([
            'user_id' => $client->id,
            'case_id' => $case->id,
            'number' => 'INV-INST-2',
            'description' => 'دفعة أتعاب 2',
            'installment_no' => 2,
            'amount' => 2000,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 30 يوماً',
            'paid' => false,
        ]);
        $case->update(['pay_plan' => 'install', 'installments_total' => 2, 'installments_paid' => 0, 'fee_status' => 'installments']);

        // First installment paid
        $inv1->update(['paid' => true, 'status' => 'مدفوعة']);
        CaseFee::markInstallmentPaid($case);

        $this->assertTrue(AuditLog::where('action', 'سداد دفعة أتعاب')->where('auditable_ref', $case->number)->exists());
        $this->assertTrue(AuditLog::where('action', 'تفعيل القضية')->where('auditable_ref', $case->number)->exists());

        // Second installment paid
        $inv2->update(['paid' => true, 'status' => 'مدفوعة']);
        CaseFee::markInstallmentPaid($case);

        $this->assertTrue(AuditLog::where('action', 'سداد الدفعة الأخيرة واكتمال أتعاب القضية')->where('auditable_ref', $case->number)->exists());
    }

    public function test_ruling_recording_is_audited_with_before_and_after_states(): void
    {
        $case = $this->caseOf(['status' => 'منظورة', 'pleading_status' => 'approved']);
        $lawyer = User::find($case->assigned_lawyer_id);

        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), [
            'ruling' => 'حكمت المحكمة بإلزام المدعى عليه بدفع المبلغ.',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'تسجيل حكم قضائي')->where('auditable_ref', $case->number)->first();
        $this->assertNotNull($log);
        $this->assertSame(['الحالة' => 'منظورة'], $log->before_state);
        $this->assertSame(['الحالة' => 'صدر الحكم'], $log->after_state);
    }

    // ═════ ٢.٧ التحسينات المهمة (المرحلة ب) ═════

    public function test_closing_with_justified_reason_and_notes(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf();

        $this->actingAs($admin)->post(route('admin.cases.close', $case), [
            'closure_reason' => 'AMICABLE_SETTLEMENT',
            'closure_notes' => 'تم الاتفاق والصلح وسداد المبلغ ودياً خارج المحكمة.',
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('مغلقة', $case->status);
        $this->assertSame('AMICABLE_SETTLEMENT', $case->closure_reason);
        $this->assertSame('تم الاتفاق والصلح وسداد المبلغ ودياً خارج المحكمة.', $case->closure_notes);
        $this->assertNotNull($case->closed_at);

        $log = AuditLog::where('action', 'إغلاق قضية')->where('auditable_ref', $case->number)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('AMICABLE_SETTLEMENT', $log->after_state['سبب_الإغلاق'] ?? null);
    }

    public function test_admin_can_reopen_closed_case(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf();

        // Close first
        $this->actingAs($admin)->post(route('admin.cases.close', $case), [
            'closure_reason' => 'RULING_FINALIZED',
        ])->assertRedirect();
        $this->assertSame('مغلقة', $case->fresh()->status);

        // Reopen
        $this->actingAs($admin)->post(route('admin.cases.reopen', $case), [
            'reason' => 'ورود طلب تصحيح حكم من المحكمة يستوجب إعادة فتح الملف.',
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('صدر الحكم', $case->status);
        $this->assertNull($case->closure_reason);
        $this->assertNull($case->closed_at);

        $this->assertTrue(AuditLog::where('action', 'إعادة فتح قضية')->where('auditable_ref', $case->number)->exists());

        // Cannot reopen an archived case
        $archived = $this->caseOf(['status' => 'مؤرشفة']);
        $this->actingAs($admin)->post(route('admin.cases.reopen', $archived), [
            'reason' => 'محاولة فتح مؤرشفة',
        ])->assertStatus(422);
    }

    public function test_lawyer_can_correct_recorded_ruling(): void
    {
        $case = $this->caseOf(['status' => 'صدر الحكم', 'pleading_status' => 'approved', 'ruling' => 'منطوق أولي']);
        $lawyer = User::find($case->assigned_lawyer_id);

        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling.correct', $case), [
            'ruling' => 'الحكم المصحح: إلزام المدعى عليه بمبلغ 75,000 ريال.',
            'reason' => 'تصحيح خطأ حسابي مادي في مبلغ التعويض بموجب قرار الدائرة.',
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('الحكم المصحح: إلزام المدعى عليه بمبلغ 75,000 ريال.', $case->ruling);

        $log = AuditLog::where('action', 'تصحيح حكم قضائي')->where('auditable_ref', $case->number)->first();
        $this->assertNotNull($log);
        $this->assertSame(['منطوق_الحكم' => 'منطوق أولي'], $log->before_state);
        $this->assertSame('الحكم المصحح: إلزام المدعى عليه بمبلغ 75,000 ريال.', $log->after_state['منطوق_الحكم'] ?? null);

        // Cannot correct before ruling is recorded
        $activeCase = $this->caseOf(['status' => 'منظورة', 'pleading_status' => 'approved', 'assigned_lawyer_id' => $lawyer->id]);
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling.correct', $activeCase), [
            'ruling' => 'حكم مبكر',
            'reason' => 'محاولة تصحيح قبل الحكم',
        ])->assertStatus(422);
    }

    // ═════ ٢.٨ ترقية المعمارية ومحرك الرحلة (المرحلة ج) ═════

    public function test_case_status_enum_and_client_labels(): void
    {
        // 1. يطابق الكتالوج تماماً
        $expectedStatuses = array_keys(CaseJourney::STATUSES);
        $this->assertSame($expectedStatuses, CaseStatus::values());
        $this->assertCount(8, CaseStatus::cases());

        // 2. التحقق من تسميات العميل المنفصلة
        foreach (CaseStatus::cases() as $status) {
            $clientLabel = CaseJourney::clientLabel($status->value);
            $this->assertNotEmpty($clientLabel);
            $this->assertSame($status->clientLabel(), $clientLabel);
        }

        $this->assertSame('إعداد اللائحة وخطة العمل', CaseJourney::clientLabel('قيد التحضير'));
        $this->assertSame('تم الرفع — بانتظار قيد المحكمة', CaseJourney::clientLabel('بانتظار القيد'));
        $this->assertSame('منظورة في المحكمة', CaseJourney::clientLabel('منظورة'));
        $this->assertSame('صدر الحكم القضائي', CaseJourney::clientLabel('صدر الحكم'));

        // 3. تصنيفات الحالة
        $this->assertTrue(CaseStatus::AwaitingFeeApproval->isPendingFee());
        $this->assertTrue(CaseStatus::AwaitingFeePayment->isPendingFee());
        $this->assertFalse(CaseStatus::InPreparation->isPendingFee());

        $this->assertTrue(CaseStatus::InPreparation->isActive());
        $this->assertTrue(CaseStatus::AwaitingRegistration->isActive());
        $this->assertTrue(CaseStatus::InCourt->isActive());
        $this->assertFalse(CaseStatus::Judged->isActive());

        $this->assertTrue(CaseStatus::Closed->isFinal());
        $this->assertTrue(CaseStatus::Archived->isFinal());
        $this->assertFalse(CaseStatus::Judged->isFinal());
    }

    public function test_case_transitions_are_recorded_in_journey_transitions(): void
    {
        $admin = $this->admin();
        $case = $this->caseOf(['status' => 'منظورة', 'pleading_status' => 'approved']);
        $lawyer = User::find($case->assigned_lawyer_id);

        // 1. انتقال تسجيل الحكم: من منظورة إلى صدر الحكم
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), [
            'ruling' => 'حكمت المحكمة بإلزام المدعى عليه.',
        ])->assertRedirect();

        $rulingTransition = JourneyTransition::where('entity_type', 'LegalCase')
            ->where('entity_id', $case->id)
            ->where('transition', 'case.record_ruling')
            ->first();

        $this->assertNotNull($rulingTransition, 'يجب تسجيل انتقال تسجيل الحكم في journey_transitions');
        $this->assertSame('منظورة', $rulingTransition->from_state);
        $this->assertSame('صدر الحكم', $rulingTransition->to_state);
        $this->assertSame($lawyer->id, $rulingTransition->actor_id);
        $this->assertSame('حكمت المحكمة بإلزام المدعى عليه.', $rulingTransition->payload['ruling'] ?? null);
        $this->assertSame($case->number, $rulingTransition->entity_ref);

        // 2. انتقال إغلاق القضية: من صدر الحكم إلى مغلقة
        $this->actingAs($admin)->post(route('admin.cases.close', $case->fresh()), [
            'closure_reason' => 'RULING_FINALIZED',
            'closure_notes' => 'اكتسب الحكم القطعية وتم الإغلاق.',
        ])->assertRedirect();

        $closeTransition = JourneyTransition::where('entity_type', 'LegalCase')
            ->where('entity_id', $case->id)
            ->where('transition', 'case.close')
            ->first();

        $this->assertNotNull($closeTransition, 'يجب تسجيل انتقال إغلاق القضية في journey_transitions');
        $this->assertSame('صدر الحكم', $closeTransition->from_state);
        $this->assertSame('مغلقة', $closeTransition->to_state);
        $this->assertSame($admin->id, $closeTransition->actor_id);
        $this->assertSame('RULING_FINALIZED', $closeTransition->payload['closure_reason'] ?? null);

        // 3. انتقال إعادة فتح القضية: من مغلقة إلى صدر الحكم
        $this->actingAs($admin)->post(route('admin.cases.reopen', $case->fresh()), [
            'reason' => 'إعادة الفتح لأمر طارئ.',
        ])->assertRedirect();

        $reopenTransition = JourneyTransition::where('entity_type', 'LegalCase')
            ->where('entity_id', $case->id)
            ->where('transition', 'case.reopen')
            ->first();

        $this->assertNotNull($reopenTransition, 'يجب تسجيل انتقال إعادة فتح القضية في journey_transitions');
        $this->assertSame('مغلقة', $reopenTransition->from_state);
        $this->assertSame('صدر الحكم', $reopenTransition->to_state);
        $this->assertSame($admin->id, $reopenTransition->actor_id);
        $this->assertSame('إعادة الفتح لأمر طارئ.', $reopenTransition->payload['reason'] ?? null);

        // 4. إغلاقها مجدداً ثم أرشفتها: من مغلقة إلى مؤرشفة
        $this->actingAs($admin)->post(route('admin.cases.close', $case->fresh()), [
            'closure_reason' => 'RULING_FINALIZED',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.cases.archive', $case->fresh()))->assertRedirect();

        $archiveTransition = JourneyTransition::where('entity_type', 'LegalCase')
            ->where('entity_id', $case->id)
            ->where('transition', 'case.archive')
            ->first();

        $this->assertNotNull($archiveTransition, 'يجب تسجيل انتقال أرشفة القضية في journey_transitions');
        $this->assertSame('مغلقة', $archiveTransition->from_state);
        $this->assertSame('مؤرشفة', $archiveTransition->to_state);
        $this->assertSame($admin->id, $archiveTransition->actor_id);
    }

    // ═════ ٢.٩ الإضافات الوظيفية: المذكرات والاستئناف (المرحلة د) ═════

    public function test_document_can_be_linked_to_specific_hearing(): void
    {
        Storage::fake('local');
        Queue::fake([AnalyzeCaseDocumentJob::class]);

        $case = $this->caseOf(['status' => 'منظورة', 'pleading_status' => 'approved']);
        $lawyer = User::find($case->assigned_lawyer_id);

        $hearing = CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'الجلسة الأولى — المرافعة وتقديم المذكرة الجوابية',
            'day' => now()->addDays(5)->format('Y-m-d'),
            'time' => '10:00',
            'court' => 'الدائرة التجارية الأولى',
            'status' => 'مجدولة',
        ]);

        $file = UploadedFile::fake()->create('مذكرة_جوابية.pdf', 500, 'application/pdf');

        $this->actingAs($lawyer)->post(route('lawyer.cases.attach', $case), [
            'file' => $file,
            'hearing_id' => $hearing->id,
        ])->assertRedirect();

        $doc = CaseDocument::where('case_id', $case->id)->latest('id')->first();
        $this->assertNotNull($doc);
        $this->assertSame($hearing->id, $doc->hearing_id);
        $this->assertSame('مذكرة_جوابية.pdf', $doc->name);

        // التحقق من العلاقة المتبادلة
        $this->assertTrue($hearing->documents()->where('id', $doc->id)->exists());
        $this->assertSame($hearing->title, $doc->hearing->title);

        // التحقق من تصدير البيانات للواجهة
        $data = $doc->toData($lawyer);
        $this->assertSame($hearing->id, $data['hearingId']);
        $this->assertSame($hearing->title, $data['hearingTitle']);
    }

    public function test_appeal_workflow_from_judgment_to_appeal_filing_and_ruling(): void
    {
        $case = $this->caseOf(['status' => 'منظورة', 'pleading_status' => 'approved']);
        $lawyer = User::find($case->assigned_lawyer_id);

        // 1. تسجيل الحكم الابتدائي: يفعّل حالة الاستئناف تلقائياً ويحدد مهلة 30 يوماً
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), [
            'ruling' => 'إلزام المدعى عليه بمبلغ 500,000 ريال.',
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('صدر الحكم', $case->status);
        $this->assertSame('pending_appeal', $case->appeal_status);
        $this->assertNotNull($case->appeal_deadline_at);
        $this->assertSame(now()->addDays(30)->toDateString(), $case->appeal_deadline_at->toDateString());

        // 2. تسجيل قيد طلب الاستئناف
        $this->actingAs($lawyer)->post(route('lawyer.cases.appeal', $case), [
            'appeal_request_no' => 'APP-2026-9901',
            'appeal_court' => 'محكمة الاستئناف بالرياض',
            'appeal_circuit' => 'الدائرة التجارية الاستئنافية الثالثة',
            'appeal_filed_at' => now()->toDateString(),
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('appeal_filed', $case->appeal_status);
        $this->assertSame('APP-2026-9901', $case->appeal_request_no);
        $this->assertSame('محكمة الاستئناف بالرياض', $case->appeal_court);
        $this->assertTrue(AuditLog::where('action', 'تسجيل طلب استئناف')->where('auditable_ref', $case->number)->exists());

        // 3. تسجيل حكم الاستئناف
        $this->actingAs($lawyer)->post(route('lawyer.cases.appeal.ruling', $case), [
            'appeal_ruling' => 'حكمت المحكمة بتأييد الحكم الابتدائي بجميع فقراته واكتسابه القطعية.',
            'appeal_outcome' => 'تأييد الحكم الابتدائي',
            'appeal_judged_at' => now()->toDateString(),
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame('appeal_judged', $case->appeal_status);
        $this->assertStringContainsString('تأييد الحكم الابتدائي', $case->appeal_ruling);
        $this->assertTrue(AuditLog::where('action', 'تسجيل حكم استئناف')->where('auditable_ref', $case->number)->exists());

        // 4. لا يمكن قيد استئناف على قضية لم يُسجل حكمها بعد
        $activeCase = $this->caseOf(['status' => 'منظورة', 'assigned_lawyer_id' => $lawyer->id]);
        $this->actingAs($lawyer)->post(route('lawyer.cases.appeal', $activeCase), [
            'appeal_request_no' => 'APP-INVALID',
            'appeal_court' => 'استئناف',
            'appeal_circuit' => 'دائرة',
            'appeal_filed_at' => now()->toDateString(),
        ])->assertStatus(422);
    }
}
