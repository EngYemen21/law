<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * قرارات المنتج المعلّقة من فحص الأزرار (نُفّذت 2026-08-26):
 * إغلاق/إعادة إسناد مهام الإدارة · رفض إثبات التحويل · تصدير التقارير.
 */
class AdminProductGapsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    /** المهمة المسندة لغير محامٍ كانت لا تُغلق من أي شاشة — الإدارة تُغلق أي مهمة. */
    public function test_admin_completes_any_task_even_non_lawyer_assigned(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['role' => Role::Employee]);
        $task = Task::create(['assigned_to' => $employee->id, 'title' => 'مهمة عالقة', 'status' => 'مفتوحة', 'tone' => 'b-amber']);

        $this->actingAs($admin)->post(route('admin.tasks.complete', $task))->assertRedirect();

        $this->assertSame('منجزة', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_admin_reassigns_a_task_to_another_lawyer(): void
    {
        $admin = $this->admin();
        $l1 = User::factory()->create(['role' => Role::Lawyer]);
        $l2 = User::factory()->create(['role' => Role::Lawyer]);
        $task = Task::create(['assigned_to' => $l1->id, 'title' => 'مهمة', 'status' => 'مفتوحة', 'tone' => 'b-amber']);

        $this->actingAs($admin)->post(route('admin.tasks.reassign', $task), ['assigned_to' => $l2->id])->assertRedirect();

        $this->assertSame($l2->id, $task->fresh()->assigned_to);
    }

    /** رافع الملف الخاطئ كان يفقد زرّ الدفع نهائياً — الرفض يعيد الفاتورة للاستحقاق ويُشعره. */
    public function test_rejecting_proof_restores_the_invoice_to_payable(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PG-1', 'description' => 'أتعاب',
            'amount' => 900, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال أسبوع', 'paid' => false,
        ]);
        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('wrong.jpg', 40, 'image/jpeg'),
        ]);
        $path = $invoice->fresh()->proof_path;
        $this->assertNotNull($path);

        $this->actingAs($admin)->post(route('admin.invoices.proof.reject', $invoice), ['reason' => 'الملف غير واضح'])
            ->assertRedirect()->assertSessionHas('flash');

        $invoice->refresh();
        $this->assertNull($invoice->proof_path, 'الإثبات المرفوض أُزيل — زرّ الدفع يعود');
        $this->assertSame('مستحقة', $invoice->status);
        Storage::disk('local')->assertMissing($path);
        $this->assertGreaterThan(0, UserNotification::where('user_id', $client->id)->count(), 'العميل أُشعر بالسبب');
    }

    /**
     * تحويل القضية للتنفيذ من شاشة الإدارة — كان معطوباً مزدوجاً: المسار يشير لدالّة
     * غير موجودة (500 فورياً)، وجسم الدالّة ينادي createFor غير المعرَّفة.
     */
    public function test_admin_converts_a_judged_case_to_execution(): void
    {
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-PG-1', 'type' => 'تجاري',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'status' => 'صدر الحكم', 'tone' => 'b-green',
        ]);

        $this->actingAs($admin)->post(route('admin.cases.execute', $case), ['amount' => 150000])
            ->assertRedirect()->assertSessionHas('flash');

        $this->assertTrue($case->execution()->exists(), 'فُتح ملف التنفيذ للقضية');

        // غير المؤهَّلة (لها تنفيذ قائم) تُرفض بـ422 لا 500
        $this->actingAs($admin)->post(route('admin.cases.execute', $case))->assertStatus(422);
    }

    /** تصدير التقارير والإيرادات PDF — كانت الشاشتان بلا أي تصدير. */
    public function test_reports_and_revenue_export_as_pdf(): void
    {
        $admin = $this->admin();

        foreach (['admin.reports.pdf', 'admin.revenue.pdf'] as $route) {
            $res = $this->actingAs($admin)->get(route($route));
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'), $route);
            $this->assertStringStartsWith('%PDF', $res->getContent(), $route);
        }
    }
}
