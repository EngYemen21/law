<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\CaseMessageBroadcast;
use App\Events\ExecMessageBroadcast;
use App\Events\TicketMessageBroadcast;
use App\Models\Appointment;
use App\Models\CaseMessage;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\ExecutionMessage;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\IcalendarService;
use App\Support\CaseFee;
use App\Support\ChatSenderLabel;
use App\Support\ExecService;
use App\Support\ExecutionCreation;
use App\Support\LawyerName;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **العميل لا يرى الاسم الكامل لأحدٍ من الطاقم — في أيّ حمولةٍ تصله.** (قرارا المالك 2026-09-11 و2026-09-25)
 *
 * المحامي يصل العميلَ «الاسم. الحرف» (`LawyerName`)، والموظّف والإدارة بالتسمية التي ضبطتها
 * الإدارة (`ChatSenderLabel`). والتسريب كان يعود من أبوابٍ لا تمرّ بالمصدرين: نصٌّ يُكتب في متن
 * رسالة («أُسند إلى <الاسم الكامل>»)، وعمودٌ نصّيٌّ يُقحم خاماً في تنبيه أو تقويم، ومشاركٌ في Zoom
 * باسم حسابه، وملاحظةٌ داخليّة تُبثّ على قناةٍ يسمعها العميل.
 *
 * فهذا الحارس يقود المسارات الحقيقيّة بأسماءٍ مميّزة، ثمّ يفتّش **كلَّ ما يصل العميل** — صفحته
 * وبثّه وتقويمه وتوقيع غرفته — عن أيّ اسمٍ كامل. ويحرس بنيةَ الكود أيضاً: لا متنَ رسالةٍ يقرؤه
 * العميل يُبنى من اسم حساب.
 */
class ClientNeverSeesStaffNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $otherLawyer;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Queue::fake();
        Mail::fake();

        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'موكّل الحارس']);
        // أسماءٌ مميّزة: الشطر الثاني منها لا يظهر في أيّ نصٍّ ثابتٍ في الكود، فظهوره تسريبٌ لا مصادفة
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. نايف الشمّري']);
        $this->otherLawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. بدر العنزيّ']);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'زايد المطيريّ']);
        $this->employee = User::factory()->create(['role' => Role::Employee, 'name' => 'ريم الحربيّة']);
    }

    /** لا اسمَ كاملٍ ولا لقبَ عائلةٍ لأحدٍ من الطاقم في هذا النصّ. */
    private function assertNoStaffName(string $haystack, string $where): void
    {
        foreach ([$this->lawyer, $this->otherLawyer, $this->admin, $this->employee] as $staff) {
            $this->assertStringNotContainsString($staff->name, $haystack, "{$where}: الاسم الكامل «{$staff->name}» يصل العميل");

            // الشطر الأخير وحده يكفي تسريباً: «الشمّري» لا تحمله الصيغة المختصرة «نايف. ش»
            $parts = preg_split('/\s+/u', $staff->name) ?: [];
            $this->assertStringNotContainsString((string) end($parts), $haystack, "{$where}: لقب «{$staff->name}» يصل العميل");
        }
    }

    /** كلّ ما تحمله صفحة Inertia للعميل — نصّاً واحداً يُفتَّش. */
    private function clientPage(string $url): string
    {
        $props = $this->actingAs($this->client)->get($url)->assertOk()->viewData('page')['props'];

        return (string) json_encode($props, JSON_UNESCAPED_UNICODE);
    }

    private function execution(array $extra = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $this->client->id, 'number' => 'EXE-GRD-'.uniqid(), 'subject' => 'تنفيذ سند',
            'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'decision' => 'مقبول',
        ], $extra));
    }

    // ── ١ · متون رسائل المكتب ──

    /** إسناد ملفّ التنفيذ وإعادة إسناده: المتن كان يحمل الاسمين الكاملين للقديم والجديد. */
    public function test_exec_assignment_messages_never_carry_full_names(): void
    {
        $exec = $this->execution();

        ExecService::assignLawyer($exec, $this->lawyer, $this->admin);
        ExecService::assignLawyer($exec->fresh(), $this->otherLawyer, $this->admin);

        $bodies = $exec->messages()->where('who', '!=', 'note')->pluck('body')->implode("\n");
        $this->assertStringContainsString(LawyerName::short($this->otherLawyer->name), $bodies, 'المسنَد إليه يُذكر باسمه للعميل');
        $this->assertNoStaffName($bodies, 'متن رسائل الإسناد');

        foreach ($exec->messages as $message) {
            $this->assertNoStaffName(json_encode((new ExecMessageBroadcast($message))->broadcastWith(), JSON_UNESCAPED_UNICODE), 'بثّ رسالة التنفيذ');
        }

        $this->assertNoStaffName($this->clientPage(route('execs')), 'صفحة «طلبات التنفيذ»');
    }

    /**
     * **المحامي السابق يُذكر ويُحفظ** (قرار المالك 2026-09-26: «حتى لا يضيع أيّ شيء»): رسالة العميل تسمّي
     * السابق والجديد بصيغتهما المختصرة، وسطر الرحلة يحفظ السابق كاملاً بحسابه واسمه للطاقم.
     */
    public function test_a_reassignment_names_the_previous_lawyer_and_keeps_him_on_record(): void
    {
        $exec = $this->execution();

        ExecService::assignLawyer($exec, $this->lawyer, $this->admin);
        ExecService::assignLawyer($exec->fresh(), $this->otherLawyer, $this->admin);

        $this->assertSame(
            'أُعيد إسناد ملفّ التنفيذ من '.LawyerName::short($this->lawyer->name).' إلى '.LawyerName::short($this->otherLawyer->name).'.',
            // `reorder`: العلاقة مرتّبةٌ تصاعديّاً في النموذج، و`latest` وحده يُضاف بعد ترتيبها فلا يغلبه
            strip_tags((string) $exec->messages()->where('who', '!=', 'note')->reorder('id', 'desc')->value('body')),
        );

        $rows = JourneyTransition::where('entity_type', 'Execution')->where('entity_id', $exec->id)
            ->where('transition', 'exec.assign_lawyer')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->payload['previous_lawyer_id'], 'الإسناد الأوّل لا سابق له');
        $this->assertSame($this->lawyer->id, $rows[1]->payload['previous_lawyer_id']);
        $this->assertSame($this->lawyer->name, $rows[1]->payload['previous_lawyer_name']);
        $this->assertSame($this->otherLawyer->name, $rows[1]->payload['lawyer_name']);
    }

    /** فتح التنفيذ من قضيّةٍ بلا محامٍ: المسنَد احتياطاً هو المدير الفاعل — ولا يُسمّى. */
    public function test_an_execution_opened_by_an_admin_does_not_name_him(): void
    {
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CS-GRD-1', 'type' => 'نزاع تجاري',
            'status' => 'صدر الحكم', 'tone' => 'b-green',
        ]);

        $exec = ExecutionCreation::fromCase($case, $this->admin);

        $opening = (string) $exec->messages()->where('role', 'فتح')->value('body');
        $this->assertStringContainsString(LawyerName::SPECIALIST, $opening);
        $this->assertNoStaffName($exec->messages()->where('who', '!=', 'note')->pluck('body')->implode("\n"), 'رسالة فتح التنفيذ');
        $this->assertNoStaffName($this->clientPage(route('execs')), 'صفحة «طلبات التنفيذ»');
        $this->assertNoStaffName($this->clientPage(route('cases.show', $case)), 'محادثة القضيّة');
    }

    /** تفعيل القضيّة بعد السداد: كان المتن يطبع العمود النصّيّ `assigned_lawyer` خاماً. */
    public function test_case_activation_names_the_lawyer_in_short_form(): void
    {
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CS-GRD-2', 'type' => 'نزاع تجاري',
            'status' => 'قيد التحضير', 'tone' => 'b-blue', 'pleading_status' => 'none',
            'assigned_lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);

        CaseFee::activate($case);

        $body = (string) $case->messages()->where('role', 'تفعيل')->value('body');
        $this->assertStringContainsString(LawyerName::short($this->lawyer->name), $body);
        $this->assertNoStaffName($body, 'رسالة تفعيل القضيّة');
        $this->assertNoStaffName($this->clientPage(route('cases.show', $case)), 'محادثة القضيّة');
    }

    // ── ٢ · الملاحظة الداخليّة لا تُبثّ على قناة العميل ──

    /**
     * **الملاحظة إلى `{base}.staff` في المحادثات الثلاث.** كان بثّ القضيّة والتنفيذ يرسل الملاحظة
     * («أعادت الإدارة إسناد القضية إلى <الاسم الكامل>»، ونتيجة الدراسة الداخليّة) على القناة المشتركة.
     */
    public function test_internal_notes_are_broadcast_to_the_staff_channel_only(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-GRD-1', 'type' => 'نزاع',
            'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);
        $case = LegalCase::create(['user_id' => $this->client->id, 'number' => 'CS-GRD-3', 'type' => 'نزاع', 'status' => 'منظورة']);
        $exec = $this->execution();

        $write = fn (string $who) => [
            'who' => $who, 'name' => $this->admin->name, 'role' => 'إسناد',
            'body' => '<p>أسندت الإدارة الملفّ إلى '.e($this->lawyer->name).'.</p>', 'time_label' => '10:00 ص',
        ];

        $cases = [
            'ticket.'.$ticket->id => fn (string $who) => new TicketMessageBroadcast(TicketMessage::create(['ticket_id' => $ticket->id] + $write($who))),
            'case.'.$case->id => fn (string $who) => new CaseMessageBroadcast(CaseMessage::create(['case_id' => $case->id] + $write($who))),
            'exec.'.$exec->id => fn (string $who) => new ExecMessageBroadcast(ExecutionMessage::create(['execution_id' => $exec->id] + $write($who))),
        ];

        foreach ($cases as $base => $broadcast) {
            $this->assertSame('private-'.$base.'.staff', $broadcast('note')->broadcastOn()[0]->name, "{$base}: الملاحظة تُبثّ على قناةٍ يسمعها العميل");
            $this->assertSame('private-'.$base, $broadcast('system')->broadcastOn()[0]->name, "{$base}: رسالة المحادثة لا تصل قناة العميل");
        }
    }

    // ── ٣ · لوحة العميل وتقويمه ──

    /** تنبيه «موعدك اليوم» كان يُقحم العمود النصّيّ خاماً؛ وبطاقة المستشار كانت تعرض محامياً لا صلة له. */
    public function test_the_client_dashboard_names_no_staff_member(): void
    {
        Appointment::create([
            'user_id' => $this->client->id, 'ext_id' => 'APT-GRD-1', 'type' => 'استشارة حضورية', 'ico' => 'pin',
            'lawyer' => $this->lawyer->name, 'lawyer_id' => $this->lawyer->id,
            'day' => today()->toDateString(), 'time' => '10:00', 'starts_at' => today()->setTime(10, 0),
            'place' => 'مقرّ المكتب', 'status' => 'مؤكد', 'tone' => 'b-green',
        ]);

        $page = $this->clientPage(route('dashboard'));

        $this->assertStringContainsString(LawyerName::short($this->lawyer->name), $page, 'التنبيه يذكر المحامي باسمه للعميل');
        $this->assertNoStaffName($page, 'لوحة العميل');

        // لا قضيّة ولا تذكرة مسنَدة ⇒ لا «مستشار مخصّص» — لا أوّلَ محامٍ نشط في المكتب
        $this->actingAs($this->client)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('assignedAdvisor', null));
    }

    /** التقويم المشترَك يُقرأ في تطبيق العميل خارج المنصّة — والطاقم يبقى بالأسماء الحقيقيّة. */
    public function test_the_client_calendar_feed_names_no_staff_member(): void
    {
        Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-GRD-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
            'starts_at' => today()->addDay()->setTime(11, 0), 'status' => 'موعد مؤكد',
        ]);
        Appointment::create([
            'user_id' => $this->client->id, 'ext_id' => 'APT-GRD-2', 'type' => 'استشارة حضورية', 'ico' => 'pin',
            'lawyer' => $this->lawyer->name, 'lawyer_id' => $this->lawyer->id,
            'day' => today()->addDay()->toDateString(), 'time' => '10:00',
            'place' => 'مقرّ المكتب', 'status' => 'مؤكد', 'tone' => 'b-green',
        ]);

        $feed = IcalendarService::feedForUser($this->client);
        $this->assertStringContainsString(LawyerName::short($this->lawyer->name), $feed);
        $this->assertNoStaffName($feed, 'تقويم العميل');

        $this->assertStringContainsString($this->lawyer->name, IcalendarService::feedForUser($this->lawyer), 'الطاقم يرى الاسم الحقيقيّ');
    }

    // ── ٤ · غرفة Zoom المضمّنة ──

    /** مربّع المشارك يحمل `userName` — والعميل يقرؤه في الغرفة نفسها. */
    public function test_staff_join_a_client_session_under_their_client_facing_label(): void
    {
        config(['services.zoom.sdk_key' => 'SDKKEY', 'services.zoom.sdk_secret' => 'SDKSECRET']);
        Http::fake(['*' => Http::response([], 400)]); // لا ZAK — الاسم هو المقيس لا الدور

        $consult = Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-GRD-2', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $this->lawyer->name, 'assigned_lawyer_id' => $this->lawyer->id,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة', 'meet_id' => '987654321', 'meet_password' => 'pw',
        ]);
        $join = fn (User $who) => $this->actingAs($who)->postJson(route('zoom.signature'), ['ref' => $consult->ref])->assertOk()->json('userName');

        $this->assertSame(LawyerName::short($this->lawyer->name), $join($this->lawyer));
        $this->assertSame(ChatSenderLabel::forAccount($this->admin), $join($this->admin));
        $this->assertNoStaffName($join($this->lawyer).' '.$join($this->admin), 'غرفة Zoom');
        $this->assertSame($this->client->name, $join($this->client), 'والعميل باسمه');
    }

    /** المصدر الواحد: المحادثة والغرفة يسمّيان الحساب نفسه بالاسم نفسه. */
    public function test_one_rule_names_an_account_for_the_client(): void
    {
        $this->assertSame(LawyerName::short($this->lawyer->name), ChatSenderLabel::forAccount($this->lawyer));
        $this->assertSame(ChatSenderLabel::OFFICE, ChatSenderLabel::forAccount($this->admin));
        $this->assertSame(ChatSenderLabel::OFFICE, ChatSenderLabel::forAccount($this->employee));
        $this->assertSame($this->client->name, ChatSenderLabel::forAccount($this->client));

        // والمحادثة تقرأ القاعدة نفسها بحساب المُرسِل
        foreach ([$this->lawyer, $this->admin, $this->employee] as $staff) {
            $this->assertSame(ChatSenderLabel::forAccount($staff), ChatSenderLabel::forClient('lawyer', $staff->name, $staff->id));
        }
    }

    // ── ٥ · حارس البنية ──

    /**
     * **لا متنَ رسالةٍ يقرؤه العميل يُبنى من اسم حساب.** الاختبارات أعلاه تقود المسارات المعروفة؛
     * وهذا يمنع موضعاً جديداً: كلُّ `'body' =>` أو `officeMsg(` يُقحم `$lawyer->name` أو عمود
     * `assigned_lawyer` أو `->lawyer` يجب أن يكون ملاحظةً داخليّة (`who='note'`) أو يمرّ بالمصدر
     * الواحد (`LawyerName::` · `ChatSenderLabel::`).
     */
    public function test_no_client_visible_message_body_is_built_from_a_staff_name(): void
    {
        $nameSource = '/\$(lawyer|actor|admin|employee|staff|assignee|sender|user)\w*->name\b|->assigned_lawyer\b|\$\w+->lawyer\b/u';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname()) ?: [];

            foreach ($lines as $i => $line) {
                $isBody = str_contains($line, "'body' =>");
                $isOfficeMsg = str_contains($line, 'officeMsg(') && ! str_contains($line, 'function officeMsg');
                if (! $isBody && ! $isOfficeMsg) {
                    continue;
                }

                // النصّ قد يمتدّ سطرين بعد الافتتاح (`officeMsg(..., $cond ? '…' : '…')`)
                $text = implode('', array_slice($lines, $i, $isOfficeMsg ? 3 : 1));
                if (! preg_match($nameSource, $text) || str_contains($text, 'LawyerName::') || str_contains($text, 'ChatSenderLabel::')) {
                    continue;
                }

                $context = implode('', array_slice($lines, max(0, $i - 5), 6));
                if ($isBody && preg_match("/'who' => 'note'/", $context)) {
                    continue;
                }

                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
            }
        }

        $this->assertSame([], $offenders, 'متنٌ يقرؤه العميل يُقحم اسم حسابٍ من الطاقم خاماً');

        // وتعليمة ملخّص الاستشارة: ما يُعطى للنموذج قد يُعاد في ملخّصٍ يقرؤه العميل
        $this->assertStringNotContainsString('{$consult->lawyer}', (string) file_get_contents(app_path('Services/LegalAiService.php')));
    }
}
