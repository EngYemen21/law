<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Execution\AcceptExecutionOffer;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **انتقال «قبول عرض التنفيذ» لا يسقط بخطأٍ قاتل.**
 *
 * 🔴 كان `AcceptExecutionOffer::apply()` ينادي `ExecFee::ensureInvoice` — دالّةٌ غير معرَّفة في أيّ
 * مكان — فأيّ استعمالٍ للانتقال يُسقط الطلب. لم يظهر لأنّ الانتقال غير مستعمَل اليوم: المسار الحيّ
 * `ExecService::acceptOffer` → `ExecFee::openOnAcceptance` يُصدر الفاتورة في معاملةٍ مقفلة وحده.
 */
class AcceptExecutionOfferTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function offer(string $mode = 'fixed', array $extra = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => 'EXE-ACC-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => ExecutionStatus::ServiceOffer->value, 'tone' => 'b-amber', 'stage' => 5, 'amount' => 120000,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'fee' => $mode === 'percent' ? 0 : 1000, 'vat' => $mode === 'percent' ? 0 : 150, 'fee_approved' => true,
            'fee_mode' => $mode, 'collection_fee_pct' => $mode === 'percent' ? 10 : null,
        ], $extra));
    }

    public function test_fixed_offer_without_an_invoice_is_refused_not_fatal(): void
    {
        $exec = $this->offer('fixed');

        try {
            Workflow::run(new AcceptExecutionOffer, $exec);
            $this->fail('قُبل العرض بلا فاتورة');
        } catch (TransitionDenied $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('لم تصدر فاتورة', $e->getMessage());
        }

        $this->assertSame(ExecutionStatus::ServiceOffer->value, $exec->fresh()->status, 'الرفض لا يترك أثراً');
        $this->assertSame(0, JourneyTransition::where('transition', 'exec.accept_offer')->count());
    }

    public function test_fixed_offer_with_an_issued_invoice_moves_to_payment(): void
    {
        $exec = $this->offer('fixed', ['invoice_no' => 'INV-2026-0001']);

        Workflow::run(new AcceptExecutionOffer, $exec);

        $exec->refresh();
        $this->assertSame(ExecutionStatus::Payment->value, $exec->status);
        $this->assertSame(ExecutionStatus::Payment->stage(), (int) $exec->stage);
        $this->assertSame('مقبول', $exec->offer_status);
        $this->assertSame(1, JourneyTransition::where('transition', 'exec.accept_offer')->where('entity_id', $exec->id)->count());
    }

    public function test_percentage_offer_needs_no_invoice(): void
    {
        $exec = $this->offer('percent');

        Workflow::run(new AcceptExecutionOffer, $exec);

        $exec->refresh();
        $this->assertSame(ExecutionStatus::PendingNajiz->value, $exec->status);
        $this->assertTrue((bool) $exec->paid);
    }

    /** المسار الحيّ لم يتغيّر: فاتورةٌ واحدة، والمرحلة 6 بانتظار السداد. */
    public function test_the_live_acceptance_path_is_unchanged(): void
    {
        $exec = $this->offer('fixed');

        ExecService::acceptOffer($exec);

        $exec->refresh();
        $this->assertSame(1, Invoice::where('exec_id', $exec->id)->count());
        $this->assertNotSame('', (string) $exec->invoice_no);
        $this->assertSame(6, $exec->effectiveStage());
    }

    /**
     * **حارسٌ لكلّ الانتقالات:** كلّ نداءٍ ساكن لصنفٍ من التطبيق داخل انتقالٍ يصل دالّةً موجودة.
     * كان النداء المعطوب صامتاً لأنّ الانتقال لا يُستعمل — والحارس يكشف مثله قبل أوّل استعمال.
     */
    public function test_no_transition_calls_an_undefined_static_method(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Domain/Journey/Transitions')));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/^use\s+(App\\\\[\w\\\\]+)\s*;/m', $source, $uses);
            $imports = [];
            foreach ($uses[1] as $fqn) {
                $imports[class_basename($fqn)] = $fqn;
            }

            preg_match_all('/\b([A-Z]\w+)::([a-z]\w*)\(/', $source, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $short, $method]) {
                $fqn = $imports[$short] ?? null;
                // أصناف المنطق وحدها: النماذج والتعدادات تُحلّ نداءاتها الساكنة سحريّاً
                if ($fqn === null || ! preg_match('/^App\\\\(Support|Services|Domain\\\\Journey\\\\(?!Enums))/', $fqn)) {
                    continue;
                }
                if (! method_exists($fqn, $method)) {
                    $missing[] = $file->getFilename().": {$short}::{$method}()";
                }
            }
        }

        $this->assertSame([], $missing, 'انتقالاتٌ تنادي دوالّ غير موجودة');
    }
}
