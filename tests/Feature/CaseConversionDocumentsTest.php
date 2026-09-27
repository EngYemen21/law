<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Support\ExecutionCreation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **مرفقات الطلب تصل ملفّ القضيّة لكلّ من يراه — بلا ما رفضه الفحص** (ملاحظة المالك 2026-09-27).
 *
 * كانت القضيّة المحوَّلة تبدأ فارغةً عند الإدارة والعميل (شاشة المحامي وحدها تقرأ التذكرة)، وكان
 * المحامي والتنفيذ يأخذان المرفق «غير مرتبط» مع غيره.
 */
class CaseConversionDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Queue::fake();

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->ticket = Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'SB-DOCS-1',
            'type' => 'نزاع تجاري',
            'subject' => 'مطالبة مالية',
            'status' => TicketStatus::ReadyForOutcome->value,
            'assigned_lawyer_id' => $this->lawyer->id,
            'assigned_lawyer' => $this->lawyer->name,
        ]);

        foreach ([['العقد.pdf', 'مرتبط', 'عقد توريد'], ['صورة_شخصية.jpg', 'غير مرتبط', 'صورة']] as [$name, $status, $type]) {
            TicketDocument::create([
                'ticket_id' => $this->ticket->id, 'name' => $name, 'path' => "ticket-docs/{$name}",
                'mime' => 'application/pdf', 'size' => 1000, 'status' => $status, 'doc_type' => $type, 'summary' => 'ملخّص',
            ]);
        }
    }

    private function convertedCase(): LegalCase
    {
        return LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $this->ticket->id, 'number' => 'CASE-2026-7001',
            'type' => 'تجارية', 'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
            'status' => 'بانتظار اعتماد الأتعاب', 'fee_status' => 'none',
        ]);
    }

    public function test_admin_and_client_see_the_related_ticket_documents_of_a_converted_case(): void
    {
        $case = $this->convertedCase();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get(route('admin.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('ticketDocuments', 1)->where('ticketDocuments.0.name', 'العقد.pdf'));

        $this->actingAs($this->client)->get(route('cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('ticketDocuments', 1)->where('ticketDocuments.0.name', 'العقد.pdf'));
    }

    public function test_the_employee_sees_them_too(): void
    {
        $case = $this->convertedCase();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->get(route('employee.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('ticketDocuments', 1)->where('ticketDocuments.0.name', 'العقد.pdf'));
    }

    public function test_the_lawyer_no_longer_gets_rejected_documents(): void
    {
        $case = $this->convertedCase();

        $this->actingAs($this->lawyer)->get(route('lawyer.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('ticketDocuments', 1)->where('ticketDocuments.0.name', 'العقد.pdf'));
    }

    public function test_execution_does_not_carry_rejected_documents(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $exec = ExecutionCreation::fromTicket($this->ticket, $admin, 'تحويل مسار التنفيذ بعد الاعتماد');

        $this->assertSame(['العقد.pdf'], $exec->documents()->pluck('label')->all());
        $this->assertSame(['عقد توريد'], $exec->docs);
    }
}
