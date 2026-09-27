<?php

namespace Tests\Feature;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Conversation\TakeOverConversation;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\CaseMessage;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\ConversationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **من يتولّى المحادثة، ومن تولّاها قبله.** (طلب المالك 2026-09-25)
 *
 * «الموظّف لا يُحتفظ بمن الذي أدار المحادثات»: لم يكن للمحادثة مسؤولٌ ولا سجلّ، والرسالة تحمل
 * اسمَ كاتبها نصّاً منسوخاً لا حسابَه. وقرّر المالك: التولّي تلقائيّ (من يردّ من الطاقم يصير
 * المسؤول)، وردُّ زميلٍ ينقله إليه، على التذاكر والقضايا والتنفيذ، وكلُّ رسالةٍ تُربط بحسابها.
 */
class ConversationHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    private User $salma;

    private User $noura;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // الردود الآليّة ليست موضوع هذا الاختبار

        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'موكّل التجربة']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'مدير المكتب']);
        $this->salma = $this->employee('سلمى موظّفة الاستقبال');
        $this->noura = $this->employee('نورة موظّفة الاستقبال');
    }

    private function employee(string $name): User
    {
        $user = User::factory()->create(['role' => Role::Employee, 'name' => $name]);
        foreach (['إدارة التذاكر', 'الرد على العملاء', 'إدارة القضايا والأتعاب'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-HO-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue',
            'assigned_lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);
    }

    private function reply(User $as, Ticket $ticket, string $body = 'مرحباً، نتابع طلبك.'): void
    {
        $route = $as->isAdmin() ? 'admin.tickets.reply' : ($as->isLawyer() ? 'lawyer.tickets.reply' : 'employee.tickets.reply');
        $this->actingAs($as)->post(route($route, $ticket), ['body' => $body])->assertSuccessful();
    }

    /** @return Collection<int, JourneyTransition> */
    private function handovers(string $type, int $id)
    {
        return JourneyTransition::where('entity_type', $type)->where('entity_id', $id)
            ->where('transition', TakeOverConversation::NAME)->orderBy('id')->get();
    }

    // ── التولّي والنقل ──────────────────────────────────────────────────────────

    public function test_the_first_staff_reply_makes_its_writer_the_handler(): void
    {
        $ticket = $this->ticket();

        $this->reply($this->salma, $ticket);

        $this->assertSame($this->salma->id, $ticket->fresh()->handler_id);
        $rows = $this->handovers('Ticket', $ticket->id);
        $this->assertCount(1, $rows);
        $this->assertSame($this->salma->id, $rows[0]->actor_id);
        $this->assertNull($rows[0]->payload['from_id']);
        $this->assertSame($ticket->status, $rows[0]->to_state, 'تولّي المحادثة غيّر حالة التذكرة.');
    }

    public function test_the_handler_replying_again_writes_no_new_line(): void
    {
        $ticket = $this->ticket();

        $this->reply($this->salma, $ticket);
        $this->reply($this->salma, $ticket, 'ردٌّ ثانٍ');

        $this->assertCount(1, $this->handovers('Ticket', $ticket->id));
    }

    public function test_a_colleague_reply_hands_the_conversation_over(): void
    {
        $ticket = $this->ticket();

        $this->reply($this->salma, $ticket);
        $this->reply($this->noura, $ticket, 'أكمل عن زميلتي');

        $this->assertSame($this->noura->id, $ticket->fresh()->handler_id);
        $last = $this->handovers('Ticket', $ticket->id)->last();
        $this->assertSame($this->salma->name, $last->payload['from_name'], 'السجلّ لا يقول ممّن انتقلت.');
        $this->assertSame($this->noura->name, $last->payload['to_name']);
    }

    // ── ما لا ينقل المسؤوليّة ──────────────────────────────────────────────────

    public function test_an_internal_note_does_not_hand_it_over(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);

        $this->actingAs($this->noura)->post(route('employee.tickets.note', $ticket), ['body' => 'ملاحظة للفريق'])->assertSuccessful();

        $this->assertSame($this->salma->id, $ticket->fresh()->handler_id, 'الملاحظة الداخليّة ليست ردّاً على العميل.');
    }

    public function test_the_lawyer_reply_is_linked_to_the_lawyer_but_does_not_take_the_conversation(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);

        $this->reply($this->lawyer, $ticket, 'رأي المحامي');

        $this->assertSame($this->salma->id, $ticket->fresh()->handler_id, 'المحامي له إسناده المستقلّ.');
        $this->assertSame($this->lawyer->id, TicketMessage::where('body', 'like', '%رأي المحامي%')->value('sender_id'));
    }

    /** **الدور من الحساب لا من `who`:** ردُّ الإدارة على التذكرة يُخزَّن `who='lawyer'`. */
    public function test_an_admin_reply_stored_as_lawyer_still_takes_the_conversation(): void
    {
        $ticket = $this->ticket();

        $this->reply($this->admin, $ticket, 'ردّ الإدارة');

        $this->assertSame('lawyer', TicketMessage::where('body', 'like', '%ردّ الإدارة%')->value('who'));
        $this->assertSame($this->admin->id, $ticket->fresh()->handler_id);
    }

    /** رسالة النظام أو المهمّة بلا كاتبٍ بشريّ: بلا مُرسِل ولا نقل. */
    public function test_a_message_without_a_human_writer_changes_nothing(): void
    {
        $ticket = $this->ticket();

        $message = $ticket->messages()->create(['who' => 'staff', 'name' => 'النظام', 'role' => 'آليّ', 'body' => '<p>تحديث</p>']);

        $this->assertNull($message->fresh()->sender_id);
        $this->assertNull($ticket->fresh()->handler_id);
    }

    /**
     * **الإعلان الآليّ ليس ردّاً.** اعتمادُ الإدارة للملخّص يكتب في المحادثة إعلاناً للعميل — وكان
     * يجعل المديرَ «مسؤول المحادثة» لأنّه اعتمد. المالك قال «من **يردّ**»، فمسارات الردّ وحدها
     * (`conversation.reply`) تنقل المسؤوليّة.
     */
    public function test_an_announcement_written_by_another_action_does_not_take_the_conversation(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);

        $ticket->messages()->create(['who' => 'staff', 'name' => $this->admin->name, 'role' => 'اعتماد', 'body' => '<p>اعتُمد الملخّص</p>']);
        $this->actingAs($this->admin);
        $ticket->messages()->create(['who' => 'staff', 'name' => $this->admin->name, 'role' => 'اعتماد', 'body' => '<p>إعلانٌ آليّ في طلب المدير</p>']);

        $this->assertSame($this->salma->id, $ticket->fresh()->handler_id, 'إعلانٌ آليّ نقل المسؤوليّة.');
        $this->assertSame($this->admin->id, TicketMessage::where('body', 'like', '%إعلانٌ آليّ%')->value('sender_id'), 'والرسالة تبقى منسوبةً لكاتبها.');
    }

    // ── القضايا والتنفيذ ───────────────────────────────────────────────────────

    public function test_case_and_execution_conversations_are_handled_the_same_way(): void
    {
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-HO-1', 'type' => 'نزاع تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—', 'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $exec = Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-HO-1', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة',
            'tone' => 'b-blue', 'stage' => 2, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);

        $this->actingAs($this->salma)->post(route('employee.cases.reply', $case), ['body' => 'متابعة القضيّة'])->assertSuccessful();
        $this->actingAs($this->noura)->post(route('exec-flow.messages.store', $exec), ['body' => 'متابعة التنفيذ'])->assertSuccessful();

        $this->assertSame($this->salma->id, $case->fresh()->handler_id);
        $this->assertSame($this->noura->id, $exec->fresh()->handler_id);
        $this->assertCount(1, $this->handovers('LegalCase', $case->id));
        $this->assertCount(1, $this->handovers('Execution', $exec->id));
        $this->assertSame($this->salma->id, CaseMessage::where('body', 'like', '%متابعة القضيّة%')->value('sender_id'));
    }

    // ── السباق ────────────────────────────────────────────────────────────────

    /**
     * ردّان متزامنان من الموظّف نفسه يريان قبل القفل أنّه ليس المسؤول. الحارسُ يُفحص بعد القفل على
     * صفٍّ مُعاد قراءته، فيُرفض الثاني ولا يُكتب «تولّاها فلان بعد فلان» عن نفسه.
     */
    public function test_the_same_writer_cannot_take_over_twice_after_the_lock(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new TakeOverConversation, $ticket->fresh(), $this->salma, ['by' => $this->salma->id]);
    }

    // ── العرض: للطاقم وحده ────────────────────────────────────────────────────

    /** سطران منفصلان (قرار المالك 2026-09-27): ملفٌّ له محامٍ ولم يردّ عليه موظّفٌ بعد لا يُقرأ «بلا أحد». */
    public function test_the_card_carries_the_assigned_lawyer_apart_from_the_handler(): void
    {
        $ticket = $this->ticket();

        $history = ConversationHandler::history($ticket);
        $this->assertNull($history['current'], 'لم يردّ موظّفٌ بعد');
        $this->assertSame($this->lawyer->name, $history['lawyer']);

        $this->reply($this->salma, $ticket);
        $history = ConversationHandler::history($ticket->fresh());
        $this->assertSame($this->salma->name, $history['current']['name']);
        $this->assertSame($this->lawyer->name, $history['lawyer'], 'ردّ الموظّفة لا يحجب المحامي');
    }

    public function test_staff_see_the_handler_and_history_and_the_client_never_does(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);
        $this->reply($this->noura, $ticket, 'أكمل عن زميلتي');

        $this->actingAs($this->noura)->get(route('employee.tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p
                ->where('conversation.current.name', $this->noura->name)
                ->where('conversation.history.0.from', $this->salma->name)
                ->where('ticket.handler', $this->noura->name));

        foreach ([route('tickets.show', $ticket), route('tickets'), route('dashboard')] as $url) {
            $response = $this->actingAs($this->client)->get($url);
            if ($response->getStatusCode() !== 200) {
                continue;
            }
            $html = json_encode($response->viewData('page') ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($this->salma->name, (string) $html, "«{$url}» تحمل اسم الموظّفة للعميل.");
            $this->assertStringNotContainsString('sender_id', (string) $html);
            $this->assertStringNotContainsString('"conversation"', (string) $html);
        }
    }

    public function test_the_sender_account_is_hidden_from_raw_serialization(): void
    {
        $ticket = $this->ticket();
        $this->reply($this->salma, $ticket);

        $this->assertArrayNotHasKey('sender_id', TicketMessage::firstOrFail()->toArray());
    }
}
