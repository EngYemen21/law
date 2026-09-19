<?php

namespace Tests\Feature;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Ticket\AwaitTicketDocuments;
use App\Domain\Journey\Transitions\Ticket\ReferTicketToLawyer;
use App\Domain\Journey\Transitions\Ticket\TicketDocumentsReceived;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **مستندات التذكرة وإحالتها تمرّ بالمحرّك** — طلب النواقص، ووصول المستند، والإحالة للمستشار.
 *
 * كان الموظّف والوكيل الآليّ يكتبان «بانتظار مستندات» و«قيد التحليل» و«بانتظار اعتماد المستشار»
 * مباشرةً. السلوك المرئيّ محفوظٌ في `EmployeeRequestDocsTest` و`EmployeeTicketAttachTest`
 * و`TicketTriageTest`؛ وهنا ما أضافه المرور بالمحرّك: قيدٌ بالفاعل، وحدودٌ لم تكن مكتوبة.
 */
class TicketDocumentsThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // التحليل الذكيّ والتلخيص خارج موضوع هذا الاختبار
        $this->seed(PermissionSeeder::class);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->employee = User::factory()->create(['role' => Role::Employee]);
        $this->employee->syncPermissions(Permission::whereIn('name', ['الرد على العملاء', 'إدارة التذاكر'])->get());
    }

    private function ticket(string $status, array $extra = []): Ticket
    {
        return Ticket::create($extra + [
            'user_id' => $this->client->id, 'number' => 'SB-DW-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => 'b-blue', 'last_message' => '—',
        ]);
    }

    public function test_employee_request_for_documents_is_a_recorded_transition(): void
    {
        $ticket = $this->ticket('قيد التحليل');

        $this->actingAs($this->employee)->post(route('employee.tickets.reqdocs', $ticket), ['docs' => ['عقد الإيجار']])
            ->assertNoContent();

        $ticket->refresh();
        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertSame('طلب نواقص من خدمة العملاء', $ticket->last_message);
        $row = JourneyTransition::where('transition', 'ticket.awaiting_documents')->sole();
        $this->assertSame('قيد التحليل', $row->from_state);
        $this->assertSame($this->employee->id, $row->actor_id);
        $this->assertSame(['via' => 'employee.request_docs'], $row->payload);
    }

    public function test_office_attachment_on_a_waiting_ticket_is_a_recorded_receipt(): void
    {
        $ticket = $this->ticket('بانتظار مستندات');

        $this->actingAs($this->employee)->post(route('employee.tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('عقد.pdf', 10, 'application/pdf'),
        ])->assertNoContent();

        $ticket->refresh();
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertSame('تم إرفاق مستند من المكتب: عقد.pdf', $ticket->last_message);
        $this->assertSame('بانتظار مستندات', JourneyTransition::where('transition', 'ticket.documents_received')->sole()->from_state);
    }

    /** الإحالة تفتح الملخّص بسطر ميلاده ثمّ تنقل التذكرة — سطران في سجلّ الرحلة. */
    public function test_referral_opens_the_summary_and_records_the_referral(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->ticket('محالة للقسم القانوني', ['assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
        TicketDocument::create(['ticket_id' => $ticket->id, 'name' => 'عقد.pdf', 'path' => 'ticket-docs/x.pdf', 'status' => 'مرتبط']);

        $this->actingAs($this->employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $ticket->refresh();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);
        $this->assertSame('awaiting_lawyer', $ticket->summary->status);

        $opened = JourneyTransition::where('transition', 'ticket_summary.opened')->sole();
        $this->assertNull($opened->from_state);
        $this->assertSame('awaiting_lawyer', $opened->to_state);

        $row = JourneyTransition::where('transition', 'ticket.referred_to_lawyer')->sole();
        $this->assertSame('محالة للقسم القانوني', $row->from_state);
        $this->assertSame($this->employee->id, $row->actor_id);
    }

    /**
     * الحارس يسدّ ما لم يكن ممكناً من المنادي أصلاً (ملفٌّ بعد اعتماد المستشار)، ويقبل الحالة
     * النصّيّة القديمة خارج الكتالوج كما كانت تُقبل.
     */
    public function test_referral_refuses_only_catalogue_states_after_lawyer_approval(): void
    {
        $approved = $this->ticket('الرأي القانوني');
        try {
            Workflow::run(new ReferTicketToLawyer, $approved, null, []);
            $this->fail('إحالةٌ بعد اعتماد المستشار');
        } catch (TransitionDenied) {
        }
        $this->assertSame('الرأي القانوني', $approved->fresh()->status);

        $legacy = $this->ticket('قيد المعالجة');
        Workflow::run(new ReferTicketToLawyer, $legacy, null, []);
        $this->assertSame('بانتظار اعتماد المستشار', $legacy->fresh()->status);
    }

    /** الانتقالان واسعان لكتّابٍ لم يفحصوا — ولا يُحييان ملفّاً حُسم مآله. */
    public function test_document_transitions_accept_every_open_state_but_no_terminal_one(): void
    {
        foreach ([new AwaitTicketDocuments, new TicketDocumentsReceived] as $transition) {
            foreach (['جديدة', 'قيد التحليل', 'بانتظار مستندات', 'محالة للقسم القانوني', 'بانتظار اعتماد المستشار', 'مكتملة'] as $open) {
                $this->assertTrue($transition->accepts($open), "{$transition->name()} ← {$open}");
            }
            foreach (['مغلقة', 'محولة إلى قضية'] as $terminal) {
                $this->assertFalse($transition->accepts($terminal), "{$transition->name()} ← {$terminal}");
            }
        }
    }
}
