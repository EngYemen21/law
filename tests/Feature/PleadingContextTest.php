<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\CasePleading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **اللائحة تُولَّد من ملفّ القضيّة كلّه منذ بدايته.** (طلب المالك 2026-09-11)
 *
 * كان سياق النموذج: ملخّص التذكرة (موسوماً «المعتمدة» أيّاً كانت حالته) وبيانات الخصم ومستندات
 * القضيّة **المحلَّلة وحدها**. فسقطت: مرفقات الطلب قبل التحويل كلّها، ومستندات القضيّة التي لم
 * يكتمل تحليلها صامتةً، وما كتبه العميل في محادثة القضيّة، وموضوع الطلب. وملفٌّ بلا وقائع
 * كان النموذج يسرد له نزاعاً من عنده.
 */
class PleadingContextTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(
                ['draft' => 'لائحة دعوى تجريبيّة.', 'claims' => [], 'unsupported_claims' => []],
                JSON_UNESCAPED_UNICODE
            )]]]]],
        ], 200)]);
    }

    private function sent(): string
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'لم يُرسَل طلب');

        return (string) json_encode($recorded[0][0]->data(), JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0: LegalCase, 1: Ticket} */
    private function fullFile(string $summaryStatus = 'draft'): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-CTX-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'فسخ عقد توريد والتعويض عن التأخير', 'status' => 'مكتملة',
        ]);
        TicketSummary::create(['ticket_id' => $ticket->id, 'case_summary' => 'تأخّر المورّد في التسليم ستّين يوماً.', 'status' => $summaryStatus]);
        $ticket->documents()->create(['name' => 'عقد.pdf', 'path' => 'ticket-docs/a.pdf', 'doc_type' => 'عقد توريد', 'summary' => 'عقد توريد مؤرّخ يلزم بالتسليم خلال ثلاثين يوماً.']);
        $ticket->documents()->create(['name' => 'صورة.jpg', 'path' => 'ticket-docs/b.jpg']); // لم يُحلَّل

        $case = LegalCase::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-CTX-'.uniqid(),
            'type' => 'نزاع تجاري', 'department' => 'تجاري', 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
        $case->documents()->create(['name' => 'فاتورة.pdf', 'path' => 'case-docs/c.pdf', 'doc_type' => 'فاتورة', 'summary' => 'فاتورة بمبلغ مئة ألف ريال لم تُسدَّد.']);
        $case->documents()->create(['name' => 'مراسلة.pdf', 'path' => 'case-docs/d.pdf']); // لم يُحلَّل
        $case->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => '<p>المورّد أقرّ بالتأخير في رسالة بريد إلكتروني.</p>']);
        $case->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 مراسلة.pdf</span></div>']);

        return [$case, $ticket];
    }

    public function test_the_model_receives_the_whole_file_since_its_beginning(): void
    {
        $this->fakeProvider();
        [$case] = $this->fullFile();

        $result = app(LegalAiService::class)->draftPleadingResult($case);
        $sent = $this->sent();

        $this->assertStringContainsString('فسخ عقد توريد والتعويض عن التأخير', $sent, 'موضوع الطلب');
        $this->assertStringContainsString('عقد توريد مؤرّخ يلزم بالتسليم', $sent, 'مرفق الطلب قبل التحويل');
        $this->assertStringContainsString('فاتورة بمبلغ مئة ألف ريال', $sent, 'مستند القضيّة');
        $this->assertStringContainsString('المورّد أقرّ بالتأخير', $sent, 'ما كتبه العميل في محادثة القضيّة');
        $this->assertStringNotContainsString('تم إرفاق مستند', $sent, 'رسالة الإرفاق وحدها لا تُعدّ واقعة');
        $this->assertStringContainsString('مستندات لم يكتمل تحليلها بعد: 2', $sent);

        // الوسم صادق: ملخّصٌ لم يُعتمد لا يُسمّى «المعتمدة»
        $this->assertStringContainsString('ملخّصٌ لم يُعتمد بعد', $sent);
        $this->assertStringNotContainsString('وقائع وبيانات الملف (المعتمدة)', $sent);

        // والنقص يُعلَن في المسودّة ويمنع اعتمادها حتى يُعالَج
        $this->assertStringContainsString('⚠️ 2 من مستندات الملف لم يكتمل تحليلها', $result['draft']);
    }

    public function test_an_approved_summary_is_labelled_approved(): void
    {
        $this->fakeProvider();
        [$case] = $this->fullFile('approved');

        app(LegalAiService::class)->draftPleadingResult($case);

        $this->assertStringContainsString('وقائع وبيانات الملف (المعتمدة)', $this->sent());
    }

    public function test_a_file_without_facts_gets_a_template_and_a_blocking_warning(): void
    {
        $this->fakeProvider();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => User::factory()->create(['role' => Role::Lawyer])->id,
            'number' => 'CASE-EMPTY-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => 'قيد التحضير',
            'tone' => 'b-blue', 'pleading_status' => 'pending_lawyer',
        ]);

        $result = app(LegalAiService::class)->draftPleadingResult($case);

        $this->assertStringContainsString('لا وقائع مسجّلة في الملف بعد', $this->sent(), 'يُقال للنموذج ألّا يسرد وقائع');
        $this->assertStringContainsString('⚠️ الملف بلا وقائع مسجّلة', $result['draft']);

        // ولا يُعتمد قالبٌ بلا وقائع
        $case->messages()->create(['who' => 'ai', 'name' => 'x', 'role' => CasePleading::DRAFT_ROLE, 'body' => e($result['draft']), 'withheld_at' => now()]);
        $this->assertNotNull(CasePleading::blockReason($case));
    }

    public function test_escaped_and_code_markup_never_reaches_the_pleading(): void
    {
        $text = LegalAiService::humanizeDraft(
            "```json\nلائحة دعوى\\nالوقائع: &quot;التوريد&quot; \\u0627\\u0644\\u0645\\u0648\\u0631\\u062f\n* الطلب الأوّل\n`كود`\n```",
            []
        );

        $this->assertStringNotContainsString('```', $text);
        $this->assertStringNotContainsString('`', $text);
        $this->assertStringNotContainsString('&quot;', $text);
        $this->assertStringNotContainsString('\\n', $text, 'لا «\\n» حرفية');
        $this->assertStringNotContainsString('\\u', $text);
        $this->assertStringContainsString("لائحة دعوى\nالوقائع: \"التوريد\" المورد", $text);
        $this->assertStringContainsString('• الطلب الأوّل', $text);
    }
}
