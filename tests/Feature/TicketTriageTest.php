<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * الوكيل التشغيلي الذكي للتذاكر — الفرز الآلي وطلب المستندات والإحالة الآلية والتصعيد.
 */
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

        // بوابة المستندات صامتة (بلا رسائل «قيد الاستلام/قيد التحليل» الآلية)
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

    public function test_attaching_related_document_auto_refers_to_lawyer(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->openTicket($client);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد توريد', 'summary' => 'عقد توريد بين الطرفين بقيمة محددة.', 'reason' => 'يوثّق العلاقة التعاقدية محل النزاع.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ])->assertNoContent();

        $ticket->refresh();
        // إحالة آلية كاملة: ملخص رباعي + إسناد محامٍ حقيقي (FK) + انتظار اعتماد المستشار
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $summary = TicketSummary::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('awaiting_lawyer', $summary->status);
        $this->assertNotEmpty($summary->case_summary);

        // سجل المستند: محفوظ بمساره ونتيجة فحصه + رسالة تأكيد الفحص للعميل
        $doc = $ticket->documents()->firstOrFail();
        $this->assertSame('مرتبط', $doc->status);
        $this->assertSame('عقد توريد', $doc->doc_type);
        $this->assertNotEmpty($doc->path);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'فحص المستند'));
    }

    public function test_unrelated_document_is_rejected_and_correct_docs_requested(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->openTicket($client);
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
        User::factory()->create(['role' => Role::Lawyer]);
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

    public function test_lawyer_approval_works_after_auto_referral(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->openTicket($client);
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد', 'summary' => 'عقد يخص النزاع.', 'reason' => 'مرتبط بالموضوع.']);

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100),
        ]);

        // الاعتماد القانوني يبقى بشرياً: المستشار يعتمد الملخص فيتقدم المسار
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket))->assertRedirect();
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);
    }

    public function test_attach_outside_documents_gate_does_nothing(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer]);
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

        // السلوك القديم: التذكرة تبقى قيد الدراسة بانتظار الموظف
        $this->assertSame('قيد الدراسة', $ticket->status);
        $this->assertFalse($ticket->messages->contains(fn ($m) => $m->name === 'الوكيل الذكي'));
    }
}
