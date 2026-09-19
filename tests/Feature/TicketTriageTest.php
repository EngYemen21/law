<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * الوكيل التشغيلي الذكي للتذاكر — الفرز الآلي وطلب المستندات والإحالة الآلية والتصعيد.
 */
// المحامون بقسم التذكرة: الإسناد الأوّل يشترط التخصّص (LawyerAssignmentPolicyTest)
class TicketTriageTest extends TestCase
{
    use RefreshDatabase;

    private function enableAgent(): void
    {
        config(['services.ai_agent.enabled' => true]);
    }

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'details' => 'أطالب الطرف الآخر بمستحقاتي بموجب العقد.',
        ]);

        return Ticket::latest('id')->firstOrFail();
    }

    /**
     * إسناد المحامي كما يفعل الطاقم من «تحويل التذاكر»/«توزيع التذاكر».
     * الفتح لم يعد يُسنِد (قرار المالك 2026-09-20)، وموضوع هذا الملفّ الوكيلُ لا سياسةُ الإسناد.
     */
    private function assignTo(Ticket $ticket, User $lawyer): void
    {
        $ticket->update(['assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
    }

    /** محاكاة نتيجة فحص المستند (بقية دوال الخدمة تعمل بالاحتياط القالبي الحقيقي). */
    private function mockDocAnalysis(?array $result): void
    {
        $this->partialMock(LegalAiService::class, function ($mock) use ($result) {
            $mock->shouldReceive('analyzeDocument')->andReturn($result);
        });
    }

    public function test_opening_ticket_requests_documents_in_single_greeting(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openTicket($client);

        // بوابة المستندات صامتة (بلا رسائل «جديدة/قيد التحليل» الآلية)
        $this->assertSame('بانتظار مستندات', $ticket->status);

        // رسالة ترحيب واحدة فقط للعميل (who=ai من خدمة العملاء) تتضمن المستندات المطلوبة
        $teamMsgs = $ticket->messages->where('who', '!=', 'client')->where('who', '!=', 'note');
        $this->assertCount(1, $teamMsgs);
        $greeting = $teamMsgs->first();
        $this->assertSame('خدمة العملاء', $greeting->name);
        $this->assertStringContainsString('العقد', $greeting->body); // نزاع تجاري → العقد

        // لا رسائل عملية آلية
        $this->assertFalse($ticket->messages->contains(fn ($m) => $m->role === 'استقبال' || $m->role === 'تحليل'));

        // ملاحظة تدقيق داخلية باسم الوكيل (لا يراها العميل)
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'note' && $m->name === 'الوكيل الذكي'));
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($msgs) => collect($msgs)->every(fn ($m) => $m['who'] !== 'note')));
    }

    public function test_attaching_related_document_moves_to_in_analysis_and_employee_advances_to_lawyer(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $this->assignTo($ticket, $lawyer);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد توريد', 'summary' => 'عقد توريد بين الطرفين بقيمة محددة.', 'reason' => 'يوثّق العلاقة التعاقدية محل النزاع.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ])->assertNoContent();

        $ticket->refresh();
        // الذكاء الاصطناعي لا يُحيل تلقائياً للمستشار — ينقلها لـ«قيد التحليل» بانتظار إحالة الموظف
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first());

        // سجل المستند: محفوظ بمساره ونتيجة فحصه + ملخصه أُرسل للعميل مباشرة (أُلغي اعتماد الموظف)
        $doc = $ticket->documents()->firstOrFail();
        $this->assertSame('مرتبط', $doc->status);
        $this->assertSame('عقد توريد', $doc->doc_type);
        $this->assertNotEmpty($doc->path);
        // الملخص رسالة ai مرئية للعميل
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'ai' && $m->role === 'تحليل المستند'));
        $this->assertFalse($ticket->messages->contains(fn ($m) => $m->role === 'ملخص بانتظار الاعتماد'));
        $this->assertTrue((bool) $doc->summary_approved);

        // الآن الموظف ينقر زر «إحالة للمستشار» — هنا فقط تقع الإحالة ويُسند المستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $ticket->refresh();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $summary = TicketSummary::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('awaiting_lawyer', $summary->status);
        $this->assertNotEmpty($summary->case_summary);
    }

    public function test_referral_dispatches_ai_summary_upgrade_job(): void
    {
        $this->enableAgent();
        // نُزيّف مهمّة الترقية فقط؛ عند إحالة الموظف تُطلق مهمّة الترقية
        Queue::fake([GenerateTicketSummaryJob::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $this->assignTo($ticket, $lawyer);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد توريد', 'summary' => 'عقد توريد.', 'reason' => 'يوثّق العلاقة.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ])->assertNoContent();

        // قبل إحالة الموظف: التذكرة قيد التحليل
        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
        Queue::assertNothingPushed();

        // الموظف يُحيل للمستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        // الإحالة كتبت الملخّص القالبي فوراً ثم أرسلت مهمّة ترقيته بتحليل حقيقي
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
        Queue::assertPushed(GenerateTicketSummaryJob::class,
            fn (GenerateTicketSummaryJob $job) => $job->ticket->id === $ticket->id);
    }

    public function test_unrelated_document_is_rejected_and_correct_docs_requested(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $this->assignTo($ticket, $lawyer);
        $this->mockDocAnalysis(['related' => false, 'doc_type' => 'وصفة طبية', 'summary' => 'تقرير طبي لا علاقة له بالنزاع.', 'reason' => 'المحتوى طبي ولا يخص النزاع التجاري.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('report.pdf', 50),
        ])->assertNoContent();

        $ticket->refresh();
        // لا انتقال للخطوة التالية: التذكرة باقية على بوابة المستندات
        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first());
        $this->assertSame('غير مرتبط', $ticket->documents()->firstOrFail()->status);

        // رسالة للعميل: المستند غير مرتبط + طلب المستندات الصحيحة (شرائح نوع الخدمة)
        $reject = $ticket->messages()->get()->last(fn ($m) => $m->role === 'نواقص');
        $this->assertNotNull($reject);
        $this->assertStringContainsString('غير مرتبط بموضوع تذكرتك', $reject->body);
        $this->assertStringContainsString('العقد', $reject->body);
    }

    public function test_unverifiable_document_defers_to_manual_review(): void
    {
        // بلا محاكاة وبلا مفاتيح AI → analyzeDocument يعيد null (تعذّر الفحص)
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ])->assertNoContent();

        $ticket->refresh();
        // لا إحالة آلية عمياء: يبقى القرار للموظف
        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertSame('بحاجة لمراجعة يدوية', $ticket->documents()->firstOrFail()->status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'note' && str_contains($m->body, 'تعذّر الفحص الآلي')));
    }

    public function test_lawyer_approval_works_after_employee_referral(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $this->assignTo($ticket, $lawyer);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد', 'summary' => 'عقد يخص النزاع.', 'reason' => 'مرتبط بالموضوع.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ]);

        // الموظف يُحيل التذكرة للمستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        // الاعتماد القانوني يبقى بشرياً: المستشار يراجع ويحرّر الملخّص ثم يعتمده فيتقدم المسار
        // (حارس الصدق يمنع اعتماد قالب لم يُحلَّل بالـAI ما لم يحرّره المحامي)
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket), [
            'case_summary' => 'ملخّص محرّر من المستشار بعد مراجعة الملف.',
            'key_points' => '• توجيه إنذار رسمي ثم دعوى عند التعذّر.',
        ])->assertRedirect();
        $this->assertSame('بانتظار اعتماد الإدارة للملخّص', $ticket->fresh()->status);

        // والإدارة تعتمد نهائيّاً فيصدر الرأي القانوني للعميل (قرار المالك 2026-09-14)
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.summary.approve', $ticket))->assertRedirect();
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
    }

    public function test_lawyer_cannot_approve_unanalyzed_template_summary(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $this->assignTo($ticket, $lawyer);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد', 'summary' => 'عقد.', 'reason' => 'مرتبط.']);
        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ]);

        // الموظف يُحيل التذكرة للمستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        // ملخّص قالبي (ai_generated=false، بلا مفاتيح AI) — اعتماده بلا تحرير مرفوض حمايةً للعميل
        $this->assertFalse((bool) $ticket->summary->fresh()->ai_generated);
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket))->assertSessionHasErrors('case_summary');
        $this->assertNotSame('الرأي القانوني', $ticket->fresh()->status);
    }

    public function test_staff_attachment_or_message_does_not_trigger_ai_reply_or_auto_referral(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->openTicket($client);
        $this->assertSame('بانتظار مستندات', $ticket->status);

        // إرفاق مستند من الموظف
        $this->actingAs($employee)->post(route('employee.tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('staff_doc.pdf', 100),
        ])->assertNoContent();

        $ticket->refresh();
        // الحالة تصبح قيد التحليل وتنتظر إحالة الموظف — لا إحالة تلقائية للمستشار
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first());

        // إرسال رد من الموظف — لا يؤدي لرد آلي من الذكاء الاصطناعي
        $this->actingAs($employee)->post(route('employee.tickets.reply', $ticket), [
            'body' => 'تم استلام الملف وجارٍ الاطلاع عليه.',
        ])->assertNoContent();

        $latestMsg = $ticket->messages()->reorder('id', 'desc')->first();
        // آخر رسالة هي رسالة الموظف نفسه — لم يقم الـ AI بالرد عليها
        $this->assertSame('staff', $latestMsg->who);
        $this->assertStringContainsString('تم استلام الملف', $latestMsg->body);
    }

    public function test_attach_outside_documents_gate_does_nothing(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client);
        $ticket->update(['status' => 'موعد مؤكد']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('extra.pdf', 50),
        ])->assertNoContent();

        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first());
    }

    public function test_complaint_message_creates_internal_escalation_note(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);

        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), [
            'body' => 'الموضوع عاجل جداً وأرجو سرعة المعالجة.',
        ])->assertNoContent();

        $this->assertTrue($ticket->messages()->get()->contains(
            fn ($m) => $m->who === 'note' && $m->role === 'إجراء آلي' && str_contains($m->body, 'تدخّل بشري')
        ));
    }

    public function test_agent_disabled_keeps_manual_flow(): void
    {
        config(['services.ai_agent.enabled' => false]);
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openTicket($client);

        // السلوك القديم: التذكرة تبقى قيد التحليل بانتظار الموظف
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertFalse($ticket->messages->contains(fn ($m) => $m->name === 'الوكيل الذكي'));
    }
}
