<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\ConversationFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **شاشة القضيّة للمحامي: الملفّ كلّه أمامه.** (طلب المالك 2026-09-11)
 *
 * كانت «مستندات القضية» قائمةً مسطّحة لمستندات ما بعد التحويل وحدها، بملخّصاتٍ كاملة تطيل
 * الشريط، ورفعٍ في ذيلها. ومرفقاتُ الطلب قبل التحويل — وهي أساسُ اللائحة — غائبةٌ عن الشاشة
 * كلّها، وكذا الخصم وقيمة المطالبة والمحكمة ووقائع الملخّص.
 */
class LawyerCaseSidebarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: LegalCase, 1: User, 2: Ticket} */
    private function file(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-SIDE-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => 'مكتملة',
            'subject' => 'فسخ عقد توريد', 'opponent_name' => 'مؤسسة تجريبية', 'claim_amount' => 100000, 'court_name' => 'المحكمة التجارية بالرياض',
        ]);
        TicketSummary::create(['ticket_id' => $ticket->id, 'case_summary' => 'تأخّر المورّد ستّين يوماً.', 'facts' => 'وُقّع العقد في مارس.', 'status' => 'approved']);
        $ticket->documents()->create(['name' => 'عقد.pdf', 'path' => 'ticket-docs/a.pdf', 'doc_type' => 'عقد توريد', 'summary' => 'عقد التوريد', 'status' => 'مرتبط']);
        $ticket->documents()->create(['name' => 'صورة.jpg', 'path' => 'ticket-docs/b.jpg', 'status' => 'قيد الفحص']);

        $case = LegalCase::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-SIDE-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => 'قيد التحضير', 'tone' => 'b-blue',
            'pleading_status' => 'pending_lawyer',
        ]);
        $case->documents()->create(['name' => 'فاتورة.pdf', 'path' => 'case-docs/c.pdf', 'doc_type' => 'فاتورة', 'summary' => 'فاتورة غير مسدّدة', 'uploaded_by' => 'client', 'status' => 'محلَّل']);

        return [$case, $lawyer, $ticket];
    }

    public function test_the_lawyer_sees_the_whole_file_with_its_readiness(): void
    {
        [$case, $lawyer, $ticket] = $this->file();
        $contract = $ticket->documents()->where('name', 'عقد.pdf')->first();

        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))->assertOk()->assertInertia(fn ($p) => $p
            ->component('lawyer/case')
            // مرفقات الطلب قبل التحويل — الأحدث أوّلاً، وبرابط تنزيلٍ من المسار الموحّد
            ->has('ticketDocuments', 2)
            ->where('ticketDocuments.1.name', 'عقد.pdf')
            ->where('ticketDocuments.1.downloadUrl', ConversationFiles::url('ticket', $contract->id))
            ->where('ticketDocuments.0.summary', '')
            // بطاقة الملفّ
            ->where('fileInfo.opponent', 'مؤسسة تجريبية')
            ->where('fileInfo.claim', '100,000 ر.س')
            ->where('fileInfo.court', 'المحكمة التجارية بالرياض')
            ->where('fileInfo.ticketNo', $ticket->number)
            // وقائع الملفّ بوسم اعتمادها
            ->where('fileFacts.approved', true)
            ->where('fileFacts.facts', 'وُقّع العقد في مارس.')
            // الجاهزية: الملخّص معتمد، ومستندٌ بانتظار التحليل، ولا مسودّة بعد
            ->has('readiness', 4)
            ->where('readiness.0.ok', true)
            ->where('readiness.1.ok', false)
            ->where('readiness.1.hint', '1 بانتظار التحليل')
            ->where('readiness.2.ok', false)
        );
    }

    /**
     * **محامي القضيّة ينزّل مرفقات طلبها** وإن لم يكن محامي التذكرة — بعد إعادة الإسناد يتغيّر
     * محامي القضيّة وحده، فكان يفقد مرفقات ما قبل التحويل. ومحامٍ لا صلة له يبقى مرفوضاً.
     */
    public function test_the_case_lawyer_downloads_the_original_request_attachments(): void
    {
        Storage::fake('local');
        [$case, $lawyer, $ticket] = $this->file();
        $contract = $ticket->documents()->where('name', 'عقد.pdf')->first();
        Storage::put($contract->path, '%PDF-1.4 عقد');
        $this->assertNull($ticket->fresh()->assigned_lawyer_id, 'التذكرة بلا محامٍ — الصلة عبر القضيّة وحدها');

        $this->actingAs($lawyer)->get(ConversationFiles::url('ticket', $contract->id))->assertOk();

        $stranger = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->assertPageRefused($this->actingAs($stranger)->get(ConversationFiles::url('ticket', $contract->id)));
    }

    public function test_readiness_is_shown_only_while_the_pleading_awaits_the_lawyer(): void
    {
        [$case, $lawyer] = $this->file();
        $case->update(['pleading_status' => 'approved', 'status' => 'منظورة']);

        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))
            ->assertInertia(fn ($p) => $p->where('readiness', []));
    }

    public function test_the_documents_card_is_organised_with_filters_and_collapsible_summaries(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/lawyer/case.tsx'));

        foreach (["['case', 'مستندات القضية']", "['ticket', 'مرفقات الطلب']", "['pending', 'بانتظار التحليل']", 'عرض الملخّص', 'قبل التحويل', 'جاهزية اللائحة', 'وقائع الملف', 'قيمة المطالبة'] as $needle) {
            $this->assertStringContainsString($needle, $ui, $needle);
        }
        // الملخّص لا يُعرض كاملاً في القائمة — يُفتح عند الطلب
        $this->assertStringContainsString('{d.summary && openDoc === key && (', $ui);
        // وبطاقة الملفّ مرّةً واحدة (صعدت إلى رأس الشريط)
        $this->assertSame(1, substr_count($ui, '<div className="tc-top">'));
    }
}
