<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * سلامة رحلة معالجة التذكرة:
 * - مفردات الحالة مصدرها TicketJourney وحده، وتشمل الحالة الافتراضية لكل تذكرة جديدة.
 * - نقطة تغيير الحالة ترفض ما هو خارج المفردات (كانت تقبل أي نصّ فيُبثّ ويُعرض عند مرحلة خاطئة).
 * - الموظف لا يقفز فوق بوّابات الجلسة والاعتماد وحجز العميل.
 */
class TicketJourneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const BRANCH = 'فرع الرياض';

    private function ticket(string $status, string $tone = 'b-blue'): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'SB-2026-7001', 'type' => 'تجاري', 'department' => 'القضايا التجارية',
            'branch' => self::BRANCH, 'status' => $status, 'tone' => $tone, 'attachments' => 2,
        ]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee, 'branch' => self::BRANCH]);
    }

    public function test_vocabulary_covers_every_status_the_server_produces(): void
    {
        $statuses = TicketJourney::statuses();

        // الحالة الافتراضية لعمود status — حالة كل تذكرة جديدة
        $this->assertContains('قيد الدراسة', $statuses);

        // كل مرحلة من مراحل الرحلة السبع
        foreach (TicketJourney::STAGES as $stage) {
            $this->assertContains($stage['status'], $statuses);
        }

        // حالات تكتبها المتحكّمات ودوال الدعم
        foreach (['بانتظار مستندات', 'بانتظار اعتماد المستشار', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة', 'مغلقة'] as $s) {
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

    public function test_status_endpoint_rejects_status_outside_vocabulary(): void
    {
        $ticket = $this->ticket('قيد الدراسة');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'حالة مخترعة', 'tone' => 'b-blue'])
            ->assertSessionHasErrors('status');

        $this->assertSame('قيد الدراسة', $ticket->fresh()->status);
    }

    public function test_status_endpoint_accepts_a_real_journey_status(): void
    {
        $ticket = $this->ticket('قيد الدراسة');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'قيد التحليل', 'tone' => 'b-blue'])
            ->assertNoContent();

        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
    }

    public function test_advance_does_not_skip_session_gate_from_in_progress(): void
    {
        // قيد التنفيذ في المرحلة نفسها التي فيها «موعد مؤكد» — كانت تقفز إلى «مكتملة» بلا محضر جلسة
        $ticket = $this->ticket('قيد التنفيذ');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $this->assertNotSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_same_status_always_gets_the_same_tone_whoever_writes_it(): void
    {
        // كانت الحالة نفسها تُلوَّن لونين باختلاف كاتبها: المستشار يغلق التذكرة رمادياً
        // بينما قائمة الموظف ترسل أخضر، فيظهر لونان لـ«مغلقة» في الجدول نفسه.
        // الاختبار عن النغمة لا الترتيب: نكتفي بالتبديل بين الحالات النهائية المسموح بها
        // (مكتملة ⇄ مغلقة) بعد حارسة منع إعادة الفتح.
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => self::BRANCH]);

        foreach (['مغلقة', 'مكتملة'] as $status) {
            $ticket = Ticket::create([
                'user_id' => $client->id, 'number' => 'SB-T-'.crc32($status),
                'type' => 'تجاري', 'branch' => self::BRANCH, 'assigned_lawyer_id' => $lawyer->id,
                'status' => 'مكتملة', 'tone' => 'b-green',
            ]);

            $this->actingAs($this->employee())
                ->post(route('employee.tickets.status', $ticket), ['status' => $status])
                ->assertNoContent();

            $this->assertSame(TicketJourney::toneFor($status), $ticket->fresh()->tone);
            $ticket->delete();
        }
    }

    public function test_status_endpoint_ignores_any_tone_sent_by_the_client(): void
    {
        // «مكتملة» → «مغلقة» (كلاهما مرحلة 6): انتقال مشروع، فالاختبار عن تجاهل النغمة المرسلة
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'مغلقة', 'tone' => 'b-red'])
            ->assertNoContent();

        $this->assertSame(TicketJourney::toneFor('مغلقة'), $ticket->fresh()->tone, 'النغمة تُشتقّ لا تُقبل');
    }

    public function test_broadcast_failure_does_not_abort_the_state_change(): void
    {
        // كان فشل الوصول إلى Reverb يرمي داخل الطلب فيُجهض ما بعده من كتابات:
        // اكتمل حجز فعلي (استشارة + Zoom + بطاقة) وبقيت التذكرة «بانتظار حجز الاستشارة».
        Event::listen(TicketStatusBroadcast::class, function () {
            throw new BroadcastException('Reverb unreachable');
        });

        $ticket = $this->ticket('قيد الدراسة');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'قيد التحليل', 'tone' => 'b-blue'])
            ->assertNoContent();

        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
    }

    public function test_advance_is_blocked_while_awaiting_client_booking_or_payment(): void
    {
        foreach (['بانتظار حجز الاستشارة', 'بانتظار الدفع'] as $status) {
            $ticket = $this->ticket($status, 'b-amber');

            $this->actingAs($this->employee())
                ->post(route('employee.tickets.advance', $ticket))->assertNoContent();

            $this->assertSame($status, $ticket->fresh()->status);
            $ticket->delete();
        }
    }

    // ── حارس ترتيب الانتقال: المفردات وحدها كانت تسمح بتخطّي الرحلة كلّها ──

    public function test_status_endpoint_rejects_forward_skip(): void
    {
        $ticket = $this->ticket('قيد الدراسة'); // مرحلة 1

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'مكتملة']) // مرحلة 6
            ->assertStatus(422);

        $this->assertSame('قيد الدراسة', $ticket->fresh()->status);
    }

    public function test_status_endpoint_rejects_even_single_step_forward(): void
    {
        // القائمة للتصحيح فقط — التقدّم (ولو خطوة) حصراً بزرّ «تنفيذ المرحلة التالية»
        $ticket = $this->ticket('قيد التحليل'); // 1

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'محالة للقسم القانوني']) // 2
            ->assertStatus(422);

        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
    }

    public function test_status_endpoint_allows_same_stage_alias_switch(): void
    {
        $ticket = $this->ticket('قيد التحليل'); // 1

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'بانتظار مستندات']) // 1
            ->assertNoContent();

        $this->assertSame('بانتظار مستندات', $ticket->fresh()->status);
    }

    public function test_status_endpoint_allows_backward_correction(): void
    {
        $ticket = $this->ticket('الرأي القانوني'); // 3

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'قيد التحليل']) // 1
            ->assertNoContent();

        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
    }

    public function test_forward_skip_cannot_unlock_convert_to_case(): void
    {
        $ticket = $this->ticket('قيد الدراسة');
        $employee = $this->employee();

        // محاولة القفز إلى «مكتملة» تُرفض…
        $this->actingAs($employee)
            ->post(route('employee.tickets.status', $ticket), ['status' => 'مكتملة'])
            ->assertStatus(422);

        // …فيبقى تحويل التذكرة إلى قضية مقفلاً (يشترط «مكتملة»)
        $this->actingAs($employee)
            ->post(route('employee.tickets.convert', $ticket))
            ->assertStatus(422);
    }

    // ── منع إعادة فتح التذاكر المكتملة/المغلقة ──
    // التذكرة المنصرفة نهائية: لا يمكن للموظف إعادتها لمرحلة سابقة عبر قائمة الحالة
    // (كان canTransition يسمح بأي تراجع فيُعاد تنفيذ referToLawyer فيمسح اعتماد المحامي).

    public function test_status_endpoint_rejects_reopen_from_completed(): void
    {
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'قيد التحليل'])
            ->assertStatus(422);

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_status_endpoint_rejects_reopen_from_closed(): void
    {
        $ticket = $this->ticket('مغلقة', 'b-grey');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'قيد التحليل'])
            ->assertStatus(422);

        $this->assertSame('مغلقة', $ticket->fresh()->status);
    }

    public function test_status_endpoint_allows_same_terminal_status_noop(): void
    {
        // البقاء على نفس الحالة النهائية مسموح (no-op): «مكتملة» ← «مكتملة»
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.status', $ticket), ['status' => 'مكتملة'])
            ->assertNoContent();

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_advance_is_noop_on_completed_ticket(): void
    {
        // دفاع بالعمق: حتى لو تُرِكَت الحالة أو رجعت لأي سبب، advance لا يعيد تنفيذ البوابات.
        $ticket = $this->ticket('مكتملة', 'b-green');

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }
}
