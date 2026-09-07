<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\Specialties;
use App\Support\TicketAssignment;
use App\Support\TicketResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لا يُعلَن عملٌ لم يقع — في التذكرة كما في الاستشارة.**
 *
 * خمسة أعطالٍ كشفتها رحلةٌ حيّة على `SB-2026-8077`، تجمعها جملةٌ واحدة: نظامٌ يقول ما
 * لا يفعل. وكلُّها بلغت العميل أو سجلّاً ماليّاً.
 */
class TicketResultHonestyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Ticket,1:Consult,2:User} */
    private function bookedTicket(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-HON-'.uniqid(), 'type' => 'استشارة',
            'subject' => 'نزاع', 'status' => 'موعد مؤكد', 'tone' => 'b-green', 'priority' => 'متوسطة',
        ]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-HON-'.uniqid(),
            'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => 'مستشار', 'starts_at' => now()->addHours(3),
        ]);

        return [$ticket, $consult, $client];
    }

    /**
     * **الحارس الأثمن: لا محضرَ لجلسةٍ لم تُختَم.**
     *
     * وقع حرفيّاً: التذكرة انتقلت إلى «بانتظار اعتماد النتيجة» ووصل العميلَ «انعقدت
     * الجلسة»، واستشارتُه ما زالت «بانتظار الجلسة» وموعدُها بعد ثلاث ساعات — وقد سُدّد
     * ثمنها ١٠٬٣٥٠ ريالاً.
     */
    public function test_a_session_that_never_ended_cannot_be_reported(): void
    {
        [$ticket, $consult] = $this->bookedTicket();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)
            ->post(route('employee.tickets.advance', $ticket))
            ->assertStatus(422);

        $fresh = $ticket->fresh();
        $this->assertSame('موعد مؤكد', $fresh->status, 'ولا تتقدّم التذكرة');
        $this->assertSame(0, $fresh->messages()->where('body', 'like', '%انعقدت الجلسة%')->count(), 'ولا يُخبَر العميل');

        // وبعد ختمها فعلاً يمضي المسار
        $consult->forceFill(['session' => 'منتهية', 'status' => 'منتهية'])->save();
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد النتيجة', $ticket->fresh()->status);
    }

    /** **ولا اكتمالَ يُعلَن فوق متنٍ يقول إنّ الدراسة لم تقع.** */
    public function test_the_result_headline_follows_its_substance(): void
    {
        [$ticket] = $this->bookedTicket();

        $empty = TicketSummary::create(['ticket_id' => $ticket->id, 'status' => 'approved']);
        $this->assertFalse(TicketResult::hasSubstance($empty));

        $card = TicketResult::card($ticket->fresh(), $empty);
        $this->assertStringNotContainsString('تم الانتهاء من دراسة الموضوع', $card, 'عنوانُ اكتمالٍ فوق فراغ');
        $this->assertStringContainsString('لم تكتمل الدراسة', $card);

        $full = TicketSummary::create([
            'ticket_id' => $ticket->id, 'status' => 'approved',
            'facts' => '• وقائع مدوّنة', 'key_points' => '• توصية مدوّنة',
        ]);
        $this->assertTrue(TicketResult::hasSubstance($full));
        $this->assertStringContainsString('تم الانتهاء من دراسة الموضوع', TicketResult::card($ticket->fresh(), $full));
    }

    /**
     * **وحصيلةُ الجلسة تصل العميل** — كانت البطاقة تُبنى من دراسة ما قبلها وحدها.
     *
     * ويُحترم الحجب: ملخّصٌ لم يعتمده محامٍ لا يُسرَّب في بطاقة نتيجة.
     */
    public function test_the_session_outcome_reaches_the_client_once_approved(): void
    {
        [$ticket, $consult] = $this->bookedTicket();

        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id, 'status' => 'approved',
            'facts' => '• وقائع', 'key_points' => '• توصيات',
        ]);

        $consult->forceFill([
            'session' => 'منتهية', 'status' => 'منتهية',
            'summary' => 'ما دار في الجلسة: نوقشت الوقائع.',
            'decisions' => ['إرسال إنذار خطّيّ'],
        ])->save();

        // بلا اعتماد ⇒ لا يُسرَّب
        $this->assertStringNotContainsString('ما دار في الجلسة', TicketResult::card($ticket->fresh(), $summary));

        $consult->forceFill(['summary_approved_at' => now()])->save();

        $card = TicketResult::card($ticket->fresh(), $summary);
        $this->assertStringContainsString('ما دار في الجلسة', $card);
        $this->assertStringContainsString('إرسال إنذار خطّيّ', $card, 'والقرارات معه');
    }

    /**
     * **و«كل الأقسام» تطابق كلَّ قسم.**
     *
     * كانت المقارنة نصّيّةً حرفيّة، فمحامٍ عامٌّ لا يستقبل تذكرةً واحدة تلقائيّاً —
     * كلُّها تُصعَّد. و`Specialties::matches` موجودةٌ لهذا ويستعملها مساران آخران.
     */
    public function test_an_all_departments_lawyer_matches_every_specialty(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'department' => Specialties::ALL_DEPARTMENTS, 'distribution_mode' => 'auto',
        ]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-DEPT-'.uniqid(), 'type' => 'استشارة',
            'subject' => 'نزاع تجاري', 'department' => 'القضايا التجارية',
            'status' => 'قيد التحليل', 'tone' => 'b-blue', 'priority' => 'متوسطة',
        ]);

        $picked = TicketAssignment::pickLawyer($ticket, requireSpecialty: true);

        $this->assertNotNull($picked, '«كل الأقسام» قيمةٌ شاملةٌ مقصودة — لا اسمُ قسمٍ يُقارَن حرفيّاً');
        $this->assertSame($lawyer->id, $picked->id);
    }
}
