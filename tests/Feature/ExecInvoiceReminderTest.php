<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **تذكير سداد فواتير التنفيذ** — الأمر كان بلا اختبارٍ واحد، وهو يعمل كلّ نصف ساعة.
 *
 * عطلان أُغلقا هنا، وكلاهما ظهر مع تعدّد الفواتير على الملفّ الواحد:
 *
 * ١. **الختم كان على الطلب** (`executions.payment_reminder_sent_at`) والمرشّح `stage = 6`،
 *    فيُلاحَق أوّلُ فاتورة ويسقط كلُّ ما استحقّ بعدها — الدفعتان 2 و3 (المرحلتان 7 و8)
 *    وفواتيرُ الأتعاب عن التحصيل (المرحلة 8).
 *
 * ٢. **والملاحقة كانت بعمر الإصدار لا بالاستحقاق** (`created_at <= now()->subDay()`). ودفعات
 *    الخطّة الثلاث تُنشأ في معاملةٍ واحدة باستحقاقات +3 و+30 و+60 يوماً، فيتلقّى العميل في
 *    اليوم الثاني ثلاثة تذكيرات، اثنان عن مالٍ لا يستحقّ قبل شهر — ولأنّ الختم لمرّةٍ
 *    واحدة، لا يُلاحَقان حين يتأخّران فعلاً. المقياس الآن `Invoice::isOverdue()` نفسه الذي
 *    تعرضه صفحة الفواتير.
 */
class ExecInvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    private function execution(array $over = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => 'EXE-REM-'.uniqid(), 'subject' => 'تنفيذ',
            'status' => 'السداد', 'tone' => 'b-amber', 'stage' => 6, 'amount' => 100000,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 6001, 'vat' => 900, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ], $over));
    }

    private function invoice(Execution $exec, array $over = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $exec->user_id, 'exec_id' => $exec->id, 'number' => 'INV-'.uniqid(),
            'description' => 'أتعاب تنفيذ', 'amount' => 6901, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => 'خلال 3 أيام', 'due_at' => now()->addDays(3)->toDateString(), 'paid' => false,
        ], $over));
    }

    private function noticesFor(int $userId): int
    {
        return UserNotification::where('user_id', $userId)->where('body', 'like', '%تذكير%')->count();
    }

    /** @return Collection<int, Invoice> */
    private function plan(Execution $exec)
    {
        return Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get();
    }

    /**
     * **الدفعة المتأخّرة وحدها تُلاحَق.** الترشيح القديم بعمر الإصدار كان يرسل ثلاثة
     * تذكيرات في اليوم الثاني — عن دفعتين تستحقّان بعد شهرٍ وشهرين.
     */
    public function test_only_the_overdue_installment_is_chased_not_the_whole_plan(): void
    {
        $exec = $this->execution();
        ExecFee::openOnAcceptance($exec);
        ExecFee::openInstallmentPlan($exec->fresh());
        $plan = $this->plan($exec);
        $this->assertCount(3, $plan);

        // مضى أسبوع: الأولى (+3 أيام) تأخّرت، والثانية (+30) والثالثة (+60) لم تستحقّا بعد
        $this->travel(7)->days();
        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(1, $this->noticesFor($exec->user_id), 'تذكيرٌ واحد للمتأخّرة وحدها');
        $this->assertNotNull($plan[0]->fresh()->reminder_sent_at);
        $this->assertNull($plan[1]->fresh()->reminder_sent_at, 'دفعةٌ تستحقّ بعد شهر لا تُلاحَق اليوم');
        $this->assertNull($plan[2]->fresh()->reminder_sent_at);
    }

    /**
     * **ومتى تأخّرت لاحقاً لُوحقت.** كان الإنذار السابق لأوانه هو الوحيد الذي تناله
     * الدفعتان، فلا يصلهما شيءٌ حين يحلّ أجلهما فعلاً.
     */
    public function test_a_later_installment_is_chased_when_its_own_date_passes(): void
    {
        $exec = $this->execution();
        ExecFee::openOnAcceptance($exec);
        ExecFee::openInstallmentPlan($exec->fresh());
        $plan = $this->plan($exec);

        $this->travel(7)->days();
        $this->artisan('exec:send-payment-reminders')->assertSuccessful();
        $this->assertSame(1, $this->noticesFor($exec->user_id));

        // بعد خمسةٍ وثلاثين يوماً تأخّرت الثانية أيضاً
        $this->travel(28)->days();
        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(2, $this->noticesFor($exec->user_id));
        $this->assertNotNull($plan[1]->fresh()->reminder_sent_at);
        $this->assertNull($plan[2]->fresh()->reminder_sent_at, 'والثالثة (+60) ما تزال في أجلها');
    }

    /** والأمر يعمل كلّ نصف ساعة، فالختم هو ما يمنع ثمانيةً وأربعين تذكيراً في اليوم. */
    public function test_running_again_sends_nothing(): void
    {
        $exec = $this->execution();
        $this->invoice($exec, ['due_at' => now()->subDays(2)->toDateString()]);

        $this->artisan('exec:send-payment-reminders')->assertSuccessful();
        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(1, $this->noticesFor($exec->user_id));
    }

    /** فاتورةٌ سُدّدت لا تُلاحَق، وفاتورةٌ في أجلها كذلك — ويومُ الاستحقاق نفسه ليس تأخّراً. */
    public function test_paid_and_not_yet_due_invoices_are_skipped(): void
    {
        $exec = $this->execution();
        $this->invoice($exec, ['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due_at' => now()->subDays(5)->toDateString()]);
        $this->invoice($exec, ['due_at' => now()->addDays(3)->toDateString()]);
        $this->invoice($exec, ['due_at' => now()->toDateString()]); // تستحقّ اليوم — حتى نهايته

        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(0, $this->noticesFor($exec->user_id));
    }

    /** فاتورة أتعابٍ عن تحصيل على ملفٍّ في المرحلة 8 — كان مرشّح `stage = 6` يُسقطها. */
    public function test_an_overdue_collection_fee_invoice_on_an_open_file_is_chased(): void
    {
        $exec = $this->execution(['stage' => 8, 'status' => 'قيد التنفيذ', 'paid' => true, 'fee_mode' => 'percent', 'collection_fee_pct' => 10]);
        $this->invoice($exec, ['amount' => 2875, 'due_label' => 'خلال 7 أيام', 'due_at' => now()->subDays(2)->toDateString()]);

        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(1, $this->noticesFor($exec->user_id));
    }

    /** والملفّ المنتهي لا يُلاحَق عليه سداد. */
    public function test_invoices_on_a_closed_file_are_skipped(): void
    {
        $exec = $this->execution(['stage' => 9, 'status' => 'مغلق']);
        $this->invoice($exec, ['due_at' => now()->subDays(5)->toDateString()]);

        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $this->assertSame(0, $this->noticesFor($exec->user_id));
        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->whereNotNull('reminder_sent_at')->count());
    }

    /** والإشعار يسمّي فاتورته: ثلاث دفعاتٍ بمبالغ مختلفة لا تُنتج ثلاث رسائل متطابقة. */
    public function test_the_notice_names_the_invoice_and_its_installment(): void
    {
        $exec = $this->execution();
        ExecFee::openOnAcceptance($exec);
        ExecFee::openInstallmentPlan($exec->fresh());
        $first = $this->plan($exec)[0];

        $this->travel(7)->days();
        $this->artisan('exec:send-payment-reminders')->assertSuccessful();

        $body = (string) UserNotification::where('user_id', $exec->user_id)->latest('id')->first()?->body;
        $this->assertStringContainsString($first->number, $body);
        $this->assertStringContainsString('الدفعة 1', $body);
        $this->assertStringContainsString(number_format((int) $first->amount), $body);
    }
}
