<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * سير رحلة التذكرة من أوّلها إلى آخرها عبر الأدوار الأربعة، مرحلةً مرحلة:
 * استلام الطلب ← التحليل ← الإحالة للقسم ← الرأي القانوني ← حجز الاستشارة ← الجلسة ← النتيجة
 *
 * يتحقّق من أن كل بوّابة تعمل (المستندات، اعتماد المستشار، حجز العميل، محضر الجلسة)
 * وأن الفهرس المعروض على المسار يتقدّم فعلياً ولا يقفز ولا يرتدّ.
 */
class TicketJourneyWalkTest extends TestCase
{
    use RefreshDatabase;

    private function stageOf(Ticket $t): int
    {
        return TicketJourney::indexOf($t->fresh()->status);
    }

    public function test_ticket_walks_every_stage_in_order(): void
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7700',
            'type' => 'تجاري', 'department' => 'القضايا التجارية',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'status' => 'قيد التحليل', 'tone' => 'b-blue', 'attachments' => 0,
        ]);

        // ── المرحلة 1: التحليل ──
        $this->assertSame(1, $this->stageOf($ticket), 'التذكرة الجديدة تبدأ عند التحليل');

        // ── بوّابة المستندات: بلا مرفقات لا تُحال للقسم ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار مستندات', $ticket->fresh()->status);
        $this->assertSame(1, $this->stageOf($ticket), 'البوّابة تُبقيه عند التحليل لا تُقدّمه');

        // ── العميل يرفق مستنداً ──
        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('عقد.pdf', 120, 'application/pdf'),
        ])->assertNoContent();
        $this->assertSame(1, $ticket->fresh()->attachments);

        // ── المرحلة 2: الإحالة للقسم ← تُسلّم لاعتماد المستشار ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
        $this->assertSame(2, $this->stageOf($ticket));

        // ── البوّابة: الموظف لا يتجاوز اعتماد المستشار ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status, 'لا يتقدّم الموظف بلا اعتماد');

        // ── المرحلة 3: المستشار يعتمد الملخّص ← الرأي القانوني ──
        // المستشار يحرّر الملخّص القالبي ثم يعتمده (حارس الصدق يمنع اعتماد القالب كما هو)
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket), [
            'case_summary' => 'ملخّص محرّر من المستشار بعد مراجعة الملف.',
            'key_points' => '• الرأي القانوني المبدئي بعد المراجعة.',
        ])->assertRedirect();
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
        $this->assertSame(3, $this->stageOf($ticket));

        // ── المرحلة 4: حجز الاستشارة ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار حجز الاستشارة', $ticket->fresh()->status);
        $this->assertSame(4, $this->stageOf($ticket));

        // ── البوّابة: الموظف لا يحجز نيابةً عن العميل ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار حجز الاستشارة', $ticket->fresh()->status, 'الحجز بيد العميل وحده');

        // ── المرحلة 5: العميل يحجز (طلب → تسعير → دفع → موعد) ← موعد مؤكد ──
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'phone'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();
        $pricingAdmin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($pricingAdmin)->post(route('admin.consults.price', $consult), ['price' => 350])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id,
            'date' => now()->addDays(3)->toDateString(),
            'time' => '11:00',
        ])->assertRedirect();
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status, 'حالة التذكرة تُحفظ رغم أي تعثّر في البثّ');
        $this->assertSame(5, $this->stageOf($ticket));

        // ── المرحلة 6: الموظف يعقد الجلسة ← تُرفع النتيجة للمستشار ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد النتيجة', $ticket->fresh()->status);
        $this->assertSame(5, $this->stageOf($ticket), 'ما زال عند الجلسة حتى يعتمد المستشار');

        // ── المستشار يعتمد النتيجة ← اعتماد الإدارة ──
        $this->actingAs($lawyer)->post(route('lawyer.result.approve', $ticket))->assertRedirect();
        $this->assertSame('بانتظار اعتماد الإدارة', $ticket->fresh()->status);
        $this->assertSame(6, $this->stageOf($ticket));

        // ── المرحلة 7: الإدارة تعتمد ← مكتملة ──
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertRedirect();
        $this->assertSame('مكتملة', $ticket->fresh()->status);
        $this->assertSame(6, $this->stageOf($ticket));
        $this->assertTrue(TicketJourney::isLast($ticket->fresh()->status), 'الرحلة اكتملت');

        // ── بعد الاكتمال لا تقدّم إضافي ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }
}
