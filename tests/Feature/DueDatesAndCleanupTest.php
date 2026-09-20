<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الدفعة 4 — الاستحقاق الحقيقي والديون المتبقية: «متأخرة» تُشتق من due_at لا من نص جامد،
 * المهام تحفظ استحقاقها بعد الإنجاز، الفترات الماضية تُحجب خادمياً، والأرشيف يعرض التسجيل الفعلي.
 */
class DueDatesAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(User $client, array $extra = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $client->id,
            'number' => 'INV-2026-'.random_int(1000, 9999),
            'description' => 'أتعاب', 'amount' => 1000,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ], $extra));
    }

    public function test_invoice_overdue_is_derived_from_due_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $overdue = $this->invoice($client, ['due_at' => now()->subDays(2)->toDateString()]);
        $current = $this->invoice($client, ['due_at' => now()->addDays(2)->toDateString()]);
        $dueToday = $this->invoice($client, ['due_at' => now()->toDateString()]);
        $paidOld = $this->invoice($client, ['due_at' => now()->subDays(9)->toDateString(), 'paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);

        // متجاوزة الاستحقاق ⇒ «متأخرة» حمراء مشتقّة (المخزّنة «مستحقة» الصفراء لا تتحدّث)
        $this->assertTrue($overdue->isOverdue());
        $this->assertSame(['متأخرة', 'b-red'], $overdue->liveStatus());
        $this->assertSame('متأخرة', $overdue->toCard()['status']);
        $this->assertTrue($overdue->toCard()['overdue']);

        // يوم الاستحقاق نفسه ليس تأخّراً (حتى نهايته)، والمستقبلية والمدفوعة ليستا متأخرتين
        $this->assertFalse($dueToday->isOverdue());
        $this->assertFalse($current->isOverdue());
        $this->assertSame('مستحقة', $current->toCard()['status']);
        $this->assertFalse($paidOld->isOverdue());
        $this->assertSame('مدفوعة', $paidOld->toCard()['status']);
    }

    public function test_client_dashboard_splits_due_and_overdue_and_sorts_by_due_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->invoice($client, ['number' => 'INV-LATE', 'due_at' => now()->subDay()->toDateString()]);
        $this->invoice($client, ['number' => 'INV-SOON', 'due_at' => now()->addDay()->toDateString()]);
        $this->invoice($client, ['number' => 'INV-NULL', 'due_at' => null]);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p
                ->where('counts.dueInv', 3)
                ->where('counts.overdueInv', 1)
                // الأقرب استحقاقاً أولاً وبلا استحقاق آخراً — كان الترتيب بالأحدث إنشاءً
                ->where('dueInvoices.0.no', 'INV-LATE')
                ->where('dueInvoices.1.no', 'INV-SOON')
                ->where('dueInvoices.2.no', 'INV-NULL'));
    }

    public function test_accounting_overdue_counts_only_past_due(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $this->invoice($client, ['due_at' => now()->subDay()->toDateString()]);
        $this->invoice($client, ['due_at' => now()->addDays(3)->toDateString()]); // صدرت للتوّ — ليست متأخرة

        $this->actingAs($admin)->get(route('admin.finance'))
            ->assertInertia(fn ($p) => $p->where('dashboard.overdueCount', 1));
    }

    public function test_new_invoices_carry_real_due_dates(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-7777', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => '—', 'time' => '—', 'when_label' => '—',
            'session' => 'بانتظار الجلسة', 'status' => 'بانتظار التسعير',
        ]);

        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 400])->assertRedirect();

        $invoice = $consult->fresh()->invoice;
        $this->assertNotNull($invoice->due_at);
        $this->assertSame(now()->addDays(3)->toDateString(), $invoice->due_at->toDateString());
    }

    public function test_task_completion_keeps_due_and_records_completed_at(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $task = Task::create([
            'assigned_to' => $lawyer->id, 'title' => 'مذكرة', 'due' => 'خلال أسبوع',
            'due_at' => now()->subDays(3)->toDateString(), 'status' => 'مفتوحة', 'tone' => 'b-amber',
        ]);

        // قبل الإنجاز: متأخرة (تجاوزت استحقاقها) وتصطبغ حمراء
        $this->assertTrue($task->isOverdue());
        $this->assertSame('b-red', $task->toData()['tone']);

        $this->actingAs($lawyer)->post(route('lawyer.tasks.complete', $task))->assertRedirect();

        $task->refresh();
        // «مكتملة» كانت تدهس الاستحقاق الأصلي — الآن يبقى ويُختم الإنجاز بطابع حقيقي
        $this->assertSame('خلال أسبوع', $task->due);
        $this->assertNotNull($task->completed_at);
        $this->assertFalse($task->isOverdue()); // المنجزة لا تُعدّ متأخرة
    }

    public function test_lawyer_dashboard_counts_overdue_tasks(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'متأخرة', 'due_at' => now()->subDay()->toDateString(), 'status' => 'مفتوحة', 'tone' => 'b-amber']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'قادمة', 'due_at' => now()->addDay()->toDateString(), 'status' => 'مفتوحة', 'tone' => 'b-amber']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'منجزة قديمة', 'due_at' => now()->subDay()->toDateString(), 'status' => 'منجزة', 'tone' => 'b-green']);

        $this->actingAs($lawyer)->get(route('lawyer.dashboard'))
            ->assertInertia(fn ($p) => $p->where('openTasks', 2)->where('overdueTasks', 1));
    }

    public function test_availability_slots_block_past_hours_server_side(): void
    {
        // منتصف نهار يوم عمل — الفترات حتى الساعة الحالية تُعلَّم محجوزة خادمياً
        $day = LawyerAvailability::resolveDate(null);
        Carbon::setTestNow($day->copy()->setTime(13, 30));

        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $slots = collect(LawyerAvailability::slotsFor($lawyer->id, $day->toDateString()));

        $this->assertTrue($slots->firstWhere('time', '11:00')['taken']);  // ماضية
        $this->assertTrue($slots->firstWhere('time', '13:00')['taken']);  // الساعة الجارية
        $this->assertFalse($slots->firstWhere('time', '14:00')['taken']); // قادمة
    }

    public function test_meetreqs_availability_blocks_past_of_today(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(13, 30));
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $res = $this->actingAs($employee)->getJson(route('employee.meetreqs.availability', [
            'lawyer_id' => $lawyer->id, 'day' => now()->toDateString(),
        ]))->assertOk();

        // فترة حجب من بداية اليوم حتى الآن — المتصفّح بتوقيت مختلف لم يعد يفتح فترات ماضية
        $this->assertContains(['00:00', '13:30'], $res->json('busy'));

        // يوم غدٍ بلا فترة حجب
        $res2 = $this->actingAs($employee)->getJson(route('employee.meetreqs.availability', [
            'lawyer_id' => $lawyer->id, 'day' => now()->addDay()->toDateString(),
        ]))->assertOk();
        $this->assertNotContains(['00:00', '13:30'], $res2->json('busy'));
    }

    public function test_archive_lists_ended_sessions_with_real_recording(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // جلسة منتهية تحوّلت حالتها («محولة إلى قضية») — كانت تسقط من الأرشيف رغم انعقادها
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-8801', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس · 10ص',
            'session' => 'منتهية', 'status' => 'محولة إلى قضية',
            'meet_link' => 'https://zoom.us/j/dead-join-link',
            'recording_url' => 'https://zoom.us/rec/real-recording',
        ]);
        // لم تنعقد جلستها — لا مكان لها في الأرشيف
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-8802', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'محامٍ', 'day' => 'غداً', 'time' => '10ص', 'when_label' => 'غداً · 10ص',
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
        ]);

        $recorded = Consult::where('ref', 'CN-2026-8801')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.archive'))
            ->assertInertia(fn ($p) => $p
                ->has('rows', 1)
                ->where('rows.0.ref', 'CN-2026-8801')
                // التسجيل الفعلي يُشغَّل وينزَّل عبر الخادم — لا رابط الانضمام الميّت ولا رابط سحابة Zoom
                // (قرار المالك 2026-09-15: لا زرَّ يفتح صفحةً خارج النظام)
                ->where('rows.0.stream', route('admin.consults.stream', ['consult' => $recorded, 'type' => 'video'], absolute: false))
                ->where('rows.0.zip', route('admin.consults.recording', $recorded, absolute: false))
                ->missing('rows.0.recording'));
    }
}
