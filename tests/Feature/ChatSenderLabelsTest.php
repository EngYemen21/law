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
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\ChatSenderLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * **باسم مَن يرى العميلُ كلَّ رسالة — تضبطه الإدارة من الإعدادات.** (طلب المالك 2026-09-25)
 *
 * أربعة حقول: الموظّف · المحامي · الإدارة العليا · الذكاء الاصطناعي. وقرارات المالك:
 * - المحامي: البديل إن أُدخل، وإلّا اسمه المختصر «سارة. ق».
 * - للعميل وحده: الطاقم يرى الأسماء الحقيقيّة.
 * - الذكاء الاصطناعي: الاسم وحده بلا وسم «ردّ آليّ» (الوسم في الواجهة، فيحرسه غيابُه من `ChatThread`).
 */
class ChatSenderLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'موكّل التجربة']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'مدير المكتب']);
        $this->employee = User::factory()->create(['role' => Role::Employee, 'name' => 'سلمى موظّفة الاستقبال']);
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-LB-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);
    }

    /** رسالةٌ مربوطة بحساب كاتبها — كما يكتبها `RecordsSender` في طلبٍ حيّ. */
    private function say(Ticket $ticket, string $who, ?User $sender, string $name): TicketMessage
    {
        $message = new TicketMessage;
        $message->forceFill([
            'ticket_id' => $ticket->id, 'who' => $who, 'name' => $name, 'role' => 'ردّ',
            'body' => '<p>نصّ</p>', 'time_label' => '10:00 ص', 'sender_id' => $sender?->id,
        ])->save();

        return $message;
    }

    private function labels(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::put($key, $value);
        }
    }

    public function test_the_defaults_keep_what_the_client_saw_before(): void
    {
        $ticket = $this->ticket();

        $this->assertSame('الفريق القانوني', $this->say($ticket, 'staff', $this->employee, $this->employee->name)->toMessage(forClient: true)['name']);
        $this->assertSame('الفريق القانوني', $this->say($ticket, 'admin', $this->admin, $this->admin->name)->toMessage(forClient: true)['name']);
        $this->assertSame('خدمة العملاء', $this->say($ticket, 'ai', null, 'المساعد')->toMessage(forClient: true)['name']);
        $this->assertSame('سارة. ق', $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name)->toMessage(forClient: true)['name']);
    }

    public function test_the_admin_labels_reach_the_client_page_and_the_live_broadcast(): void
    {
        $this->labels([
            ChatSenderLabel::EMPLOYEE => 'خدمة العملاء البشريّة',
            ChatSenderLabel::LAWYER => 'المستشار القانوني المختصّ',
            ChatSenderLabel::ADMIN => 'إدارة المكتب',
            ChatSenderLabel::AI => 'المساعد الذكيّ',
        ]);
        $ticket = $this->ticket();
        $staff = $this->say($ticket, 'staff', $this->employee, $this->employee->name);
        $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name);
        $this->say($ticket, 'admin', $this->admin, $this->admin->name);
        $this->say($ticket, 'ai', null, 'المساعد');

        $this->actingAs($this->client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn (Assert $page) => $page
                ->where('messages.0.name', 'خدمة العملاء البشريّة')
                ->where('messages.1.name', 'المستشار القانوني المختصّ')
                ->where('messages.2.name', 'إدارة المكتب')
                ->where('messages.3.name', 'المساعد الذكيّ'));

        $this->assertSame('خدمة العملاء البشريّة', (new TicketMessageBroadcast($staff))->broadcastWith()['message']['name']);
    }

    /** الحقل فارغ ⇒ اسم كلّ محامٍ مختصراً؛ والتفريغ يُرجع بقيّة الحقول إلى افتراضها. */
    public function test_an_empty_lawyer_label_falls_back_to_the_short_name(): void
    {
        $this->labels([ChatSenderLabel::LAWYER => '', ChatSenderLabel::EMPLOYEE => '   ']);
        $ticket = $this->ticket();

        $this->assertSame('سارة. ق', $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name)->toMessage(forClient: true)['name']);
        $this->assertSame(ChatSenderLabel::OFFICE, $this->say($ticket, 'staff', $this->employee, $this->employee->name)->toMessage(forClient: true)['name']);
    }

    /**
     * **ردُّ الإدارة على التذكرة يُخزَّن `who='lawyer'`** — والحكم بالحساب لا بالنصّ. كان يصل العميلَ
     * باسم المدير مختصراً كأنّه محامٍ («مدير. ا»).
     */
    public function test_an_admin_reply_stored_as_lawyer_takes_the_admin_label(): void
    {
        $this->labels([ChatSenderLabel::ADMIN => 'إدارة المكتب']);
        $message = $this->say($this->ticket(), 'lawyer', $this->admin, $this->admin->name);

        $this->assertSame('إدارة المكتب', $message->toMessage(forClient: true)['name']);
    }

    /**
     * رسالةٌ بلا حساب (قديمة، أو كُتبت داخل مهمّة طابور): `who` يقرّر، والمخزَّن نصّاً **لا يُختصر إلّا
     * إن طابق حسابَ محامٍ** — كان «قسم التنفيذ» يصير «قسم. ت»، واسمُ مديرٍ يُختصر كأنّه محامٍ.
     */
    public function test_messages_without_an_account_shorten_only_a_real_lawyer_name(): void
    {
        $this->labels([ChatSenderLabel::EMPLOYEE => 'فريق الاستقبال']);
        $ticket = $this->ticket();
        $asLawyer = fn (string $stored) => $this->say($ticket, 'lawyer', null, $stored)->toMessage(forClient: true)['name'];

        $this->assertSame('فريق الاستقبال', $this->say($ticket, 'staff', null, 'اسمٌ قديم')->toMessage(forClient: true)['name']);
        $this->assertSame('سارة. ق', $asLawyer($this->lawyer->name));
        $this->assertSame('الإدارة العليا', $asLawyer('الإدارة العليا'));
        $this->assertSame(ChatSenderLabel::OFFICE, $asLawyer('قسم التنفيذ'));
        $this->assertSame(ChatSenderLabel::OFFICE, $asLawyer($this->admin->name));
    }

    /** للعميل وحده: شاشات الطاقم والملاحظة الداخليّة تبقى بالأسماء الحقيقيّة. */
    public function test_staff_keep_seeing_real_names(): void
    {
        $this->labels([ChatSenderLabel::EMPLOYEE => 'فريق الاستقبال', ChatSenderLabel::LAWYER => 'المستشار']);
        $ticket = $this->ticket();

        $this->assertSame($this->employee->name, $this->say($ticket, 'staff', $this->employee, $this->employee->name)->toMessage()['name']);
        $this->assertSame($this->lawyer->name, $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name)->toMessage()['name']);

        $note = $this->say($ticket, 'note', $this->employee, $this->employee->name);
        $this->assertSame($this->employee->name, (new TicketMessageBroadcast($note))->broadcastWith()['message']['name']);
    }

    /** القضيّة والتنفيذ بالمصدر نفسه — صفحاتهما وبثّهما. */
    public function test_cases_and_executions_use_the_same_labels(): void
    {
        $this->labels([ChatSenderLabel::EMPLOYEE => 'فريق الاستقبال']);

        $case = LegalCase::create(['user_id' => $this->client->id, 'number' => 'CS-LB-1', 'type' => 'نزاع', 'status' => 'منظورة']);
        $caseMessage = (new CaseMessage)->forceFill([
            'case_id' => $case->id, 'who' => 'staff', 'name' => $this->employee->name, 'role' => 'ردّ',
            'body' => '<p>نصّ</p>', 'time_label' => '10:00 ص', 'sender_id' => $this->employee->id,
        ]);
        $caseMessage->save();

        $exec = Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-LB-1', 'subject' => 'تنفيذ حكم',
            'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2,
        ]);
        $execMessage = (new ExecutionMessage)->forceFill([
            'execution_id' => $exec->id, 'who' => 'staff', 'name' => $this->employee->name, 'role' => 'ردّ',
            'body' => '<p>نصّ</p>', 'time_label' => '10:00 ص', 'sender_id' => $this->employee->id,
        ]);
        $execMessage->save();

        $this->assertSame('فريق الاستقبال', (new CaseMessageBroadcast($caseMessage))->broadcastWith()['message']['name']);
        $this->assertSame('فريق الاستقبال', (new ExecMessageBroadcast($execMessage))->broadcastWith()['message']['name']);

        $this->actingAs($this->client)->get(route('cases.show', $case))
            ->assertInertia(fn (Assert $page) => $page->where('messages.0.name', 'فريق الاستقبال'));
    }

    /** الحقول الأربعة في شاشة الإعدادات، تُحفظ منها، وتفريغُها يُعيد الافتراض. */
    public function test_the_admin_sets_the_labels_from_the_settings_tab(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.chat', 'مسمّيات المتحدّثين في محادثات العميل')
                ->has('fields.'.ChatSenderLabel::EMPLOYEE)
                ->has('fields.'.ChatSenderLabel::LAWYER)
                ->has('fields.'.ChatSenderLabel::ADMIN)
                ->has('fields.'.ChatSenderLabel::AI));

        $this->actingAs($this->admin)->post(route('admin.settings.update'), [
            ChatSenderLabel::AI => 'المساعد الذكيّ',
            ChatSenderLabel::LAWYER => 'المستشار',
        ])->assertRedirect();

        $ticket = $this->ticket();
        $this->assertSame('المساعد الذكيّ', $this->say($ticket, 'ai', null, 'المساعد')->toMessage(forClient: true)['name']);
        $this->assertSame('المستشار', $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name)->toMessage(forClient: true)['name']);

        $this->actingAs($this->admin)->post(route('admin.settings.update'), [ChatSenderLabel::LAWYER => ''])->assertRedirect();

        $this->assertSame('سارة. ق', $this->say($ticket, 'lawyer', $this->lawyer, $this->lawyer->name)->toMessage(forClient: true)['name']);
    }
}
