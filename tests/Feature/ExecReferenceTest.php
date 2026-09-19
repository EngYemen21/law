<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رقم ملفّ التنفيذ: داخليٌّ ومتفرّد.
 *
 * كان `random_int(70, 99).'-'.year.'-تنفيذ'` — ثلاثون قيمة في السنة على عمودٍ بلا قيد
 * تفرّد، ويُعرض في PDF والبريد وأربعة إشعارات باسم «رقم ملفّ التنفيذ». فيقرؤه العميل
 * رقماً صادراً عن وزارة العدل، ولا تكامل مع ناجز أصلاً. وقرار المالك: يُسمّى داخلياً
 * ويُضمن تفرّده.
 */
class ExecReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function paidExecution(int $i): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-'.$i.'-'.uniqid(),
            'subject' => 'تنفيذ حكم',
            'sanad' => 'حكم قضائي',
            'status' => 'السداد',
            'tone' => 'b-amber',
            'stage' => 6,
            'fee' => 1000,
        ]);
        Invoice::create([
            'user_id' => $client->id, 'exec_id' => $exec->id,
            'number' => 'INV-'.$i.'-'.uniqid(), 'description' => 'أتعاب تنفيذ', 'amount' => 1150,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال أسبوع', 'paid' => false,
        ]);

        ExecFee::settleInvoice($exec);

        return $exec->fresh();
    }

    /** مئتا ملفّ بلا تصادم — المدى القديم ثلاثون قيمة فقط. */
    public function test_the_execution_file_number_is_unique_across_the_year(): void
    {
        $numbers = [];
        for ($i = 0; $i < 200; $i++) {
            $numbers[] = $this->paidExecution($i)->exec_no;
        }

        $this->assertCount(200, array_filter($numbers), 'كلّها وُلِّدت');
        $this->assertSame(
            count($numbers),
            count(array_unique($numbers)),
            'ولا تصادم — المدى القديم كان يُصادم بعد ثلاثين'
        );
    }

    /** والرقم لا يُقدَّم بوصفه صادراً عن جهة قضائيّة. */
    public function test_the_number_is_never_presented_as_a_ministry_issued_reference(): void
    {
        $exec = $this->paidExecution(1);

        // في رسالة الملفّ
        $body = $exec->messages()->where('role', 'سداد')->latest('id')->first()?->body ?? '';
        $this->assertStringContainsString('المرجعيّ الداخليّ', $body);

        // وفي إشعار العميل
        $notice = UserNotification::where('user_id', $exec->user_id)
            ->where('body', 'like', '%سُدّدت أتعاب التنفيذ%')->latest('id')->first()?->body ?? '';
        $this->assertStringContainsString('المرجعيّ الداخليّ', $notice);

        // ونصّ العرض في PDF والبريد
        $this->assertStringContainsString(
            'الرقم المرجعيّ الداخليّ لملفّ التنفيذ',
            file_get_contents(app_path('Http/Controllers/ExecFlowController.php'))
        );
        $this->assertStringContainsString(
            'الرقم المرجعيّ الداخليّ لملفّ التنفيذ',
            file_get_contents(resource_path('views/emails/execution-event.blade.php'))
        );
    }

    /** والتسوية مرّتين لا تُغيّر الرقم — كان هذا محفوظاً ويبقى. */
    public function test_settling_twice_keeps_the_same_number(): void
    {
        $exec = $this->paidExecution(1);
        $before = $exec->exec_no;

        ExecFee::settleInvoice($exec);

        $this->assertSame($before, $exec->fresh()->exec_no);
    }
}
