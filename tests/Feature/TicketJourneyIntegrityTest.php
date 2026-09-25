<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ApprovesTicketSummary;
use Tests\TestCase;

/**
 * سلامة رحلة معالجة التذكرة:
 * - مفردات الحالة مصدرها TicketJourney وحده، وتشمل الحالة الافتراضية لكل تذكرة جديدة.
 * - **لا قائمة «تغيير الحالة» بيد الموظّف** (قرار المالك 2026-09-14): كانت تقبل أيّ تبديلٍ
 *   داخل رقم المرحلة فتُكمل التذكرة متخطّيةً الإدارة (ع٥) وتنقض الإغلاق (ع٦).
 * - التصحيح الاستثنائيّ للإدارة العليا وحدها، بسببٍ مكتوبٍ يُسجَّل.
 * - فعل الموظّف الوحيد «إحالة للمستشار» — ولا يقفز فوق الجلسة ولا الاعتماد ولا الحجز.
 */
class TicketJourneyIntegrityTest extends TestCase
{
    use ApprovesTicketSummary;
    use RefreshDatabase;

    private function ticket(string $status, string $tone = 'b-blue'): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'SB-2026-'.random_int(7000, 7999), 'type' => 'تجاري', 'department' => 'القضايا التجارية',
            'status' => $status, 'tone' => $tone, 'attachments' => 2,
        ]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_vocabulary_covers_every_status_the_server_produces(): void
    {
        $statuses = TicketJourney::statuses();

        // الحالة الافتراضية لعمود status — حالة كل تذكرة جديدة
        $this->assertContains('قيد التحليل', $statuses);

        // كل مرحلة من مراحل الرحلة السبع
        foreach (TicketJourney::STAGES as $stage) {
            $this->assertContains($stage['status'], $statuses);
        }

        // حالات تكتبها المتحكّمات ودوال الدعم — ومنها حالات إعادة البناء
        foreach ([
            'بانتظار مستندات', 'بانتظار اعتماد المستشار', 'بانتظار اعتماد الإدارة للملخّص',
            'بانتظار تحديد الموعد', 'بانتظار ملخّص الجلسة', 'مغلقة',
        ] as $s) {
            $this->assertContains($s, $statuses);
        }

        // لكل حالة نغمة شارة — لا تصل الواجهة بقيمة ناقصة
        foreach (TicketJourney::options() as $o) {
            $this->assertNotEmpty($o['tone']);
        }
    }

    public function test_unknown_status_falls_back_to_first_stage(): void
    {
        // الخادم والواجهة يرتدّان إلى 0؛ اختلافهما كان يجعل الزرّ يَعِد بمرحلة غير التي تُنفَّذ
        $this->assertSame(0, TicketJourney::indexOf('حالة لا وجود لها'));
    }

    // ── الموظّف: لا تغيير يدويّ للحالة ──

    /** **الحارس الأثمن:** كلّ تبديلٍ كان بابًا خلفيًّا — فالمسار مغلقٌ للموظّف أيًّا كانت الحالة. */
    public function test_employee_status_endpoint_is_closed_for_every_move(): void
    {
        foreach ([
            ['قيد التحليل', 'قيد التحليل'],       // لا تغيير
            ['قيد التحليل', 'بانتظار مستندات'],   // داخل المرحلة
            ['قيد التحليل', 'مكتملة'],             // تخطٍّ
            ['بانتظار اعتماد الإدارة للملخّص', 'مكتملة'],  // ع٥
            ['مغلقة', 'مكتملة'],                  // ع٦
            ['مكتملة', 'قيد التحليل'],             // إعادة فتح
        ] as [$from, $to]) {
            $ticket = $this->ticket($from);

            $this->actingAs($this->employee())
                ->post(route('employee.tickets.status', $ticket), ['status' => $to, 'tone' => 'b-green'])
                ->assertForbidden();

            $this->assertSame($from, $ticket->fresh()->status, "«{$from}» ← «{$to}» مرّت من الموظّف");
            $ticket->delete();
        }
    }

    public function test_a_closed_status_menu_cannot_unlock_convert_to_case(): void
    {
        $ticket = $this->ticket('قيد التحليل');
        // ملخّصٌ معتمد كما في الرحلة الحقيقيّة — شرط قرار المآل (ث٥)
        $this->approveTicketSummary($ticket);
        $employee = $this->employee();

        // محاولة القفز إلى «مكتملة» تُصدّ…
        $this->actingAs($employee)
            ->post(route('employee.tickets.status', $ticket), ['status' => 'مكتملة'])
            ->assertForbidden();

        // المسار المباشر القديم محذوف نهائياً (ADR-009)
        $this->assertFalse(
            Route::has('employee.tickets.convert'),
            'مسار employee.tickets.convert لا يزال مسجلاً.'
        );

        // مقترح الحوكمة يقبل من حالة «قيد التحليل» لكن ينتقل لـ«بانتظار اعتماد النتيجة» — لا تحويل مباشر
        $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'رفع مقترح مسار — يحتاج اعتماد الإدارة العليا.',
            ])
            ->assertRedirect();

        $this->assertSame('بانتظار اعتماد الإدارة للمسار', $ticket->fresh()->status);
        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    // ── الإدارة: تصحيحٌ مسبَّب ──

    public function test_admin_correction_requires_a_written_reason(): void
    {
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->admin())
            ->post(route('admin.tickets.correct-status', $ticket), ['status' => 'مغلقة'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_admin_correction_rejects_a_status_outside_the_journey(): void
    {
        foreach (['حالة مخترعة', 'بانتظار اعتماد النتيجة'] as $target) { // مخترعة، وقديمةٌ محذوفة (2026-09-19)
            $ticket = $this->ticket('قيد التحليل');

            $this->actingAs($this->admin())
                ->post(route('admin.tickets.correct-status', $ticket), ['status' => $target, 'reason' => 'تصحيح خطأ إدخال سابق'])
                ->assertStatus(422);

            $this->assertSame('قيد التحليل', $ticket->fresh()->status, "«{$target}» قُبلت");
            $ticket->delete();
        }
    }

    public function test_admin_correction_rejects_the_same_status(): void
    {
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->admin())
            ->post(route('admin.tickets.correct-status', $ticket), ['status' => 'مكتملة', 'reason' => 'لا تغيير فعلي هنا'])
            ->assertStatus(422);

        $this->assertSame(0, JourneyTransition::count(), 'ولا يُسجَّل انتقالٌ لم يقع');
    }

    public function test_employee_cannot_reach_the_admin_correction(): void
    {
        $ticket = $this->ticket('مكتملة', 'b-green');

        // وسيط الدور يُعيد توجيه غير الإداريّ (عُرف المشروع) — والحارس على الأثر: لا تصحيح
        $this->actingAs($this->employee())
            ->post(route('admin.tickets.correct-status', $ticket), ['status' => 'مغلقة', 'reason' => 'محاولة من الموظّف'])
            ->assertRedirect();

        $this->assertSame('مكتملة', $ticket->fresh()->status);
        $this->assertSame(0, JourneyTransition::count());
    }

    public function test_admin_correction_is_recorded_with_its_reason(): void
    {
        $ticket = $this->ticket('مغلقة', 'b-grey');

        $this->actingAs($this->admin())
            ->post(route('admin.tickets.correct-status', $ticket), ['status' => 'مكتملة', 'reason' => 'أُغلقت خطأً قبل نشر النتيجة'])
            ->assertRedirect();

        $this->assertSame('مكتملة', $ticket->fresh()->status);

        // سجلّ الانتقال بالسبب، وملاحظةٌ داخليّة لا يراها العميل
        $row = JourneyTransition::where('transition', 'ticket.correct-status')->sole();
        $this->assertSame('مغلقة', $row->from_state);
        $this->assertSame('أُغلقت خطأً قبل نشر النتيجة', $row->reason);
        $this->assertTrue($ticket->fresh()->messages->contains(
            fn ($m) => $m->who === 'note' && str_contains($m->body, 'أُغلقت خطأً قبل نشر النتيجة')
        ));
    }

    public function test_same_status_always_gets_the_same_tone_whoever_writes_it(): void
    {
        // كانت الحالة نفسها تُلوَّن لونين باختلاف كاتبها — النغمة تُشتقّ من الحالة لا تُرسَل
        foreach (['مغلقة', 'مكتملة'] as $status) {
            $ticket = $this->ticket('قيد التحليل');

            $this->actingAs($this->admin())
                ->post(route('admin.tickets.correct-status', $ticket), ['status' => $status, 'reason' => 'تصحيح لاختبار النغمة', 'tone' => 'b-red'])
                ->assertRedirect();

            $this->assertSame(TicketJourney::toneFor($status), $ticket->fresh()->tone, 'النغمة تُشتقّ لا تُقبل');
            $ticket->delete();
        }
    }

    public function test_broadcast_failure_does_not_abort_the_state_change(): void
    {
        // كان فشل الوصول إلى Reverb يرمي داخل الطلب فيُجهض ما بعده من كتابات
        Event::listen(TicketStatusBroadcast::class, function () {
            throw new BroadcastException('Reverb unreachable');
        });

        $ticket = $this->ticket('مغلقة', 'b-grey');

        $this->actingAs($this->admin())
            ->post(route('admin.tickets.correct-status', $ticket), ['status' => 'مكتملة', 'reason' => 'تصحيح رغم تعطّل البثّ'])
            ->assertRedirect();

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    // ── «إحالة للمستشار»: فعل الموظّف الوحيد ──

    public function test_advance_does_not_skip_session_gate_from_in_progress(): void
    {
        // مرحلة الجلسة — كانت «قيد التنفيذ» (حُذفت) تقفز إلى «مكتملة» بلا محضر (ع٢١)
        $ticket = $this->ticket('بانتظار ملخّص الجلسة');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.advance', $ticket))->assertStatus(422);

        $this->assertNotSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_advance_is_refused_once_the_file_left_the_referral_stages(): void
    {
        foreach ([
            'بانتظار اعتماد المستشار', 'بانتظار اعتماد الإدارة للملخّص', 'الرأي القانوني',
            'بانتظار حجز الاستشارة', 'بانتظار تحديد الموعد',
            'موعد مؤكد', 'بانتظار ملخّص الجلسة', 'مكتملة', 'مغلقة',
        ] as $status) {
            $ticket = $this->ticket($status, 'b-amber');

            $this->actingAs($this->employee())
                ->post(route('employee.tickets.advance', $ticket))->assertStatus(422);

            $this->assertSame($status, $ticket->fresh()->status, "«{$status}» تحرّكت بإحالة الموظّف");
            $ticket->delete();
        }
    }
}
