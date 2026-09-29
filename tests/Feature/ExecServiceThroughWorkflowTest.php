<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\ExecFee;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **آخر كتّاب مرحلة التنفيذ خارج المحرّك صاروا فيه** — المرحلة 6 بعد
 * صدور الفاتورة (`exec.accept_offer`)، وفتح الملفّ في المرحلة 7 (`exec.activate`). والنتيجة المخزّنة
 * كما كانت عبر `ExecService::sync` المحذوفة.
 */
class ExecServiceThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $extra = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => 'EXE-WF-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => ExecutionStatus::ServiceOffer->value, 'tone' => 'b-amber', 'stage' => 5, 'amount' => 50000,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => 1000, 'vat' => 150, 'fee_approved' => true, 'fee_mode' => 'fixed',
        ], $extra));
    }

    private function row(Execution $exec, string $name): ?JourneyTransition
    {
        return JourneyTransition::where('entity_type', 'Execution')->where('entity_id', $exec->id)->where('transition', $name)->first();
    }

    public function test_accepting_a_fixed_offer_moves_to_payment_with_the_same_last_action(): void
    {
        $exec = $this->offer();

        ExecService::acceptOffer($exec);

        $exec->refresh();
        $this->assertSame([6, 'السداد', 'b-amber', 'قبل العميل العرض وصدرت الفاتورة'], [(int) $exec->stage, $exec->status, $exec->tone, $exec->last_action]);
        $this->assertSame(1, Invoice::where('exec_id', $exec->id)->count());
        $this->assertNotNull($this->row($exec, 'exec.accept_offer'));
    }

    /** المرحلة 5 هي الحارس لا نصّ الحالة: عرضٌ بنصٍّ قديم يُقبل كما كان — لا 422 بعد صدور الفاتورة. */
    public function test_a_stage_five_offer_with_stale_text_is_still_accepted(): void
    {
        $exec = $this->offer(['status' => ExecutionStatus::UnderStudy->value]);

        ExecService::acceptOffer($exec);

        $this->assertSame(6, (int) $exec->fresh()->stage);
    }

    public function test_settling_the_fee_opens_the_file_once(): void
    {
        $exec = $this->offer();
        ExecService::acceptOffer($exec);
        $invoice = Invoice::where('exec_id', $exec->id)->sole();

        ExecFee::settleInvoice($exec->fresh(), $invoice);

        $exec->refresh();
        $this->assertSame([7, 'بانتظار الرفع في ناجز', 'b-green', 'سُدّدت الأتعاب — يُرفع الطلب في ناجز'], [(int) $exec->stage, $exec->status, $exec->tone, $exec->last_action]);
        $this->assertTrue((bool) $exec->paid);
        $this->assertNotNull($exec->exec_no);
        $execNo = $exec->exec_no;

        // تكرار التسوية (ويبهوك + عودة) لا يُخطئ ولا يسكّ رقماً ثانياً
        $this->assertFalse(ExecFee::openFile($exec, 'مكرّر'));
        $this->assertSame($execNo, $exec->fresh()->exec_no);
        $this->assertSame(1, JourneyTransition::where('transition', 'exec.activate')->where('entity_id', $exec->id)->count());
    }

    public function test_a_percentage_offer_opens_the_file_on_acceptance(): void
    {
        $exec = $this->offer(['fee' => 0, 'vat' => 0, 'fee_mode' => 'percent', 'collection_fee_pct' => 10]);

        ExecService::acceptOffer($exec);

        $exec->refresh();
        $this->assertSame(7, (int) $exec->stage);
        $this->assertSame('قُبل العرض بنموذج نسبة من المحصّل — يُرفع الطلب في ناجز', $exec->last_action);
        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->count());
        $this->assertNotNull($this->row($exec, 'exec.activate'));
    }
}
