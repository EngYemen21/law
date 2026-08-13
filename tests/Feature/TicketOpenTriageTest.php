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
 * فتح التذكرة واعٍ بالمرفقات: يُحلَّل نص المشكلة ومرفقاتها فعلياً قبل الرد، فيتفرّع رد خدمة العملاء:
 * لا مرفقات → طلب المستندات؛ مرفق ذو صلة → إقرار الموظف المختص + إحالة؛ غير ذي صلة → طلب الصحيح؛
 * متعذّر الفحص → إقرار بالاستلام + مراجعة يدوية.
 */
class TicketOpenTriageTest extends TestCase
{
    use RefreshDatabase;

    private function enableAgent(): void
    {
        config(['services.ai_agent.enabled' => true]);
    }

    private function mockDocAnalysis(?array $result): void
    {
        $this->partialMock(LegalAiService::class, function ($mock) use ($result) {
            $mock->shouldReceive('analyzeDocument')->andReturn($result);
        });
    }

    /** يفتح تذكرة (اختيارياً بمرفقات) عبر المسار الحقيقي POST /tickets. */
    private function openTicket(User $client, array $files = []): Ticket
    {
        $payload = ['type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'details' => 'أطالب الطرف الآخر بمستحقاتي بموجب العقد المبرم بيننا.'];
        if ($files !== []) {
            $payload['files'] = $files;
        }
        $this->actingAs($client)->post(route('tickets.store'), $payload)->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_open_without_documents_requests_documents(): void
    {
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openTicket($client);

        $this->assertSame('بانتظار مستندات', $ticket->status);
        $greeting = $ticket->messages->where('who', 'ai')->firstWhere('role', LegalAiService::AGENT_ROLE);
        $this->assertNotNull($greeting);
        $this->assertStringContainsString('doc-list', $greeting->body); // قائمة مستندات مطلوبة
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first()); // لا إحالة
    }

    public function test_open_with_related_document_acknowledges_and_refers(): void
    {
        $this->enableAgent();
        $this->mockDocAnalysis(['related' => true, 'doc_type' => 'عقد توريد', 'summary' => 'عقد توريد يوثّق العلاقة محل النزاع.', 'reason' => 'مرتبط بالموضوع.']);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $ticket = $this->openTicket($client, [UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf')]);

        // إقرار بنبرة الموظف المختص (رسالة staff/خدمة العملاء) ثم إحالة تلقائية كاملة
        $ack = $ticket->messages->where('who', 'staff')->firstWhere('role', 'خدمة العملاء');
        $this->assertNotNull($ack);
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame('awaiting_lawyer', TicketSummary::where('ticket_id', $ticket->id)->firstOrFail()->status);
        $this->assertSame('مرتبط', $ticket->documents()->firstOrFail()->status);

        // إشعار المستشار المسند بمراجعة الملخّص
        $this->assertNotNull($ticket->assigned_lawyer_id);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $ticket->assigned_lawyer_id]);
    }

    public function test_open_with_unrelated_document_requests_correct_docs(): void
    {
        $this->enableAgent();
        $this->mockDocAnalysis(['related' => false, 'doc_type' => 'وصفة طبية', 'summary' => 'تقرير طبي لا صلة له بالنزاع.', 'reason' => 'المحتوى طبي.']);
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer]);

        $ticket = $this->openTicket($client, [UploadedFile::fake()->create('report.pdf', 60, 'application/pdf')]);

        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertSame('غير مرتبط', $ticket->documents()->firstOrFail()->status);
        $this->assertNull(TicketSummary::where('ticket_id', $ticket->id)->first()); // لا إحالة
        $reject = $ticket->messages->firstWhere('role', 'نواقص');
        $this->assertNotNull($reject);
        $this->assertStringContainsString('لا تخصّ موضوع تذكرتك', $reject->body);
    }

    public function test_open_with_unanalyzable_document_acknowledges_and_flags_manual(): void
    {
        // بلا مفاتيح AI في بيئة الاختبار → analyzeDocument يعيد null (تعذّر الفحص)
        $this->enableAgent();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer]);

        $ticket = $this->openTicket($client, [UploadedFile::fake()->create('scan.pdf', 90, 'application/pdf')]);

        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertSame('بحاجة لمراجعة يدوية', $ticket->documents()->firstOrFail()->status);
        // إقرار بالاستلام (لا إعادة طلب بجفاء) + ملاحظة مراجعة يدوية داخلية
        $ack = $ticket->messages->where('who', 'ai')->firstWhere('role', LegalAiService::AGENT_ROLE);
        $this->assertNotNull($ack);
        $this->assertStringContainsString('قيد المراجعة', $ack->body);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'note' && str_contains($m->body, 'تدخّل بشري')));
    }
}
