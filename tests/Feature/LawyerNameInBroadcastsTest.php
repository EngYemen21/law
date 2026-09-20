<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\CaseMessageBroadcast;
use App\Events\ExecMessageBroadcast;
use App\Events\TicketMessageBroadcast;
use App\Models\CaseMessage;
use App\Models\Execution;
use App\Models\ExecutionMessage;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **اسم المحامي مقنَّعٌ في البثّ اللحظيّ أيضاً** (قرار المالك 2026-09-11، ثغرةٌ وُجدت 2026-09-20).
 *
 * العميل يرى «محمد. ب» لا الاسم الكامل. والمتحكّمات تقنّعه عند التحميل (`LawyerName::inMessages`)،
 * لكنّ حمولة البثّ كانت خاماً: قنوات `case.{id}` و`ticket.{id}` و`exec.{id}` يُخوَّل عليها العميل
 * (`routes/channels.php`)، فيصله الاسم كاملاً لحظةَ الإرسال ثمّ يُختصر عند أوّل تحميل.
 */
class LawyerNameInBroadcastsTest extends TestCase
{
    use RefreshDatabase;

    private const FULL = 'سارة القحطاني';

    private const SHORT = 'سارة. ق';

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    public function test_a_case_message_broadcast_carries_the_short_name(): void
    {
        $client = $this->client();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CS-BC-1', 'type' => 'نزاع تجاري', 'status' => 'منظورة',
        ]);
        $message = CaseMessage::create([
            'case_id' => $case->id, 'who' => 'lawyer', 'name' => self::FULL,
            'role' => 'المستشار', 'body' => '<p>تحديث الملفّ</p>', 'time_label' => '10:00 ص',
        ]);

        $payload = (new CaseMessageBroadcast($message))->broadcastWith();

        $this->assertSame(self::SHORT, $payload['message']['name']);
    }

    public function test_a_ticket_message_broadcast_carries_the_short_name(): void
    {
        $client = $this->client();
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-BC-1', 'type' => 'نزاع تجاري',
            'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id, 'who' => 'lawyer', 'name' => self::FULL,
            'role' => 'المستشار', 'body' => '<p>رأيي في الملفّ</p>', 'time_label' => '10:00 ص',
        ]);

        $payload = (new TicketMessageBroadcast($message))->broadcastWith();

        $this->assertSame(self::SHORT, $payload['message']['name']);
    }

    public function test_an_execution_message_broadcast_carries_the_short_name(): void
    {
        $client = $this->client();
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-BC-1', 'subject' => 'تنفيذ حكم',
            'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2,
        ]);
        $message = ExecutionMessage::create([
            'execution_id' => $exec->id, 'who' => 'lawyer', 'name' => self::FULL,
            'role' => 'المستشار', 'body' => '<p>سار الطلب</p>', 'time_label' => '10:00 ص',
        ]);

        $payload = (new ExecMessageBroadcast($message))->broadcastWith();

        $this->assertSame(self::SHORT, $payload['message']['name']);
    }

    /**
     * ما ليس اسم محامٍ يمرّ كما هو: رسالة العميل والنظام والموظّف لا تُختصر — واختصارُ نائبٍ
     * نصّيّ («الإدارة العليا») يُنتج كلاماً بلا معنى.
     */
    public function test_other_senders_are_untouched(): void
    {
        $client = $this->client();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CS-BC-2', 'type' => 'نزاع', 'status' => 'منظورة',
        ]);

        foreach ([['client', 'أنت'], ['system', 'النظام'], ['staff', 'الإدارة العليا']] as [$who, $name]) {
            $message = CaseMessage::create([
                'case_id' => $case->id, 'who' => $who, 'name' => $name,
                'role' => 'تحديث', 'body' => '<p>نصّ</p>', 'time_label' => '10:00 ص',
            ]);

            $payload = (new CaseMessageBroadcast($message))->broadcastWith();

            $this->assertSame($name, $payload['message']['name'], "اسم «{$name}» تغيّر في البثّ.");
        }
    }
}
