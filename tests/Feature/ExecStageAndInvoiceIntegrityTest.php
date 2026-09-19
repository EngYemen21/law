<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\CaseFee;
use App\Support\ExecFee;
use App\Support\ExecService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **لا رجوعَ بالمرحلة، ولا عرضَ بلا صاحب، ولا فاتورتين عن قبولٍ واحد، ولا خطّةَ قضيّةٍ بلا وسم.**
 *
 * أربعةُ أعطالٍ يجمعها أن أثرها مالٌ أو موضعٌ في التدفّق لا رسالةٌ على شاشة:
 * دراسةٌ متأخّرة تسحب ملفّاً أُحيل فيختفي من قائمة الالتقاط، واعتمادُ أتعابٍ على ملفٍّ
 * بلا محامٍ، وقبولٌ مُعاد يُصدر فاتورةً ثانية تبقى ديناً أبديّاً، وخطّةُ تقسيطِ قضيّةٍ
 * تُقدَّم بفاتورةٍ تكميليّة لا بدفعةٍ منها.
 */
class ExecStageAndInvoiceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(Role $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        return $user;
    }

    private function exec(array $attrs = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-INT-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'stage' => 2,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ], $attrs));
    }

    // ── ب‑١: الدراسة الناقصة لا تُرجع ملفّاً أُحيل ──

    /**
     * السيناريو الحقيقيّ: العميل يقدّم (1) ⇒ الموظّف يضغط «إحالة» فتصير 2 ⇒ تعمل مهمّة
     * التحليل وفيها نقص ⇒ كانت `sync($exec, 1)` تُرجع الملفّ إلى «تحليل ذكي»، **فيختفي من
     * قائمة التقاط المحامين** (غير المسنَد يُعرض من `stage >= 2`).
     */
    public function test_an_incomplete_study_never_drags_a_referred_file_back_out_of_pickup(): void
    {
        $exec = $this->exec(['stage' => 2, 'last_action' => 'أُحيل الطلب لقسم التنفيذ']);
        $lawyer = $this->staff(Role::Lawyer);

        ExecService::applyAnalysis($exec, [
            'summary' => 'دراسةٌ رصدت نقصاً.', 'missing' => ['أصل السند'], 'procedures' => [],
            'source' => AiSource::AiSuccess->value,
        ]);

        $exec->refresh();
        $this->assertSame(2, (int) $exec->stage, 'رجع الملفّ إلى «تحليل ذكي» بعد إحالته');
        $this->assertSame('أُحيل الطلب لقسم التنفيذ', $exec->last_action);
        $this->assertSame('قيد الدراسة', $exec->status);
        $this->assertNotEmpty($exec->ai_summary, 'والحقول تُخزَّن رغم ذلك');

        // ويبقى في قائمة الالتقاط: هو أثرُ العطل الحقيقيّ لا سطرُ الحالة
        $this->actingAs($lawyer)->get(route('lawyer.execs'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('execs', 1));
    }

    /** والتعذّر مثله: مخرجٌ فارغ من المزوّد لا يسحب ملفّاً أُحيل. */
    public function test_a_failed_study_never_drags_a_referred_file_back(): void
    {
        $exec = $this->exec(['stage' => 2, 'last_action' => 'أُحيل الطلب لقسم التنفيذ']);

        ExecService::applyAnalysis($exec, [
            'summary' => '', 'missing' => [], 'procedures' => [],
            'source' => AiSource::Fallback->value,
        ]);

        $this->assertSame(2, (int) $exec->fresh()->stage);
        $this->assertFalse(
            UserNotification::where('user_id', $exec->user_id)->where('body', 'like', '%قيد المراجعة%')->exists(),
            'تراجعت حالةُ الملفّ في عين العميل بعد إحالته'
        );
    }

    /** ⚠️ والمرحلة 1 تبقى متحرّكةً إلى 2 عند اكتمال الدراسة — وهو طريقها الثاني إليها. */
    public function test_stage_one_still_advances_to_study_when_the_analysis_is_complete(): void
    {
        $exec = $this->exec(['stage' => 1, 'status' => 'تحليل ذكي', 'last_action' => 'فتح الطلب']);

        ExecService::applyAnalysis($exec, [
            'summary' => 'السند مستوفٍ.', 'missing' => [], 'procedures' => ['قيد الطلب'],
            'source' => AiSource::AiSuccess->value,
        ]);

        $this->assertSame(2, (int) $exec->fresh()->stage);
        $this->assertSame('اكتمل التحليل الذكيّ — بانتظار الدراسة', $exec->fresh()->last_action);
    }

    // ── ب‑٣: لا اعتمادَ أتعابٍ على ملفٍّ بلا محامٍ مسنَد ──

    /**
     * `approveFee` كانت وحدها بلا `guardAssigned` بين ثلاثة مسارات تكتب الأتعاب — **وهي
     * تقبل `$adjustedFee` وتكتبه في `fee`**: فيصل العميلَ عرضٌ بمبلغٍ جديد على ملفٍّ لا
     * محاميَ له ولا من يُسأل عن تقدير مدّته.
     */
    public function test_approving_a_fee_on_an_unassigned_file_is_refused(): void
    {
        $exec = $this->exec(['stage' => 4, 'decision' => 'مقبول', 'fee' => 5000, 'vat' => 750]);
        $admin = $this->staff(Role::Admin);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'approveFee', 'fee' => 9000,
        ])->assertStatus(422);

        $exec->refresh();
        $this->assertFalse((bool) $exec->fee_approved);
        $this->assertSame(5000, (int) $exec->fee, 'كُتب المبلغ المعدَّل على ملفٍّ بلا محامٍ');
        $this->assertSame(4, (int) $exec->stage);
        $this->assertFalse(
            UserNotification::where('user_id', $exec->user_id)->where('body', 'like', '%عرض خدمة التنفيذ%')->exists()
        );
    }

    /** ومع الإسناد يمرّ الاعتماد كما كان — الحارس يسدّ باباً ولا يقفل المسار. */
    public function test_approving_a_fee_on_an_assigned_file_still_works(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec([
            'stage' => 4, 'decision' => 'مقبول', 'fee' => 5000, 'vat' => 750,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($this->staff(Role::Admin))->post(route('exec-flow.act', $exec), [
            'action' => 'approveFee', 'fee' => 9000,
        ])->assertRedirect();

        $exec->refresh();
        $this->assertTrue((bool) $exec->fee_approved);
        $this->assertSame(9000, (int) $exec->fee);
        $this->assertSame(5, (int) $exec->stage);
    }

    // ── ب‑٤: قبولٌ مُعاد لا يُصدر فاتورةً ثانية ──

    /**
     * `openOnAcceptance` كانت تغلّف الإنشاء بمعاملةٍ **بلا قفلٍ ولا إعادة فحص**: نقرةٌ
     * مزدوجة أو إعادة إرسال POST تُصدر فاتورتين كاملتين كلٌّ بـ`installment_no = 1`،
     * فتبقى الثانية ديناً أبديّاً يدخل «غير المسدَّد» ثمّ «المتأخّر» في المحاسبة.
     */
    public function test_accepting_an_offer_twice_issues_exactly_one_invoice(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec([
            'stage' => 5, 'status' => 'عرض الخدمة', 'tone' => 'b-amber',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 6000, 'vat' => 900, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ]);

        ExecFee::openOnAcceptance($exec);
        ExecFee::openOnAcceptance($exec);   // نقرةٌ ثانية / إعادة إرسال POST

        $invoices = Invoice::where('exec_id', $exec->id)->get();
        $this->assertCount(1, $invoices, 'صدرت فاتورتان عن قبولٍ واحد');
        $this->assertSame(6900, (int) $invoices->first()->amount);
        $this->assertSame(1, (int) $invoices->first()->installment_no);

        $exec->refresh();
        $this->assertSame(6, (int) $exec->stage);
        $this->assertSame($invoices->first()->number, $exec->invoice_no);
        $this->assertSame(1, (int) $exec->installments_total);
    }

    /** والمسار الكامل مثله: العميل يضغط «قبول العرض» مرّتين فلا تصدر إلا واحدة. */
    public function test_the_client_route_cannot_issue_a_second_invoice(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec([
            'stage' => 5, 'status' => 'عرض الخدمة', 'tone' => 'b-amber',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 6000, 'vat' => 900, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ]);
        $client = User::find($exec->user_id);

        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'acceptOffer'])->assertRedirect();
        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'acceptOffer']);

        $this->assertSame(1, Invoice::where('exec_id', $exec->id)->count());
    }

    // ── ب‑٥: خطّة تقسيط القضيّة تُوسَم وتُعدّ بوسمها ──

    private function payableCase(int $fee = 9000): LegalCase
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-INT-'.uniqid(), 'type' => 'تجاري',
            'title' => 'قضية', 'court' => 'المحكمة',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => $fee, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-INT-'.uniqid(),
            'description' => 'أتعاب القضية', 'amount' => $fee, 'status' => 'مستحقة',
            'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false,
        ]);

        return $case->fresh();
    }

    /** دفعات الخطّة تُوسَم بمواضعها — كما هوجرت فواتير التنفيذ. */
    public function test_a_case_plan_tags_every_installment_with_its_position(): void
    {
        $case = $this->payableCase(9000);

        CaseFee::openInstallmentPlan($case);

        $plan = Invoice::where('case_id', $case->id)->orderBy('id')->get();
        $this->assertSame([1, 2, 3], $plan->pluck('installment_no')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(9000, (int) $plan->sum('amount'));
    }

    /**
     * **وفاتورةٌ تكميليّة لا تقدّم الخطّة.** العدّ كان على كلّ مدفوعةٍ على القضيّة، فسدادُ
     * فاتورةٍ خارج الخطّة يُقرأ دفعةً — ومع ثلاثٍ يُنهي الأتعاب بلا سدادها.
     */
    public function test_a_supplementary_invoice_never_advances_the_case_plan(): void
    {
        $case = $this->payableCase(9000);
        CaseFee::openInstallmentPlan($case);
        $case->refresh();

        $extra = Invoice::create([
            'user_id' => $case->user_id, 'case_id' => $case->id, 'number' => 'INV-EXTRA-'.uniqid(),
            'description' => 'أتعاب تكميليّة', 'amount' => 500, 'status' => 'مستحقة',
            'tone' => 'b-amber', 'due_label' => 'خلال 7 أيام', 'paid' => true,
        ]);

        CaseFee::markInstallmentPaid($case->fresh());

        $case->refresh();
        $this->assertSame(0, (int) $case->installments_paid, 'قدّمت فاتورةٌ تكميليّة الخطّةَ بلا دفعةٍ منها');
        $this->assertSame('installments', $case->fee_status);
        $this->assertSame('none', $case->pleading_status, 'فُعّلت القضية بلا سداد دفعةٍ من الخطّة');
        $this->assertTrue((bool) $extra->fresh()->paid);
    }

    /** والدفعة التالية دفعةُ خطّةٍ بترتيبها لا أقدمُ فاتورةٍ مستحقّة أيّاً كانت. */
    public function test_the_next_case_installment_ignores_invoices_outside_the_plan(): void
    {
        $case = $this->payableCase(9000);
        CaseFee::openInstallmentPlan($case);
        $case->refresh();

        // تُسدَّد الدفعة الأولى، وتصدر فاتورةٌ تكميليّة بعدها
        $first = Invoice::where('case_id', $case->id)->where('installment_no', 1)->firstOrFail();
        $first->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);
        Invoice::create([
            'user_id' => $case->user_id, 'case_id' => $case->id, 'number' => 'INV-EXTRA2-'.uniqid(),
            'description' => 'أتعاب تكميليّة', 'amount' => 500, 'status' => 'مستحقة',
            'tone' => 'b-amber', 'due_label' => 'خلال 7 أيام', 'paid' => false,
        ]);

        $next = CaseFee::nextInstallment($case->fresh());
        $this->assertNotNull($next);
        $this->assertSame(2, (int) $next->installment_no);
    }

    /** والاحتياط في `markInvoicePaid` يشطب **الأقدم** — كان يشطب التكميليّة ويترك الأتعاب. */
    public function test_the_fallback_settles_the_oldest_unpaid_invoice_not_the_newest(): void
    {
        $case = $this->payableCase(9000);
        $fee = Invoice::where('case_id', $case->id)->firstOrFail();
        $extra = Invoice::create([
            'user_id' => $case->user_id, 'case_id' => $case->id, 'number' => 'INV-EXTRA3-'.uniqid(),
            'description' => 'أتعاب تكميليّة', 'amount' => 500, 'status' => 'مستحقة',
            'tone' => 'b-amber', 'due_label' => 'خلال 7 أيام', 'paid' => false,
        ]);

        CaseFee::markInvoicePaid($case);

        $this->assertTrue((bool) $fee->fresh()->paid, 'بقيت فاتورة الأتعاب مستحقّة');
        $this->assertFalse((bool) $extra->fresh()->paid, 'شُطبت فاتورةٌ تكميليّة بلا مقابل');
    }
}
