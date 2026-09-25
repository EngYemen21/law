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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **عنوان IP لمُرسِل الرسالة: يُسجَّل، ويراه الطاقم، ولا يصل العميلَ قطّ.** (طلب المالك 2026-09-25)
 *
 * **ولماذا مسحٌ للحمولة كاملةً لا فحصُ حقل؟** — للعلّة نفسها في `LawyerNameNeverLeaksTest`: حقلٌ
 * جديد يُضاف لشكل الرسالة، أو بثٌّ جديد، أو علاقةٌ تُمرَّر خاماً — كلّها تحمل العنوان من بابٍ لم
 * يُحسب. فالحارس يبحث عن العنوان نصّاً في كلّ ما يستلمه العميل، والعدّاد يمنعه أن يمرّ فراغاً.
 */
class ChatSenderIpTest extends TestCase
{
    use RefreshDatabase;

    /** عنوانٌ من نطاق التوثيق (RFC 5737) — لا يصادف قيمةً أخرى في الحمولة. */
    private const IP = '203.0.113.7';

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    /** @return array{0: Ticket, 1: LegalCase, 2: Execution} */
    private function seedChats(): array
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-IP-1', 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-IP-1',
            'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
            'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $exec = Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-IP-1', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة',
            'tone' => 'b-blue', 'stage' => 2, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);

        // رسالةٌ ظاهرة للعميل في كلّ محادثة، بعنوانٍ مُسنَدٍ صراحةً (العمود خارج `$fillable` عمداً)
        foreach ([$ticket, $case, $exec] as $owner) {
            $message = $owner->messages()->make([
                'who' => 'lawyer', 'name' => 'محامي التجربة', 'role' => 'المحامي',
                'body' => '<p>ردّ المكتب</p>', 'time_label' => '10:42 ص',
            ]);
            $message->sender_ip = self::IP;
            $message->save();
        }

        return [$ticket, $case, $exec];
    }

    /** خصائص صفحة إنيرشيا مفكوكة الترميز — أو null لما ليس صفحةً (تحويلٌ أو 403). */
    private function propsOf(User $viewer, string $url): ?string
    {
        $response = $this->actingAs($viewer)->get($url);
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $props = null;
        try {
            $response->assertInertia(function ($page) use (&$props) {
                $props = $page->toArray()['props'] ?? [];

                return true;
            });
        } catch (\Throwable) {
            return null;
        }

        return is_array($props) ? (string) json_encode($props, JSON_UNESCAPED_UNICODE) : null;
    }

    // ── الحجب: لا شاشة عميلٍ تحمل العنوان ─────────────────────────────────────────

    public function test_no_client_chat_screen_carries_the_sender_ip(): void
    {
        [$ticket, $case] = $this->seedChats();

        $screens = [
            route('tickets.show', $ticket), route('cases.show', $case), route('execs'),
            route('dashboard'), route('tickets'), route('cases'),
        ];

        $scanned = 0;
        foreach ($screens as $url) {
            $payload = $this->propsOf($this->client, $url);
            if ($payload === null) {
                continue;
            }

            $scanned++;
            $this->assertStringNotContainsString(self::IP, $payload, "شاشة العميل «{$url}» تحمل عنوان IP المُرسِل.");
        }

        // المحادثات الثلاث على الأقلّ فُحصت — وإلّا مرّ الحارس فراغاً
        $this->assertGreaterThanOrEqual(3, $scanned, 'الحارس لم يفحص شاشات المحادثة — تحقّق من المسارات.');
    }

    /** وليس الحجبَ الكامل: الرسالة نفسها تصل العميل — فالحارس يفحص محادثةً فيها الرسالة فعلاً. */
    public function test_the_client_does_receive_the_message_itself(): void
    {
        [$ticket] = $this->seedChats();

        $this->assertStringContainsString('ردّ المكتب', (string) $this->propsOf($this->client, route('tickets.show', $ticket)));
    }

    /** البثّ يُبنى داخل طلب المُرسِل — والمُرسِل هنا إداريّ يرى العنوان — ثمّ يصل قناة العميل. */
    public function test_live_broadcasts_never_carry_the_sender_ip_even_when_built_by_staff(): void
    {
        $this->seedChats();
        $this->actingAs($this->admin);

        $events = [
            new TicketMessageBroadcast(TicketMessage::firstOrFail()),
            new CaseMessageBroadcast(CaseMessage::firstOrFail()),
            new ExecMessageBroadcast(ExecutionMessage::firstOrFail()),
        ];

        foreach ($events as $event) {
            $payload = (string) json_encode($event->broadcastWith(), JSON_UNESCAPED_UNICODE);

            $this->assertStringContainsString('ردّ المكتب', $payload, $event::class.': الحمولة لا تحمل الرسالة أصلاً.');
            $this->assertStringNotContainsString(self::IP, $payload, $event::class.' يبثّ عنوان IP المُرسِل على قناة العميل.');
        }
    }

    /** علاقةٌ تُحمَّل وتُمرَّر خاماً (`toArray`) لا تحمل العنوان — مخفيٌّ في النموذج نفسه. */
    public function test_raw_model_serialization_hides_the_sender_ip(): void
    {
        [$ticket] = $this->seedChats();

        $this->assertStringNotContainsString(self::IP, (string) json_encode($ticket->load('messages')->toArray()));
    }

    // ── العرض: الطاقم يراه ────────────────────────────────────────────────────────

    public function test_staff_chat_screens_do_carry_the_sender_ip(): void
    {
        [$ticket, $case] = $this->seedChats();

        $screens = [
            [$this->admin, route('admin.tickets.show', $ticket)],
            [$this->admin, route('admin.cases.show', $case)],
            [$this->admin, route('admin.execs')],
            [$this->lawyer, route('lawyer.tickets.show', $ticket)],
            [$this->lawyer, route('lawyer.cases.show', $case)],
            [$this->lawyer, route('lawyer.execs')],
        ];

        foreach ($screens as [$viewer, $url]) {
            $payload = $this->propsOf($viewer, $url);

            $this->assertNotNull($payload, "شاشة الطاقم «{$url}» لم تُفتح.");
            $this->assertStringContainsString('"ip":"'.self::IP.'"', $payload, "شاشة الطاقم «{$url}» لا تعرض عنوان IP المُرسِل.");
        }
    }

    // ── الالتقاط ──────────────────────────────────────────────────────────────────

    /** رسالةٌ يكتبها مستخدمٌ في طلبٍ حيّ تحفظ عنوانه — في المحادثات الثلاث، عميلاً كان أو مكتباً. */
    public function test_a_message_sent_in_a_user_request_records_the_sender_ip(): void
    {
        Queue::fake(); // الردّ الآليّ ليس موضوع هذا الاختبار
        [$ticket, $case, $exec] = $this->seedChats();

        $as = fn (User $user) => $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);

        $as($this->client)->post(route('tickets.messages.store', $ticket), ['body' => 'سؤال التذكرة'])->assertNoContent();
        $as($this->client)->post(route('cases.messages.store', $case), ['body' => 'سؤال القضية'])->assertNoContent();
        $as($this->admin)->post(route('exec-flow.messages.store', $exec), ['body' => 'ردّ التنفيذ'])->assertNoContent();

        $this->assertSame('198.51.100.20', TicketMessage::where('body', 'سؤال التذكرة')->value('sender_ip'));
        $this->assertSame('198.51.100.20', CaseMessage::where('body', 'سؤال القضية')->value('sender_ip'));
        $this->assertSame('198.51.100.20', ExecutionMessage::where('body', 'ردّ التنفيذ')->value('sender_ip'));

        // وعنوان المكتب لا يصل العميل كذلك: الحجب للحمولة لا لعنوان العميل وحده
        $this->assertStringNotContainsString('198.51.100.20', (string) $this->propsOf($this->client, route('execs')));
    }

    /** العنوان لا يُكتب من مدخلات الطلب: العميل لا يختار ما يُسجَّل عنه. */
    public function test_the_sender_ip_cannot_be_mass_assigned(): void
    {
        [$ticket] = $this->seedChats();

        $message = $ticket->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => 'x', 'time_label' => '—', 'sender_ip' => '10.9.9.9',
        ]);

        $this->assertNull($message->fresh()->sender_ip);
    }

    /**
     * **مهمّةٌ تجري داخل طلب مستخدم لا تأخذ عنوانه.** المهمّة المتزامنة (`dispatchSync`) تشارك
     * الطلب ومستخدمه — فبلا تتبّع المهامّ كانت ملاحظتها تُنسب لصاحب الطلب.
     */
    public function test_a_message_written_inside_a_job_has_no_sender_ip_even_within_a_user_request(): void
    {
        [$ticket] = $this->seedChats();

        Route::middleware('web')->post('/_test/sender-ip/{id}', function (int $id) {
            Ticket::findOrFail($id)->messages()->create([
                'who' => 'note', 'name' => 'موظّف', 'role' => 'ملاحظة', 'body' => 'بيد المستخدم', 'time_label' => '—',
            ]);
            WriteNoteFromJob::dispatchSync($id);

            return response()->noContent();
        });

        $this->actingAs($this->admin)->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post('/_test/sender-ip/'.$ticket->id)->assertNoContent();

        $this->assertSame(self::IP, TicketMessage::where('body', 'بيد المستخدم')->value('sender_ip'));
        $this->assertNull(TicketMessage::where('body', 'من المهمّة')->value('sender_ip'));
    }

    /** رسالة النظام والمساعد بلا مُرسِلٍ بشريّ — ولو كتبها إجراءُ مستخدمٍ في طلبه. */
    public function test_system_and_ai_messages_have_no_sender_ip(): void
    {
        [$ticket] = $this->seedChats();

        Route::middleware('web')->post('/_test/sender-ip-system/{id}', function (int $id) {
            foreach (['system', 'ai'] as $who) {
                Ticket::findOrFail($id)->messages()->create([
                    'who' => $who, 'name' => 'النظام', 'role' => '—', 'body' => 'آليّ '.$who, 'time_label' => '—',
                ]);
            }

            return response()->noContent();
        });

        $this->actingAs($this->admin)->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post('/_test/sender-ip-system/'.$ticket->id)->assertNoContent();

        $this->assertNull(TicketMessage::where('body', 'آليّ system')->value('sender_ip'));
        $this->assertNull(TicketMessage::where('body', 'آليّ ai')->value('sender_ip'));
    }

    /** بلا مستخدمٍ مسجَّل (عامل الطابور، الجدولة، أوامر artisan) لا عنوان. */
    public function test_a_message_written_without_an_authenticated_user_has_no_sender_ip(): void
    {
        [$ticket] = $this->seedChats();

        $message = $ticket->messages()->create([
            'who' => 'note', 'name' => 'أمر', 'role' => '—', 'body' => 'من سطر الأوامر', 'time_label' => '—',
        ]);

        $this->assertNull($message->fresh()->sender_ip);
    }
}

/** مهمّة طابور تكتب ملاحظةً — لاختبار أنّ ما يُكتب داخل مهمّة لا يُنسب لصاحب الطلب. */
class WriteNoteFromJob implements ShouldQueue
{
    // `Queueable` كمهامّ التطبيق: به يمرّ `dispatchSync` عبر طابور `sync` فتُطلق أحداثه
    use Dispatchable, Queueable;

    public function __construct(public int $ticketId) {}

    public function handle(): void
    {
        Ticket::findOrFail($this->ticketId)->messages()->create([
            'who' => 'note', 'name' => 'مهمّة', 'role' => '—', 'body' => 'من المهمّة', 'time_label' => '—',
        ]);
    }
}
