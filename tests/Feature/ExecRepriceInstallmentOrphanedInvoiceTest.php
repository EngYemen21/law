<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ExecFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecRepriceInstallmentOrphanedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function execFor(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-2026-9999',
            'subject' => 'تنفيذ سند لأمر',
            'status' => ExecutionStatus::UnderStudy->value,
            'tone' => ExecutionStatus::UnderStudy->tone(),
            'stage' => ExecutionStatus::UnderStudy->stage(),
            'amount' => 100000,
        ], $attrs));
    }

    /**
     * التحقق من أن إعادة التسعير بعد اختيار التقسيط تُلغي كافة فواتير الأقساط القديمة،
     * وتُصفّر خطّة التقسيط، وتمنع الفواتير اليتيمة واختطاف الفاتورة القابلة للسداد.
     */
    public function test_repricing_after_installment_plan_cancels_all_installment_invoices_and_resets_plan(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id,
            'decision' => 'مقبول',
        ]);

        // 1. تسعير أولي بقيمة 6000 ريال من الإدارة
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee',
            'fee' => 6000,
            'feeMode' => 'fixed',
            'duration' => '30 يوم',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(5, $exec->stage);
        $this->assertTrue($exec->fee_approved);

        // 2. قبول العميل للعرض -> إصدار الفاتورة الأم والانتقال للمرحلة 6
        $this->actingAs($client)->post(route('exec-flow.act', $exec), [
            'action' => 'acceptOffer',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(6, $exec->stage);
        $this->assertSame(1, Invoice::where('exec_id', $exec->id)->count());

        // 3. العميل يختار التقسيط
        $this->actingAs($client)->post(route('exec-flow.pay', $exec), [
            'plan' => 'install',
        ]);

        $exec->refresh();
        $this->assertSame('install', $exec->pay_plan);
        $this->assertSame(3, (int) $exec->installments_total);

        // 3 فواتير أقساط نشطة
        $oldInvoices = Invoice::where('exec_id', $exec->id)->orderBy('installment_no')->get();
        $this->assertCount(3, $oldInvoices);
        foreach ($oldInvoices as $inv) {
            $this->assertFalse($inv->paid);
            $this->assertSame(InvoiceStatus::Due->value, $inv->status);
            $this->assertNull($inv->cancelled_at);
        }

        // 4. الإدارة تقوم بإعادة التسعير إلى 3000 ريال
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee',
            'fee' => 3000,
            'feeMode' => 'fixed',
            'duration' => '20 يوم',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(5, $exec->stage);
        $this->assertSame(3000, (int) $exec->fee);
        $this->assertNull($exec->pay_plan, 'يتم تصفير خطة التقسيط عند إعادة التسعير');
        $this->assertSame(1, (int) $exec->installments_total, 'يتم إعادة إجمالي الأقساط إلى 1');
        $this->assertSame(0, (int) $exec->installments_paid);
        $this->assertNull($exec->invoice_no, 'يتم تصفير رقم الفاتورة القديمة');

        // التحقق الحاسم: الفواتير الثلاث السابقة أُلغيت بالكامل ولم تعد يتيمة
        foreach ($oldInvoices as $oldInv) {
            $oldInv->refresh();
            $this->assertSame(InvoiceStatus::Cancelled->value, $oldInv->status, 'الفاتورة القديمة تحولت لملغاة');
            $this->assertNotNull($oldInv->cancelled_at, 'تم تسجيل تاريخ إلغاء الفاتورة');
        }

        // لا توجد أي فاتورة مستحقة حية في هذا الوقت
        $this->assertSame(0, Invoice::where('exec_id', $exec->id)->whereNotIn('status', [InvoiceStatus::Cancelled->value, InvoiceStatus::WrittenOff->value])->count());

        // 5. قبول العميل للعرض الجديد المعاد تسعيره
        $this->actingAs($client)->post(route('exec-flow.act', $exec), [
            'action' => 'acceptOffer',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(6, $exec->stage);

        // صدرت فاتورة واحدة جديدة فقط بالسعر الجديد (3000 + 450 ضريبة = 3450)
        $activeInvoices = Invoice::where('exec_id', $exec->id)
            ->whereNotIn('status', [InvoiceStatus::Cancelled->value, InvoiceStatus::WrittenOff->value])
            ->get();
        $this->assertCount(1, $activeInvoices);
        $newMaster = $activeInvoices->first();
        $this->assertSame(3450, (int) $newMaster->amount);
        $this->assertSame(3000, (int) $newMaster->subtotal);

        // التحقق الحاسم: دالة nextPayable تُعيد الفاتورة الجديدة وليس الفواتير القديمة الملغاة
        $nextPayable = ExecFee::nextPayable($exec);
        $this->assertNotNull($nextPayable);
        $this->assertSame($newMaster->id, $nextPayable->id, 'الفاتورة التالية القابلة للسداد هي الفاتورة الجديدة فقط');
        $this->assertSame(3450, (int) $nextPayable->amount);
    }

    /**
     * التحقق من أن رفض العميل للعرض في المرحلة 6 يُلغي فواتير التقسيط ويُعيد الطلب للمرحلة 5 لإعادة التسعير.
     */
    public function test_client_rejecting_offer_from_stage_6_cancels_invoices_and_allows_reprice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id,
            'decision' => 'مقبول',
            'stage' => 5,
            'fee' => 5000,
            'vat' => 750,
            'fee_approved' => true,
        ]);

        // قبول ثم تقسيط
        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'acceptOffer'])->assertRedirect();
        $this->actingAs($client)->post(route('exec-flow.pay', $exec), ['plan' => 'install']);

        $exec->refresh();
        $this->assertSame(6, $exec->stage);
        $this->assertSame('install', $exec->pay_plan);
        $this->assertCount(3, Invoice::where('exec_id', $exec->id)->get());

        // العميل يرفض العرض بعد التقسيط (في المرحلة 6)
        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertRedirect();

        $exec->refresh();
        $this->assertSame(5, $exec->stage);
        $this->assertSame('مرفوض', $exec->offer_status);
        $this->assertNull($exec->pay_plan);

        // كافة الفواتير ملغاة
        $unpaidActive = Invoice::where('exec_id', $exec->id)
            ->whereNotIn('status', [InvoiceStatus::Cancelled->value, InvoiceStatus::WrittenOff->value])
            ->count();
        $this->assertSame(0, $unpaidActive);

        // الإدارة تعيد التسعير الآن
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee',
            'fee' => 3500,
            'feeMode' => 'fixed',
            'duration' => '30 يوم',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(3500, (int) $exec->fee);
        $this->assertNull($exec->offer_status);
    }

    /**
     * التحقق من حظر إعادة التسعير إذا سُدّدت الأتعاب وفُتح الملف.
     */
    public function test_cannot_reprice_after_execution_is_paid_and_opened(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id,
            'decision' => 'مقبول',
            'stage' => 7,
            'paid' => true,
            'status' => 'بانتظار الرفع في ناجز',
        ]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee',
            'fee' => 4000,
            'duration' => '30 يوم',
        ])->assertSessionHasErrors('stage');
    }
}
