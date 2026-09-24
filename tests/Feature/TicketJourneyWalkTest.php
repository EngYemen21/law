<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\TicketJourney;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سير رحلة التذكرة من أوّلها إلى آخرها عبر الأدوار الأربعة، مرحلةً مرحلة (قرار المالك 2026-09-14):
 *
 *   التحليل ← المستندات ← إحالة للمستشار ← اعتماد المستشار ← اعتماد الإدارة (الرأي القانوني)
 *   ← طلب العميل ← التسعير والسداد ← اقتراح الموظّف للموعد ← اعتماد الإدارة (موعد مؤكد)
 *   ← الجلسة ← ملخّصها: اعتماد المستشار ← اعتماد الإدارة ⇒ مكتملة
 *
 * يتحقّق من أن كل بوّابة تعمل، وأن الفهرس المعروض يتقدّم فعلياً ولا يقفز ولا يرتدّ،
 * وأن كلّ خطوةٍ ليست للموظّف تُردّ عليه.
 */
class TicketJourneyWalkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function stageOf(Ticket $t): int
    {
        return TicketJourney::indexOf($t->fresh()->status);
    }

    public function test_ticket_walks_every_stage_in_order(): void
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::all());
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::all());
        $admin = User::factory()->create(['role' => Role::Admin]);

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

        // ── المرحلة 2: إحالة للمستشار ← اعتماده ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
        $this->assertSame(2, $this->stageOf($ticket));

        // ── البوّابة: الموظف لا يتجاوز اعتماد المستشار ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertStatus(422);
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status, 'لا يتقدّم الموظف بلا اعتماد');

        // ── المستشار يعتمد الملخّص ← يُرفع للإدارة، ولا يصل العميلَ شيء ──
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket), [
            'case_summary' => 'ملخّص محرّر من المستشار بعد مراجعة الملف.',
            'key_points' => '• الرأي القانوني المبدئي بعد المراجعة.',
        ])->assertRedirect();
        $this->assertSame('بانتظار اعتماد الإدارة للملخّص', $ticket->fresh()->status);
        $this->assertSame(2, $this->stageOf($ticket));
        $this->assertFalse($ticket->fresh()->messages->contains(fn ($m) => $m->who === 'lawyer'), 'الرأي لا يُنشر قبل الإدارة');

        // ── المرحلة 3: الإدارة تعتمد ← الرأي القانوني ──
        $this->actingAs($admin)->post(route('admin.summary.approve', $ticket))->assertRedirect();
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
        $this->assertSame(3, $this->stageOf($ticket));

        // ── البوّابة: طلب الاستشارة زرٌّ مستقلّ لا «مرحلة تالية» ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertStatus(422);
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);

        // ── المرحلة 4: العميل يطلب ← التسعير ← السداد ──
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'phone'])->assertNoContent();
        $this->assertSame('بانتظار حجز الاستشارة', $ticket->fresh()->status);
        $this->assertSame(4, $this->stageOf($ticket));

        $consult = $ticket->consults()->latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 350])->assertRedirect();
        $this->assertTrue(ConsultBooking::markPaid($consult->fresh()));
        $this->assertSame('بانتظار تحديد الموعد', $ticket->fresh()->status);
        $this->assertSame(4, $this->stageOf($ticket));

        // ── البوّابة: العميل لا يختار موعده ──
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'date' => now()->addDays(3)->toDateString(), 'time' => '11:00',
        ])->assertStatus(422);

        // ── الموظّف يقترح ← الإدارة تعتمد ← موعد مؤكد ──
        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'phone', 'lawyer_id' => $lawyer->id,
            'date' => now()->addDays(3)->toDateString(), 'time' => '11:00',
            'ticket_no' => $ticket->number,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('بانتظار تحديد الموعد', $ticket->fresh()->status, 'الاقتراح لا يُعلن موعداً');

        $this->actingAs($admin)->post(route('admin.consults.appointment.approve', $consult))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
        $this->assertSame(5, $this->stageOf($ticket));

        // ── الجلسة تُعقد ثمّ تُختم ← بانتظار ملخّص الجلسة ──
        $consult->refresh()->forceFill(['starts_at' => now()->addMinutes(5)])->save();
        $this->actingAs($lawyer)->post(route('lawyer.consults.start', $consult))->assertRedirect();
        $this->assertSame('جلسة جارية', $consult->fresh()->session);

        $this->actingAs($lawyer)->post(route('lawyer.consults.end', $consult))->assertRedirect();
        $this->assertSame('منتهية', $consult->fresh()->session);
        $this->assertSame('بانتظار ملخّص الجلسة', $ticket->fresh()->status);
        $this->assertSame(5, $this->stageOf($ticket));

        // ── البوّابة: الموظّف لا يُعلن نتيجة الجلسة ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertStatus(422);

        // ── المستشار يكتب ملخّص الجلسة ويعتمده ← يُرفع للإدارة ──
        $text = 'ملخّص الجلسة: نوقشت المستحقات، ويُرسل إنذارٌ خطّيّ للطرف الآخر خلال أسبوع.';
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary' => $text])->assertRedirect();
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary/approve")->assertRedirect();
        $this->assertNull($consult->fresh()->summary_approved_at, 'اعتماد المستشار لا ينشر');
        $this->assertSame('بانتظار ملخّص الجلسة', $ticket->fresh()->status);

        // ── المرحلة 6: الإدارة تعتمد ← بانتظار قرار المآل، بالنصّ نفسه في المكانين ──
        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();
        $this->assertSame(TicketStatus::ReadyForOutcome->value, $ticket->fresh()->status);
        $this->assertSame(6, $this->stageOf($ticket));

        $card = $ticket->fresh()->messages->first(fn ($m) => $m->role === 'النتيجة');
        $this->assertNotNull($card, 'بطاقة النتيجة في المحادثة');
        $this->assertStringContainsString($text, $card->body);
        $this->assertSame($text, $consult->fresh()->toClientCard()['summary'], 'و«استشاراتي» بالنصّ نفسه');

        // ── اتخاذ قرار المآل: المحامي يقترح إغلاق التذكرة بسبب مبرر وتعتمدها الإدارة ──
        $this->actingAs($lawyer)->post(route('lawyer.tickets.track.propose', $ticket), [
            'track' => TicketOutcomeTrack::Close->value,
            'reason' => 'تم تقديم الرأي القانوني الوافي ولا حاجة لإجراء قضائي إضافي.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Close->value,
            'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
            'reason' => 'تم تقديم الرأي القانوني الوافي ولا حاجة لإجراء قضائي إضافي.',
        ])->assertRedirect();
        $this->assertSame(TicketStatus::Closed->value, $ticket->fresh()->status);
        $this->assertTrue(TicketJourney::isLast($ticket->fresh()->status), 'الرحلة اكتملت وأُغلقت');

        // ── بعد الإغلاق لا تقدّم إضافي ──
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertStatus(422);
        $this->assertSame(TicketStatus::Closed->value, $ticket->fresh()->status);
    }
}
