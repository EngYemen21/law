<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\CaseMessage;
use App\Models\ContentRevision;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\CasePleading;
use App\Support\ContentRevisions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **سجلّ نسخ التحليلات والملخّصات** (طلب المالك 2026-09-29): كلّ نصٍّ من الآلة وكلّ تعديلٍ بشريّ نسخةٌ كاملة
 * بمصدرها وفاعلها وعنوانه، والنصّ السابق لا يضيع. كانت الكتابة في المكان تمحو الأصل (مسودّة اللائحة
 * تُحرَّر فوق نصّ الذكاء، وملخّص التذكرة والمحضر يُستبدلان).
 */
class ContentRevisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
    }

    private function ticketWithSummary(): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-REV-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => 'محالة للقسم القانوني',
            'tone' => 'b-blue', 'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        TicketSummary::create(['ticket_id' => $ticket->id, 'lawyer_id' => $this->lawyer->id, 'status' => 'awaiting_lawyer', 'case_summary' => 'ملخّص الآلة الأوّل', 'facts' => 'وقائع الآلة']);

        return $ticket;
    }

    public function test_human_edits_are_versioned_with_actor_and_ip_and_never_duplicated(): void
    {
        $ticket = $this->ticketWithSummary();
        $edit = fn (string $text) => $this->actingAs($this->lawyer)->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])
            ->post(route('lawyer.summary.update', $ticket), ['case_summary' => $text, 'facts' => 'وقائع الآلة']);

        $edit('تعديل المحامي الأوّل')->assertRedirect();
        $edit('تعديل المحامي الأوّل')->assertRedirect(); // الحفظ نفسه — لا نسخة مكرّرة
        $edit('تعديل المحامي الثاني')->assertRedirect();

        $rows = ContentRevision::where('subject_type', 'Ticket')->where('subject_id', $ticket->id)->where('kind', 'ticket_summary')->orderBy('version')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('version')->all());
        $this->assertSame(['ai', 'human', 'human'], $rows->pluck('source')->all(), 'الأوّل كتبته الآلة (بلا مستخدم)، ثمّ تعديلان');
        $this->assertSame('ملخّص الآلة الأوّل', $rows[0]->content['case_summary'], 'نصّ الآلة الأصليّ محفوظ');
        $this->assertSame('تعديل المحامي الثاني', $rows[2]->content['case_summary']);
        $this->assertSame([$this->lawyer->id, '198.51.100.44', 'lawyer'], [$rows[2]->actor_id, $rows[2]->ip, $rows[2]->actor_role]);
    }

    public function test_text_written_before_the_log_is_kept_as_baseline(): void
    {
        $ticket = $this->ticketWithSummary();
        ContentRevision::query()->delete(); // كأنّ النصّ كُتب قبل تفعيل السجلّ

        $this->actingAs($this->lawyer)->post(route('lawyer.summary.update', $ticket), ['case_summary' => 'بعد السجلّ'])->assertRedirect();

        $rows = ContentRevision::where('subject_id', $ticket->id)->where('kind', 'ticket_summary')->orderBy('version')->get();
        $this->assertSame(['baseline', 'human'], $rows->pluck('source')->all());
        $this->assertSame('ملخّص الآلة الأوّل', $rows[0]->content['case_summary']);
    }

    public function test_pleading_ai_draft_survives_the_lawyer_edit(): void
    {
        $case = LegalCase::create([
            'user_id' => $this->client->id, 'number' => 'CASE-REV-1', 'title' => 'دعوى', 'type' => 'تجاري', 'status' => 'قيد التحضير',
            'tone' => 'b-blue', 'update_text' => '—', 'assigned_lawyer_id' => $this->lawyer->id, 'pleading_status' => 'pending_lawyer',
        ]);
        // مسودّة الآلة داخل مهمّة طابور حقيقيّة (متزامنة في الاختبار)
        WritesPleadingDraftJob::dispatchSync($case->id);

        $this->actingAs($this->lawyer)->post(route('lawyer.cases.pleading.save', $case), ['body' => 'لائحة حرّرها المحامي بعد مراجعة مسودّة الآلة'])->assertRedirect();

        $rows = ContentRevision::where('subject_type', 'LegalCase')->where('subject_id', $case->id)->where('kind', 'case_pleading')->orderBy('version')->get();
        $this->assertSame(['ai', 'human'], $rows->pluck('source')->all());
        $this->assertStringContainsString('مسودّة الذكاء الاصطناعي', (string) $rows[0]->content['body']);
        $this->assertStringContainsString('لائحة حرّرها المحامي', (string) $rows[1]->content['body']);
    }

    public function test_machine_output_inside_a_request_keeps_its_source_and_who_triggered_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create(['ref' => 'M-REV-1', 'title' => 'اجتماع', 'when_label' => 'غداً', 'status' => MeetingStatus::Ended->value]);
        $this->actingAs($admin);

        ContentRevisions::machine('zoom', fn () => $meeting->update(['summary' => 'ملخّص Zoom']));
        $meeting->update(['summary' => 'ملخّص حرّرته الإدارة']);

        $rows = ContentRevision::where('subject_type', 'Meeting')->where('kind', 'meeting_summary')->orderBy('version')->get();
        $this->assertSame(['zoom', 'human'], $rows->pluck('source')->all());
        $this->assertSame($admin->id, $rows[0]->actor_id, 'من أطلق السحب يُذكر');
    }

    public function test_history_is_staff_only_and_scoped_like_the_file(): void
    {
        $ticket = $this->ticketWithSummary();
        $url = route('revisions.index', ['kind' => 'ticket_summary', 'ref' => $ticket->number]);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->getJson($url)->assertOk()
            ->assertJsonPath('label', 'ملخّص التذكرة')->assertJsonPath('versions.0.source', 'ai');
        $this->actingAs($this->lawyer)->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']))->getJson($url)->assertForbidden();
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());
        $this->actingAs($employee)->getJson($url)->assertOk();
        $plain = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $plain->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());
        $this->actingAs($plain)->getJson($url)->assertForbidden();
        $this->actingAs($this->client)->getJson($url)->assertForbidden();
        $this->actingAs($this->lawyer)->getJson(route('revisions.index', ['kind' => 'unknown', 'ref' => 'x']))->assertNotFound();
    }
}

/** مهمّة طابور تكتب مسودّة لائحةٍ آليّة — كما تفعل `DraftCasePleadingJob` بلا نداء نموذج. */
class WritesPleadingDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $caseId) {}

    public function handle(): void
    {
        CaseMessage::create([
            'case_id' => $this->caseId, 'who' => 'ai', 'name' => 'المساعد', 'role' => CasePleading::DRAFT_ROLE,
            'body' => '<div class="draft">مسودّة الذكاء الاصطناعي للّائحة</div>', 'time_label' => '10:00 ص', 'withheld_at' => now(),
        ]);
    }
}
