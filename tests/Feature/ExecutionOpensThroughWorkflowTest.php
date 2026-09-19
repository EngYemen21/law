<?php

namespace Tests\Feature;

use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\ExecutionCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **ملفّ التنفيذ يُفتح داخل المحرّك** (`Workflow::open`).
 *
 * كان `ExecutionCreation` يُنشئ الملفّ بمرحلته وحالته خارج المحرّك وخارج قائمة الحارس (جرد
 * 2026-09-18)، ولا يبدأ سجلّ انتقالاته إلّا من الانتقال الثاني. الآن سطرُ فتحٍ بالفاعل والمصدر.
 * والحارس في الاختبارات «يرفض»، فأيّ إنشاءٍ باقٍ خارج المحرّك يُسقط هذه الاختبارات.
 */
class ExecutionOpensThroughWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // تحليل الملفّ خارج الموضوع
        Mail::fake();
    }

    public function test_opening_from_a_ruled_case_is_the_first_line_of_its_journey(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-OPEN-1', 'type' => 'تجاري', 'status' => 'صدر الحكم',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name, 'ruling' => 'إلزام المدعى عليه بالسداد.',
        ]);

        $exec = ExecutionCreation::fromCase($case, $lawyer);

        $this->assertSame(3, (int) $exec->stage);
        $this->assertSame(ExecFlow::label(3), $exec->status);
        $row = JourneyTransition::where('entity_type', 'Execution')->where('entity_id', $exec->id)->sole();
        $this->assertSame('exec.open_from_case', $row->transition);
        $this->assertNull($row->from_state, 'الفتح لا حالةَ قبله');
        $this->assertSame(ExecFlow::label(3), $row->to_state);
        $this->assertSame($lawyer->id, $row->actor_id);
        $this->assertSame(['case' => 'CASE-OPEN-1'], $row->payload);
    }

    public function test_opening_from_a_ticket_records_its_source_and_reason(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-OPEN-1', 'type' => 'تنفيذ', 'status' => 'بانتظار قرار المآل',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $exec = ExecutionCreation::fromTicket($ticket, $admin, 'سندٌ لأمر مستحقّ');

        $row = JourneyTransition::where('entity_type', 'Execution')->where('entity_id', $exec->id)->sole();
        $this->assertSame('exec.open_from_ticket', $row->transition);
        $this->assertSame('سندٌ لأمر مستحقّ', $row->reason);
        $this->assertSame('TK-OPEN-1', $row->payload['ticket']);
        $this->assertSame($admin->id, $row->actor_id);
    }

    /** إن فشل الإنشاء لا يبقى سطرُ فتحٍ يتيم — والإنشاء والسطر في معاملةٍ واحدة. */
    public function test_a_failed_open_leaves_no_orphan_line(): void
    {
        try {
            Workflow::open('exec.open_from_case', function () {
                Execution::create(['user_id' => 0, 'number' => 'EX-X', 'subject' => 'x', 'stage' => 3, 'status' => ExecFlow::label(3)]);
                throw new \RuntimeException('فشلٌ بعد الإنشاء');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Execution::where('number', 'EX-X')->count());
        $this->assertSame(0, JourneyTransition::count());
    }
}
