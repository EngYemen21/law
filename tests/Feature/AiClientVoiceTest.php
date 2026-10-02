<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Jobs\AnalyzeExecutionDocumentJob;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Jobs\DraftCasePleadingJob;
use App\Jobs\GenerateCaseReplyJob;
use App\Jobs\GenerateTicketReplyJob;
use App\Models\AiRun;
use App\Models\CaseDocument;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewEntityState;
use App\Services\Ai\AiReviewOutcome;
use App\Services\LegalAiService;
use App\Support\AiClientVoice;
use App\Support\CasePleading;
use App\Support\ChatSenderLabel;
use App\Support\ExecFlow;
use App\Support\SettingsRegistry;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **متى يكلّم الذكاءُ الاصطناعيّ العميلَ** (قرار المالك 2026-10-02) — `AiClientVoice` ومن يقرؤه:
 *
 * 1. اعتماد دراسة التنفيذ بعد تجاوز الملفّ مرحلتها: داخليٌّ وحده (ثبت بالمتصفّح: ملفٌّ «قيد التنفيذ» وصل عميلَه
 *    «دراسة معتمدة · نواقص مطلوبة»). والمنشور باسم المعتمِد بتسمية «إعدادات النظام» لا «خدمة العملاء».
 * 2. تحليل المستند: للعميل ما دام الملفّ قائماً والمستند منه ولم يتدخّل إنسان — وإلا ملاحظةٌ للطاقم.
 * 3. اللائحة: لا اعتماد ولا إطلاق لقضيّةٍ مغلقة، ولا مسودّة بعد الاعتماد.
 * 4. التصنيف: لا يُطبَّق بعد الحكم أو الإغلاق.
 * 5. الردّ الآليّ يتوقّف بعد تدخّل إنسانٍ من المكتب، ويُنبَّه مسؤول المحادثة.
 * 6. الصندوق يعرض حالة الملفّ وأثر القبول.
 */
class AiClientVoiceTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function staff(Role $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function exec(User $client, int $stage, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => 'EXE-V-'.uniqid(), 'subject' => 'تنفيذ حكم', 'sanad' => 'حكم قضائي',
            'amount' => 50000, 'stage' => $stage, 'status' => ExecFlow::label($stage), 'tone' => ExecFlow::tone($stage),
        ], $attrs));
    }

    private function case(User $client, string $status = 'منظورة', array $attrs = []): LegalCase
    {
        return LegalCase::create(array_merge([
            'user_id' => $client->id, 'number' => 'CASE-V-'.uniqid(), 'type' => 'نزاع تجاري',
            'assigned_lawyer' => 'أ. سارة', 'status' => $status, 'tone' => 'b-blue', 'update_text' => '—',
        ], $attrs));
    }

    private function ticket(User $client, string $status = 'جديدة'): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-V-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => TicketJourney::toneFor($status),
        ]);
    }

    private function studyRun(Execution $exec): AiRun
    {
        $exec->update(['ai_summary' => 'خلاصة الدراسة.', 'ai_missing' => ['صورة الهوية'], 'ai_done' => true]);

        return AiRun::create([
            'task_type' => 'execution', 'entity_type' => Execution::class, 'entity_id' => $exec->id,
            'entity_ref' => $exec->number, 'source' => AiSource::AiSuccess->value, 'status' => 'needs_review',
        ]);
    }

    private function fakeAi(): void
    {
        $this->partialMock(LegalAiService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldReceive('analyzeCaseDocument')->andReturn(['doc_type' => 'عقد', 'summary' => 'ملخّص العقد.']);
            $mock->shouldReceive('analyzeExecutionDocument')->andReturn(['doc_type' => 'سند', 'summary' => 'ملخّص السند.']);
            $mock->shouldReceive('replyResult')->andReturn(['text' => 'ردّ آليّ.', 'source' => AiSource::AiSuccess, 'meta' => []]);
            $mock->shouldReceive('caseReplyResult')->andReturn(['text' => 'ردّ آليّ.', 'source' => AiSource::AiSuccess, 'meta' => []]);
        });
    }

    // ── ١ · دراسة التنفيذ ──

    public function test_study_approved_after_its_stage_stays_internal(): void
    {
        $client = $this->client();
        $admin = $this->staff(Role::Admin);
        $exec = $this->exec($client, 8);

        AiReviewOutcome::apply($this->studyRun($exec), AiReviewAction::Accept, $admin);
        $exec->refresh()->load('messages', 'documents');

        $this->assertTrue($exec->aiApproved(), 'الاعتماد يُسجَّل');
        $this->assertFalse($exec->aiReleased(), 'ولا يُنشر');
        $this->assertNull($exec->toFlowCard(false, false)['study'], 'ولا تنكشف بطاقة الدراسة للعميل');
        $this->assertSame(0, $exec->messages()->where('who', '!=', 'note')->where('role', 'دراسة معتمدة')->count());
        $this->assertSame(1, $exec->messages()->where('who', 'note')->where('role', 'دراسة معتمدة')->count(), 'ملاحظةٌ للمكتب');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'ولا إشعار للعميل');
    }

    public function test_rejected_or_closed_files_are_never_told_their_study(): void
    {
        $admin = $this->staff(Role::Admin);
        foreach ([$this->exec($this->client(), 2, ['decision' => 'مرفوض']), $this->exec($this->client(), 9)] as $exec) {
            AiReviewOutcome::apply($this->studyRun($exec), AiReviewAction::Accept, $admin);
            $this->assertFalse($exec->fresh()->aiReleased(), $exec->number);
        }
    }

    public function test_study_in_its_stage_is_published_under_the_approvers_role_label(): void
    {
        $client = $this->client();
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client, 2);

        $this->actingAs($lawyer);
        AiReviewOutcome::apply($this->studyRun($exec), AiReviewAction::Accept, $lawyer);
        $exec->refresh();

        $this->assertTrue($exec->aiReleased());
        $msg = $exec->messages()->where('role', 'دراسة معتمدة')->firstOrFail();
        $this->assertSame('lawyer', $msg->who, 'باسم دور المعتمِد لا «ai»');
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // والعميل يقرأ تسمية المحامي من «إعدادات النظام» لا «خدمة العملاء»
        $label = ChatSenderLabel::forClient($msg->who, (string) $msg->name, $msg->sender_id);
        $this->assertNotSame(SettingsRegistry::str(ChatSenderLabel::AI), $label);
    }

    // ── ٢ · تحليل المستند ──

    public function test_case_document_analysis_goes_to_staff_when_case_closed_or_staff_spoke_or_staff_uploaded(): void
    {
        $this->fakeAi();
        $client = $this->client();

        $closed = $this->case($client, 'مغلقة');
        (new AnalyzeCaseDocumentJob($closed, $this->caseDoc($closed, 'client')))->handle(app(LegalAiService::class));
        $this->assertSame('note', $closed->messages()->where('role', 'تحليل المستند')->value('who'), 'قضيّة مغلقة');

        $spoken = $this->case($client);
        $spoken->messages()->create(['who' => 'lawyer', 'name' => 'المحامي', 'role' => 'ردّ', 'body' => 'تفضّل.', 'time_label' => 'الآن']);
        (new AnalyzeCaseDocumentJob($spoken, $this->caseDoc($spoken, 'client')))->handle(app(LegalAiService::class));
        $this->assertSame('note', $spoken->messages()->where('role', 'تحليل المستند')->value('who'), 'بعد تدخّل المحامي');

        $staffUpload = $this->case($client);
        (new AnalyzeCaseDocumentJob($staffUpload, $this->caseDoc($staffUpload, 'lawyer')))->handle(app(LegalAiService::class));
        $this->assertSame('note', $staffUpload->messages()->where('role', 'تحليل المستند')->value('who'), 'مستندٌ رفعه المكتب');

        $fresh = $this->case($client);
        (new AnalyzeCaseDocumentJob($fresh, $this->caseDoc($fresh, 'client')))->handle(app(LegalAiService::class));
        $this->assertSame('ai', $fresh->messages()->where('role', 'تحليل المستند')->value('who'), 'والأصل كما كان');
    }

    public function test_execution_document_analysis_is_internal_on_a_rejected_file(): void
    {
        $this->fakeAi();
        $exec = $this->exec($this->client(), 2, ['decision' => 'مرفوض']);
        $doc = ExecutionDocument::create(['execution_id' => $exec->id, 'label' => 'سند', 'status' => 'مرفوع', 'uploaded_by' => 'client']);

        (new AnalyzeExecutionDocumentJob($exec, $doc))->handle(app(LegalAiService::class));

        $this->assertSame('note', $exec->messages()->where('role', 'تحليل المستند')->value('who'));
    }

    // ── ٣ · اللائحة ──

    public function test_closed_case_pleading_is_blocked_and_no_draft_after_approval(): void
    {
        $client = $this->client();
        $closed = $this->case($client, 'مغلقة', ['pleading_status' => 'pending_lawyer']);
        $this->assertSame('القضيّة مغلقة — لا تُعتمد لائحتها ولا تُطلَق للعميل.', CasePleading::blockReason($closed));

        $approved = $this->case($client, 'قيد التحضير', ['pleading_status' => 'approved']);
        (new DraftCasePleadingJob($approved))->handle(app(LegalAiService::class));
        $this->assertSame(0, $approved->messages()->count(), 'لا مسودّة بعد الاعتماد');
    }

    // ── ٤ · التصنيف ──

    public function test_classification_is_not_applied_after_judgment(): void
    {
        $admin = $this->staff(Role::Admin);
        $case = $this->case($this->client(), 'صدر الحكم', ['ai_classification' => ['type' => 'عمالي', 'department' => 'العمالي']]);
        $run = AiRun::create([
            'task_type' => 'case.classify', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'source' => AiSource::AiSuccess->value, 'status' => 'needs_review',
        ]);

        AiReviewOutcome::apply($run, AiReviewAction::Accept, $admin);
        $case->refresh();

        $this->assertSame('نزاع تجاري', $case->type, 'لا يتغيّر نوع قضيّةٍ حُكم فيها');
        $this->assertNull($case->ai_classification, 'والمقترح يسقط');
        $this->assertFalse($case->acceptsReclassification());

        // ولا يُقترح لها تصنيفٌ جديد أصلاً — المهمّة تنتهي قبل نداء الذكاء
        $this->mock(LegalAiService::class, fn ($mock) => $mock->shouldNotReceive('classifyConvertedCase'));
        (new ClassifyConvertedCaseJob($case))->handle(app(LegalAiService::class));
        $this->assertNull($case->fresh()->ai_classification);
    }

    // ── ٥ · الردّ الآليّ ──

    public function test_ai_stops_replying_after_a_human_from_the_office_spoke(): void
    {
        $this->fakeAi();
        $client = $this->client();
        $employee = $this->staff(Role::Employee);

        $ticket = $this->ticket($client);
        $ticket->forceFill(['handler_id' => $employee->id])->save(); // يختمه `ConversationHandler` في الواقع — لا يُملأ من المدخلات
        $ticket->messages()->create(['who' => 'staff', 'name' => 'الموظف', 'role' => 'خدمة العملاء', 'body' => 'أهلاً.', 'time_label' => 'الآن']);
        $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => 'سؤال.', 'time_label' => 'الآن']);

        (new GenerateTicketReplyJob($ticket, 'سؤال.'))->handle(app(LegalAiService::class));

        $this->assertSame(0, $ticket->messages()->where('who', 'ai')->count(), 'لا ردّ آليّ بعد ردّ الموظّف');
        $this->assertSame(1, UserNotification::where('user_id', $employee->id)->count(), 'ومسؤول المحادثة يُنبَّه');

        $lawyer = $this->staff(Role::Lawyer);
        $case = $this->case($client, 'منظورة', ['assigned_lawyer_id' => $lawyer->id]);
        $case->messages()->create(['who' => 'lawyer', 'name' => 'المحامي', 'role' => 'ردّ', 'body' => 'تفضّل.', 'time_label' => 'الآن']);
        $case->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => 'سؤال.', 'time_label' => 'الآن']);

        (new GenerateCaseReplyJob($case, 'سؤال.'))->handle(app(LegalAiService::class));

        $this->assertSame(0, $case->messages()->where('who', 'ai')->count());
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
    }

    public function test_ai_still_replies_before_any_human_intervention(): void
    {
        $this->fakeAi();
        $ticket = $this->ticket($this->client());
        $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => 'سؤال.', 'time_label' => 'الآن']);

        $this->assertFalse(AiClientVoice::humanIntervened($ticket));
        (new GenerateTicketReplyJob($ticket, 'سؤال.'))->handle(app(LegalAiService::class));

        $this->assertSame(1, $ticket->messages()->where('who', 'ai')->count());
    }

    // ── ٦ · الصندوق ──

    public function test_review_inbox_shows_state_and_what_acceptance_will_do(): void
    {
        $exec = $this->exec($this->client(), 8);
        $state = AiReviewEntityState::for($this->studyRun($exec));

        $this->assertSame(ExecFlow::label(8), $state['status']);
        $this->assertStringContainsString('لا يُنشر للعميل', (string) $state['stale']);

        $this->assertNull(AiReviewEntityState::for($this->studyRun($this->exec($this->client(), 2)))['stale']);
    }

    private function caseDoc(LegalCase $case, string $by): CaseDocument
    {
        return CaseDocument::create(['case_id' => $case->id, 'name' => 'عقد.pdf', 'path' => 'x', 'uploaded_by' => $by, 'status' => 'مرفوع']);
    }
}
