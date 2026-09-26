<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Permissions;
use App\Support\WebTimeLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدَّين التقني (الدفعة هـ) — كل اختبار يمثّل خللاً كان يعمل صامتاً.
 */
class TechnicalDebtTest extends TestCase
{
    use RefreshDatabase;

    // ── مهلة التنفيذ: النداء المباشر كان يخفضها على CLI فيقتل عامل الطابور ──

    public function test_time_limit_is_untouched_on_console(): void
    {
        $before = (int) ini_get('max_execution_time');
        WebTimeLimit::raise(5); // مهلة قصيرة عمداً — يجب ألّا تُطبَّق

        $this->assertSame($before, (int) ini_get('max_execution_time'));
    }

    public function test_no_raw_set_time_limit_outside_the_helper(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'WebTimeLimit.php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), '@set_time_limit(')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'استعمل WebTimeLimit::raise بدل set_time_limit المباشر.');
    }

    // ── كل صلاحية معرَّفة يجب أن تحرس شيئاً فعلاً ──

    public function test_every_declared_permission_guards_something(): void
    {
        $declared = collect(Permissions::GROUPS)->flatten()->all();
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        preg_match_all("/permission:([^']+)'/u", $routes, $m);
        // الوسيط يقبل عدّة صلاحيات مفصولة بفاصلة (`permission:أ,ب`) — بلا التفكيك كانت
        // تُلتقط سلسلة واحدة فلا تطابق أيّاً منهما، فتُبلَّغ صلاحية محروسة فعلاً كأنها ميتة
        // (وقعت على «إجراء الجلسات المرئية»: محروسة في routes/web.php ولا ترد إلا بهذه الصيغة).
        $enforced = collect($m[1])
            ->flatMap(fn ($group) => explode(',', $group))
            ->map(fn ($permission) => trim($permission))
            ->all();

        // ما لا يُفرض بالمسارات يجب أن يُفرض بـcan() في الكود أو الواجهة
        $code = '';
        foreach (['app', 'resources/js'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $f) {
                if (in_array($f->getExtension(), ['php', 'tsx'], true)) {
                    $code .= file_get_contents($f->getPathname());
                }
            }
        }

        // والصلاحيّة المسمّاة بثابتٍ في الكتالوج تُفحص باسمه: `can(Permissions::DOWNLOAD_FILES)`
        $constants = array_flip(array_filter(
            (new \ReflectionClass(Permissions::class))->getConstants(),
            'is_string'
        ));

        $dead = array_values(array_filter(
            $declared,
            fn ($p) => ! in_array($p, $enforced, true)
                && ! str_contains($code, "can('{$p}')")
                && ! (isset($constants[$p]) && str_contains($code, "can(Permissions::{$constants[$p]})"))
        ));

        $this->assertSame([], $dead, 'صلاحيات معرَّفة لا تحرس شيئاً — تُربط أو تُحذف.');
    }

    // ── التحصيل اليدوي: كان بلا قيد دفتر وبلا حارس حالة ──

    public function test_manual_collection_writes_a_ledger_row(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-M-1', 'description' => 'أتعاب', 'amount' => 5000,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
        ]);

        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice))->assertRedirect();

        $this->assertTrue($invoice->fresh()->paid);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id, 'gateway' => 'manual', 'source_channel' => 'admin', 'amount' => 5000,
        ]);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_manual_collection_is_rejected_twice(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-M-2', 'description' => 'أتعاب', 'amount' => 5000,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
        ]);

        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice));
        // التحصيل الثاني خطأُ إدخالٍ على الفاتورة نفسها (2026-09-26) — كان «نجاحاً» يحمل رسالة خطأ فيظهر نخبان متناقضان
        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice))
            ->assertSessionHasErrors('invoice');

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    // ── approveFee كان يقبل مُدخلاً حرًّا بلا تحقق ──

    public function test_approve_fee_rejects_invalid_amount(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $execution = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-D-1', 'subject' => 'تنفيذ', 'stage' => 4,
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح', 'fee' => 1000,
        ]);

        $this->actingAs($admin)->post(route('exec-flow.act', $execution), [
            'action' => 'approveFee', 'fee' => -5000,
        ])->assertSessionHasErrors('fee');
    }
}
