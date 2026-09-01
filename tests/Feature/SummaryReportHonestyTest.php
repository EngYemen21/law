<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\ReportPrint;
use App\Support\SummaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صدق الوثيقة المختومة.
 *
 * تقرير «دراسة الملف والرأي القانوني» يحمل كتلة اعتماد وتوقيع مستشار وQR تحقّق، فما
 * يُكتب فيه يُقرأ إثباتاً. وكان بديلا الحقلين الفارغين يقولان «تم فحص المرفقات
 * ومطابقتها وفق نظام الإثبات السعودي» و«تم اعتماد الدراسة وإصدار التوصية بالمتابعة»
 * — واقعتان إجرائيّتان لم تقعا، تُطبعان تحت عنوانَي «فحص المرفقات والمستندات الثبوتية»
 * و«الرأي القانوني المعتمد والتوصيات».
 */
class SummaryReportHonestyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Ticket, 1:TicketSummary} */
    private function fileWith(array $summaryFields): array
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل الاختبار']);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-HON-'.uniqid(),
            'type' => 'نزاع تجاري',
            'subject' => 'مطالبة',
            'details' => 'تفاصيل الطلب.',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ]);
        $summary = TicketSummary::create(array_merge([
            'ticket_id' => $ticket->id,
            'case_summary' => 'ملخّص.',
            'attachments_summary' => '',
            'facts' => 'وقائع.',
            'key_points' => '',
            'status' => 'approved',
            'result_status' => 'pending',
            'ai_generated' => true,
        ], $summaryFields));

        return [$ticket, $summary];
    }

    private function renderedFor(array $summaryFields): string
    {
        [$ticket, $summary] = $this->fileWith($summaryFields);

        return ReportPrint::html(SummaryReport::doc($ticket, $summary, 'عميل الاختبار', 'المستشار'));
    }

    /** فحصٌ لم يقع لا يُكتب أنه وقع. */
    public function test_the_sealed_report_never_claims_an_inspection_that_did_not_happen(): void
    {
        $html = $this->renderedFor(['attachments_summary' => '']);

        $this->assertStringNotContainsString('تم فحص المرفقات ومطابقتها', $html);
        $this->assertStringNotContainsString('وفق نظام الإثبات السعودي', $html, 'ولا يُنسب الفحص إلى نظام');
        $this->assertStringContainsString(SummaryReport::NO_ATTACHMENT_REVIEW, $html, 'بل يُعلَن الغياب');
    }

    /** ورأيٌ لم يُدوَّن لا يُكتب أنه اعتُمد. */
    public function test_the_sealed_report_never_claims_an_opinion_that_was_not_written(): void
    {
        $html = $this->renderedFor(['key_points' => '']);

        $this->assertStringNotContainsString('تم اعتماد الدراسة وإصدار التوصية', $html);
        $this->assertStringContainsString(SummaryReport::NO_LEGAL_OPINION, $html);
    }

    /** وما دُوّن فعلاً يُطبع كما هو — الإصلاح لا يبتلع محتوىً صحيحاً. */
    public function test_a_written_opinion_is_printed_verbatim(): void
    {
        $html = $this->renderedFor([
            'attachments_summary' => 'فُحص عقد التوريد ومحضر الاستلام.',
            'key_points' => 'يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.',
        ]);

        $this->assertStringContainsString('فُحص عقد التوريد ومحضر الاستلام.', $html);
        $this->assertStringContainsString('يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.', $html);
        $this->assertStringNotContainsString(SummaryReport::NO_ATTACHMENT_REVIEW, $html);
        $this->assertStringNotContainsString(SummaryReport::NO_LEGAL_OPINION, $html);
    }

    /**
     * ولا يُقدَّم الملفّ «معتمداً رسمياً» ما لم يُعتمد.
     * حارسٌ مرافق: كتلة الاعتماد نفسها تقرأ `isApproved()` — لو انفصلت عن الحالة
     * لصار التقرير يختم ما لم يُختَم.
     */
    public function test_an_unapproved_file_is_not_labelled_officially_approved(): void
    {
        $html = $this->renderedFor(['status' => 'awaiting_lawyer']);

        $this->assertStringContainsString('قيد الدراسة', $html);
        $this->assertStringNotContainsString('>معتمد رسمياً<', $html);
    }
}
