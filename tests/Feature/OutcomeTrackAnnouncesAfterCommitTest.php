<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Ticket\ApproveOutcomeTrack;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **قرار المآل لا يخرج قبل أن يُختم حفظه** (2026-09-20).
 *
 * `ApproveOutcomeTrack` يجري داخل معاملة المحرّك، وكان يبثّ القرار للشاشات ويُشعر العميل
 * ويُطلق مهامّ الخلفيّة من **داخلها**. والبثّ والمهمّة يغادران ولا يُلغيان مع المعاملة: يقرأ
 * العميل قراراً يختفي عند أوّل تحديث.
 *
 * (ومهامُّ الخلفيّة أُجّلت كذلك بـ`->afterCommit()` — تحصيناً لا إصلاحاً: طابور المشروع على
 * قاعدة البيانات، فصفُّ المهمّة يُكتب داخل الحفظ ويُلغى معه. يصير التأجيل ضرورةً لو نُقل
 * الطابور إلى Redis أو SQS.)
 *
 * (وفي مسار التنفيذ كان بريدُ المحامي يخرج كذلك — يغطّيه التأجيل نفسه في `ExecutionCreation`.)
 */
class OutcomeTrackAnnouncesAfterCommitTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** @return array{0:Ticket,1:User,2:User} التذكرة · العميل · الإداريّ */
    private function ticket(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-OT-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'بانتظار قرار المآل', 'tone' => 'b-amber',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);
        // «بانتظار قرار المآل» لا تُبلَغ إلّا بملخّصٍ معتمد — وبدونه يرتدّ الاعتماد 422 (وهو
        // `RuntimeException`)، فيمرّ اختبار الإلغاء بلا أن يُختبر شيء
        $this->approveOpinionOf($ticket);

        return [$ticket, $client, $admin];
    }

    /** @param  array<string,string>  $payload */
    private function approveInsideFailingTransaction(Ticket $ticket, User $admin, array $payload): void
    {
        try {
            DB::transaction(function () use ($ticket, $admin, $payload) {
                Workflow::run(new ApproveOutcomeTrack, $ticket, $admin, $payload);

                throw new RuntimeException('انهيارٌ بعد اعتماد المسار');
            });
        } catch (RuntimeException) {
            // متوقَّع
        }
    }

    public function test_a_rolled_back_decision_broadcasts_nothing_to_open_screens(): void
    {
        Event::fake([TicketMessageBroadcast::class]);
        [$ticket, $client, $admin] = $this->ticket();

        $this->approveInsideFailingTransaction($ticket, $admin, [
            'track' => 'close', 'reason' => 'خارجٌ عن اختصاص المكتب بعد الدراسة.',
        ]);

        Event::assertNotDispatched(TicketMessageBroadcast::class);
        $this->assertNotSame('مغلقة', $ticket->fresh()->status, 'بقي القرار رغم الإلغاء.');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    /** وفي المسار السليم يصل كلّ شيء كما كان — الإصلاح توقيتٌ لا إلغاء. */
    public function test_a_committed_decision_still_broadcasts_and_notifies(): void
    {
        Event::fake([TicketMessageBroadcast::class]);
        [$ticket, $client, $admin] = $this->ticket();

        Workflow::run(new ApproveOutcomeTrack, $ticket, $admin, [
            'track' => 'close', 'reason' => 'خارجٌ عن اختصاص المكتب بعد الدراسة.',
        ]);

        $this->assertSame('مغلقة', $ticket->fresh()->status);
        Event::assertDispatched(TicketMessageBroadcast::class);
        $this->assertGreaterThan(
            0,
            UserNotification::where('user_id', $client->id)->count(),
            'لم يصل العميلَ إشعار القرار.'
        );
    }
}
