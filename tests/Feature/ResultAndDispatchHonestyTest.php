<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\TicketResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صدق بطاقة النتيجة.
 *
 * بطاقة النتيجة المعتمدة كانت تحمل بندين ثابتين دائماً — «تنفيذ التوصيات أعلاه —
 * المسؤول: [اسم المحامي]» و«متابعة المهلة النظامية ثم التصعيد» — يُسندان مهمّةً إلى
 * محامٍ بالاسم بلا سجلّ، ويَعِدان بمتابعة مهلةٍ لم تُحسب.
 *
 * (كان للملفّ شقٌّ ثانٍ يحرس صدق إثبات الإرسال في محوّل المخاطبات الخارجيّ، وذهب مع
 * إزالة وحدة المخاطبات كاملةً بقرار المالك 2026-09-20.)
 */
class ResultAndDispatchHonestyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Ticket, 1:?TicketSummary} */
    private function file(array $summaryFields = []): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. المستشار']);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-RES-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'مكتملة', 'tone' => 'b-green',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $summary = $summaryFields === [] ? null : TicketSummary::create(array_merge([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.',
            'status' => 'approved', 'result_status' => 'approved', 'ai_generated' => true,
        ], $summaryFields));

        return [$ticket, $summary];
    }

    /** لا مهمّة تُسند إلى محامٍ بالاسم بلا سجلّ لها. */
    public function test_the_result_card_never_assigns_a_task_to_a_named_lawyer(): void
    {
        [$ticket, $summary] = $this->file(['facts' => 'وقائع.', 'key_points' => 'توصية حقيقيّة.']);

        $card = TicketResult::card($ticket, $summary);

        $this->assertStringNotContainsString('تنفيذ التوصيات أعلاه', $card);
        $this->assertStringNotContainsString('المسؤول:', $card);
        $this->assertStringNotContainsString('أ. المستشار', $card, 'لا تُنسب مهمّة إلى محامٍ لم يُسندها');
    }

    /** ولا وعدَ بمتابعة مهلة لم تُحسب ولا بتصعيدٍ لم يُقرَّر. */
    public function test_the_result_card_makes_no_procedural_promise(): void
    {
        [$ticket, $summary] = $this->file(['facts' => 'وقائع.', 'key_points' => 'توصية.']);

        $this->assertStringNotContainsString(
            'متابعة المهلة النظامية ثم التصعيد',
            TicketResult::card($ticket, $summary)
        );
    }

    /** وحقلٌ فارغ يُعلن غيابه لا يُملأ بتوصيةٍ قالبيّة. */
    public function test_an_empty_field_announces_its_absence(): void
    {
        [$ticket, $summary] = $this->file(['facts' => '', 'key_points' => '']);

        $card = TicketResult::card($ticket, $summary);
        $text = TicketResult::compose($ticket, $summary);

        foreach ([$card, $text] as $out) {
            $this->assertStringNotContainsString('اتخاذ الإجراء النظامي الأنسب بعد الدراسة', $out);
            $this->assertStringContainsString(TicketResult::NO_RECOMMENDATIONS, $out);
        }
    }

    /** وما كتبه المحامي فعلاً يُعرض كما هو. */
    public function test_real_recommendations_are_shown_verbatim(): void
    {
        [$ticket, $summary] = $this->file([
            'facts' => 'تأخّر المورّد عن التسليم.',
            'key_points' => 'يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.',
        ]);

        $card = TicketResult::card($ticket, $summary);

        $this->assertStringContainsString('يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.', $card);
        $this->assertStringNotContainsString(TicketResult::NO_RECOMMENDATIONS, $card);
    }
}
