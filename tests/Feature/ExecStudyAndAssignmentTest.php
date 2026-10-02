<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Mail\ExecutionEventMail;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiPromptRegistry;
use App\Services\LegalAiService;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\ExecutionCreation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **دراسةٌ تسبق السعر، وملفٌّ له صاحب** — الميزتان اللتان أقرّهما المالك في وحدة التنفيذ.
 *
 * ١) الملفّ المفتوح من قضيّة كان يصل المسعِّر بـ«قيمة المطالبة 0» و«المنفَّذ ضده —» وبلا
 *    دراسةٍ واحدة: `AnalyzeExecutionJob` يعود صامتاً لأنّ مرحلته 3، و`applyAnalysis` كانت
 *    ستسحبه إلى 1 أو 2 لو عمل. فصار جوهر القضيّة ينتقل معه، وتجري دراسته بالخلفية بلا
 *    تحريك مرحلته — والدراسة **مُعينٌ للتسعير لا بوّابةٌ قبله** (خيار المالك «أ»).
 *
 * ٢) «أوّل من يضغط قبول يملك الملفّ» يبقى كما هو، ويُضاف إليه: غيرُ المسنَد ملكُ الإدارة
 *    تُوجّهه، والموظّف المخوَّل يُوجّهه، ولا يُسعَّر ملفٌّ بلا صاحب.
 */
class ExecStudyAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── مساعدات ──

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'email' => 'c'.uniqid().'@t.sa']);
    }

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'email' => 'l'.uniqid().'@t.sa']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'email' => 'a'.uniqid().'@t.sa']);
    }

    /**
     * موظّفٌ بصلاحيّاته. «إدارة القضايا والأتعاب» شرطُ **فتح شاشة التنفيذ** نفسها،
     * و«إجراءات المحكمة والجلسات» هي ما يمنح الإسناد (قرار المالك) — فالمنع يُقاس
     * بحذف الثانية وحدها، وإلّا لم يبلغ الموظّف الشاشة أصلاً.
     *
     * @param  array<int, string>  $perms
     */
    private function employee(array $perms = ['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات']): User
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active', 'email' => 'e'.uniqid().'@t.sa']);
        $employee->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $employee;
    }

    /** مزوّدٌ مزيّف يعيد دراسةً كاملة بمدخلات التسعير الستّة. */
    private function fakeStudy(array $payload = []): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(array_merge([
                        'summary' => 'سند تنفيذيّ (حكم) مستوفٍ بعد فحص مستندات القضيّة.',
                        'missing' => [],
                        'procedures' => ['قيد الطلب في ناجز', 'الإفصاح عن الأصول'],
                        'readiness' => 'مكتمل — الصكّ نهائيّ وواجب النفاذ',
                        'difficulty' => 'متوسط',
                        'expected_procedures_count' => 4,
                        'duration_estimate' => '٣٠-٦٠ يوماً',
                        'recovery_indicators' => ['حساب بنكيّ مذكور في الصكّ'],
                        'risks' => ['منازعة تنفيذيّة محتملة'],
                    ], $payload), JSON_UNESCAPED_UNICODE)]]],
                ]],
            ], 200),
        ]);
    }

    /** قضيّةٌ صدر فيها الحكم، بتذكرتها الحاملة للمبلغ والخصم والمحكمة. */
    private function ruledCase(User $client, User $lawyer): LegalCase
    {
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TK-'.uniqid(),
            'type' => 'نزاع تجاري',
            'subject' => 'مطالبة مالية',
            'opponent_name' => 'مؤسسة الرمال التجارية',
            'claim_amount' => 250000,
            'court_name' => 'المحكمة التجارية بالرياض',
            'status' => 'مغلقة',
            'tone' => 'b-gray',
        ]);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket->id,
            'number' => 'CASE-'.uniqid(),
            'type' => 'نزاع تجاري',
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'status' => 'صدر الحكم',
            'tone' => 'b-cyan',
            'ruling' => 'إلزام المدّعى عليه بدفع 250000 ريال والمصاريف.',
        ]);

        $case->documents()->create([
            'name' => 'sakk.pdf', 'path' => "case-docs/{$case->id}/sakk.pdf", 'doc_type' => 'صك الحكم',
            'status' => 'مرفق', 'uploaded_by' => 'lawyer',
            'summary' => 'صكّ الحكم النهائيّ بإلزام المدّعى عليه.',
        ]);

        return $case;
    }

    private function exec(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-S-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'stage' => 2,
            'status' => ExecFlow::label(2),
            'tone' => ExecFlow::tone(2),
        ], $attrs));
    }

    private function act(User $actor, Execution $exec, string $action, array $payload = []): TestResponse
    {
        return $this->actingAs($actor)->post("/exec-flow/{$exec->number}/action", array_merge(['action' => $action], $payload));
    }

    // ── ١أ. جوهر القضيّة ينتقل مع ملفّها ──

    public function test_an_execution_opened_from_a_case_carries_its_amount_opponent_and_ruling(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $case = $this->ruledCase($client, $lawyer);

        $exec = ExecutionCreation::fromCase($case, $lawyer);

        $this->assertSame(250000, (int) $exec->amount, 'قيمة المطالبة من تذكرة القضيّة لا صفراً');
        $this->assertSame('مؤسسة الرمال التجارية', $exec->defendant, 'المنفَّذ ضدّه هو خصم القضيّة');
        $this->assertStringContainsString('إلزام المدّعى عليه', (string) $exec->notes, 'منطوق الحكم يصل المسعِّر');
        $this->assertStringContainsString('المحكمة التجارية بالرياض', (string) $exec->notes);
        // مستندات القضيّة **مرجعٌ لا نسخة**: أسماؤها على البطاقة، وملفّاتها تبقى للقضيّة
        $this->assertContains('صك الحكم', $exec->docs ?? []);
        $this->assertSame(0, $exec->documents()->count(), 'لا صفوف مستنداتٍ تشير إلى ملفّات القضيّة');
    }

    /** وما ليس في القضيّة لا يُختلق: تذكرةٌ بلا مبلغٍ ولا خصم تُبقي الحقول فارغة. */
    public function test_nothing_is_invented_when_the_case_has_no_amount_or_opponent(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-'.uniqid(), 'type' => 'عمالية',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'صدر الحكم', 'tone' => 'b-cyan',
        ]);

        $exec = ExecutionCreation::fromCase($case, $lawyer);

        $this->assertSame(0, (int) $exec->amount);
        $this->assertSame('', (string) $exec->defendant);
        $this->assertSame('', (string) $exec->notes);
    }

    // ── ١ب. الدراسة تجري ولا تحرّك ──

    public function test_the_study_runs_for_a_case_created_file_without_moving_its_stage(): void
    {
        $this->fakeStudy();
        $client = $this->client();
        $lawyer = $this->lawyer();
        $case = $this->ruledCase($client, $lawyer);

        $exec = ExecutionCreation::fromCase($case, $lawyer);
        $this->assertSame(3, (int) $exec->stage);

        // المهمّة نفسها التي يشغّلها الطابور — كانت تعود صامتةً لأنّ المرحلة > 1
        (new AnalyzeExecutionJob($exec))->handle(app(LegalAiService::class));
        $exec->refresh();

        $this->assertSame(3, (int) $exec->stage, 'الدراسة لا تُرجِع الملفّ إلى الاستقبال');
        $this->assertSame('تحديد الأتعاب', $exec->status);
        $this->assertTrue((bool) $exec->ai_done);
        $this->assertNotEmpty($exec->ai_summary);
        $this->assertSame('متوسط', $exec->ai_study['difficulty']);
        $this->assertSame(4, (int) $exec->ai_study['expected_procedures_count']);
        // ملاحظةٌ داخليّة بانتظار الاعتماد — لا رسالةٌ يقرؤها العميل
        $this->assertTrue($exec->messages()->where('who', 'note')->exists());
    }

    /** ودراسةٌ وصلت متأخّرة لملفٍّ بلغ العرض لا تُرجعه ولا تُبدّل سطر حالته. */
    public function test_a_late_study_never_drags_a_priced_file_backwards(): void
    {
        $exec = $this->exec($this->client(), [
            'stage' => 5, 'status' => ExecFlow::label(5), 'tone' => ExecFlow::tone(5),
            'last_action' => 'اعتمدت الإدارة الأتعاب وأُرسل العرض', 'fee' => 6000, 'fee_approved' => true,
        ]);

        ExecService::applyAnalysis($exec, [
            'summary' => 'دراسة متأخّرة.', 'missing' => [], 'procedures' => ['قيد الطلب'],
            'source' => AiSource::AiSuccess->value,
        ]);
        $exec->refresh();

        $this->assertSame(5, (int) $exec->stage);
        $this->assertSame('اعتمدت الإدارة الأتعاب وأُرسل العرض', $exec->last_action);
        $this->assertNotEmpty($exec->ai_summary, 'والحقول تُخزَّن رغم ذلك');
        $this->assertTrue($exec->messages()->where('who', 'note')->exists(), 'والملاحظة تُكتب');
        // ولا يُقال للعميل «طلبك قيد الدراسة» بعد أن وصله العرض
        $this->assertFalse(UserNotification::where('user_id', $exec->user_id)->where('body', 'like', '%قيد الدراسة%')->exists());
    }

    // ── ١ج. مدخلات التسعير: تحقّق وقيمٌ افتراضيّة آمنة ──

    public function test_the_pricing_inputs_validate_and_default_safely(): void
    {
        $full = AiOutputValidator::executionAnalysis([
            'summary' => 'ملخّص.',
            'readiness' => '  ناقص — ينقصه أصل السند  ',
            'difficulty' => 'معقّد',
            'expected_procedures_count' => '6',
            'duration_estimate' => '٩٠ يوماً',
            'recovery_indicators' => ['عقار', '  ', 'مركبة'],
            'risks' => ['إعسار'],
        ]);

        $this->assertSame('ناقص — ينقصه أصل السند', $full['readiness']);
        $this->assertSame('معقّد', $full['difficulty']);
        $this->assertSame(6, $full['expected_procedures_count'], 'العدد نصّاً يُقرأ عدداً');
        $this->assertSame(['عقار', 'مركبة'], $full['recovery_indicators'], 'والفراغات تُسقَط');

        // مخرجٌ قديم بالحقول الثلاثة وحدها يبقى صالحاً — والجديدة فارغةٌ معلنة لا مخترَعة
        $legacy = AiOutputValidator::executionAnalysis(['summary' => 'ملخّص قديم.']);
        $this->assertNotNull($legacy);
        $this->assertSame('', $legacy['readiness']);
        $this->assertSame('', $legacy['difficulty']);
        $this->assertSame(0, $legacy['expected_procedures_count']);
        $this->assertSame([], $legacy['risks']);

        // ودرجةُ تعقيدٍ خارج القائمة تُسقَط ولا تُقرَّب
        $odd = AiOutputValidator::executionAnalysis(['summary' => 'م.', 'difficulty' => 'متوسط إلى معقّد', 'expected_procedures_count' => -3]);
        $this->assertSame('', $odd['difficulty']);
        $this->assertSame(0, $odd['expected_procedures_count']);
    }

    /** والتعليمة تطلب المدخلات وتمنع السعر — هو الحاجز الذي يفصل مُعيناً عن مرساةٍ للقرار. */
    public function test_the_prompt_asks_for_pricing_inputs_but_forbids_a_price(): void
    {
        $text = AiPromptRegistry::executionAnalyzeSystem();

        foreach (['readiness', 'difficulty', 'expected_procedures_count', 'duration_estimate', 'recovery_indicators', 'risks'] as $key) {
            $this->assertStringContainsString($key, $text);
        }
        $this->assertStringContainsString('ولا تذكر مبلغ أتعابٍ ولا نسبةً', $text);
        $this->assertSame('v2', AiPromptRegistry::version('execution.analyze'));
    }

    // ── ١د. البطاقة: الدراسة محجوبةٌ عن العميل حتى الاعتماد ──

    public function test_the_study_is_withheld_from_the_client_until_a_lawyer_approves(): void
    {
        $lawyer = $this->lawyer();
        $exec = $this->exec($this->client(), [
            'ai_done' => true, 'ai_source' => AiSource::AiSuccess->value,
            'ai_summary' => 'خلاصة الدراسة.', 'ai_missing' => ['أصل السند'], 'ai_procedures' => ['قيد الطلب'],
            'ai_study' => [
                'readiness' => 'ناقص — ينقصه أصل السند', 'difficulty' => 'معقّد',
                'expected_procedures_count' => 5, 'duration_estimate' => '٩٠ يوماً',
                'recovery_indicators' => ['عقار مسجَّل'], 'risks' => ['إعسار محتمل'],
            ],
        ]);

        $this->assertNull($exec->toFlowCard(false, false)['study'], 'دراسةٌ لم يعتمدها محامٍ لا تصل العميل');
        $payload = json_encode($exec->toFlowCard(false, false), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('إعسار محتمل', (string) $payload, 'الحجب في الخادم لا في الواجهة');

        $staff = $exec->toFlowCard(false, true)['study'];
        $this->assertSame('معقّد', $staff['difficulty']);
        $this->assertSame(5, $staff['expectedProceduresCount']);
        $this->assertSame(['عقار مسجَّل'], $staff['recovery']);
        $this->assertTrue($staff['pending']);
        $this->assertFalse($staff['approved']);

        // النشر يُطلقها — والاعتماد وحده لا (`ai_released_at`: الاعتماد بعد تجاوز المرحلة داخليّ)
        $exec->update(['ai_approved_at' => now(), 'ai_approved_by' => $lawyer->id]);
        $this->assertNull($exec->fresh()->toFlowCard(false, false)['study'], 'معتمدةٌ غير منشورة لا تصل العميل');
        $exec->update(['ai_released_at' => now()]);
        $released = $exec->fresh()->toFlowCard(false, false)['study'];
        $this->assertNotNull($released);
        $this->assertSame('إعسار محتمل', $released['risks'][0]);
        $this->assertTrue($released['approved']);
        $this->assertFalse($released['pending']);
    }

    /** وصفٌّ سابقٌ للعمود يُقرأ بقيمٍ فارغة آمنة لا بانكسار. */
    public function test_a_row_from_before_the_column_reads_with_empty_defaults(): void
    {
        $exec = $this->exec($this->client(), [
            'ai_summary' => 'خلاصة قديمة.', 'ai_procedures' => ['قيد الطلب'],
            'ai_approved_at' => now(), 'ai_approved_by' => $this->lawyer()->id,
        ]);

        $study = $exec->toFlowCard(false, true)['study'];
        $this->assertSame('خلاصة قديمة.', $study['summary']);
        $this->assertSame('', $study['difficulty']);
        $this->assertSame(0, $study['expectedProceduresCount']);
        $this->assertSame([], $study['recovery']);

        // ولا دراسة أصلاً ⇒ null
        $this->assertNull($this->exec($this->client())->toFlowCard(false, true)['study']);
    }

    // ── ٢. الإسناد ──

    public function test_an_admin_assigns_a_lawyer_and_the_assignee_is_told_by_notice_and_mail(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $lawyer = $this->lawyer();
        $exec = $this->exec($this->client());

        $this->act($admin, $exec, 'assignLawyer', ['lawyer_id' => $lawyer->id])->assertRedirect();
        $exec->refresh();

        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $exec->assigned_lawyer);
        $this->assertTrue(UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%أُسند إليك ملفّ التنفيذ%')->exists());
        $this->assertTrue(
            Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'assigned' && $m->hasTo($lawyer->email))->isNotEmpty(),
            'بريد الإسناد القائم نفسه'
        );
        // رسالةٌ في سجلّ الملفّ باسم من أسند
        $msg = $exec->messages()->where('role', 'إسناد')->latest('id')->first();
        $this->assertNotNull($msg);
        $this->assertSame($admin->name, $msg->name);
        // وقيدٌ في السجلّ المركزيّ بمن صار مسؤولاً
        $audit = AuditLog::where('action', 'إجراء على ملف تنفيذ: assignLawyer')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString($lawyer->name, json_encode($audit->after_state, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_employee_with_the_court_permission_may_assign_an_unowned_file(): void
    {
        $lawyer = $this->lawyer();
        $exec = $this->exec($this->client());

        $this->act($this->employee(), $exec, 'assignLawyer', ['lawyer_id' => $lawyer->id])->assertRedirect();

        $this->assertSame($lawyer->id, $exec->refresh()->assigned_lawyer_id);
    }

    public function test_an_employee_without_the_permission_is_refused(): void
    {
        $exec = $this->exec($this->client());

        $this->act($this->employee(['إدارة القضايا والأتعاب']), $exec, 'assignLawyer', ['lawyer_id' => $this->lawyer()->id])
            ->assertForbidden();

        $this->assertNull($exec->refresh()->assigned_lawyer_id);
    }

    /** **ولا محامٍ يُسند** — الالتقاط بابُه «قبول» وحده، فلا يأخذ محامٍ ملفّاً بالإسناد. */
    public function test_no_lawyer_may_assign_not_even_to_themselves(): void
    {
        $lawyer = $this->lawyer();
        $exec = $this->exec($this->client());

        $this->act($lawyer, $exec, 'assignLawyer', ['lawyer_id' => $lawyer->id])->assertForbidden();

        $this->assertNull($exec->refresh()->assigned_lawyer_id);
    }

    /** إعادة الإسناد قرارُ توزيعٍ إداريّ: الإدارة تفعله، والموظّف لا ينزع ملفّاً من محامٍ. */
    public function test_reassignment_is_for_admins_only(): void
    {
        $first = $this->lawyer();
        $second = $this->lawyer();
        $exec = $this->exec($this->client(), ['assigned_lawyer' => $first->name, 'assigned_lawyer_id' => $first->id]);

        $this->act($this->employee(), $exec, 'assignLawyer', ['lawyer_id' => $second->id])->assertForbidden();
        $this->assertSame($first->id, $exec->refresh()->assigned_lawyer_id);

        $this->act($this->admin(), $exec, 'assignLawyer', ['lawyer_id' => $second->id])->assertRedirect();
        $this->assertSame($second->id, $exec->refresh()->assigned_lawyer_id);
        $this->assertStringContainsString(
            'أُعيد إسناد',
            (string) $exec->messages()->where('role', 'إسناد')->latest('id')->first()?->body
        );
    }

    public function test_a_closed_file_is_never_assigned(): void
    {
        $exec = $this->exec($this->client(), ['stage' => 9, 'status' => ExecFlow::label(9), 'tone' => ExecFlow::tone(9)]);

        $this->act($this->admin(), $exec, 'assignLawyer', ['lawyer_id' => $this->lawyer()->id])->assertStatus(422);

        $this->assertNull($exec->refresh()->assigned_lawyer_id);
    }

    /** والمُدخل يُقاس بقاعدة `ActiveLawyer` نفسها — لا عميلٌ ولا محامٍ موقوف. */
    public function test_only_an_active_lawyer_may_be_assigned(): void
    {
        $exec = $this->exec($this->client());
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);

        $this->act($this->admin(), $exec, 'assignLawyer', ['lawyer_id' => $this->client()->id])->assertSessionHasErrors('lawyer_id');
        $this->act($this->admin(), $exec, 'assignLawyer', ['lawyer_id' => $suspended->id])->assertSessionHasErrors('lawyer_id');

        $this->assertNull($exec->refresh()->assigned_lawyer_id);
    }

    // ── ٢ب. لا تسعير لملفٍّ بلا صاحب ──

    public function test_fees_are_refused_on_an_unassigned_file_and_allowed_after_assignment(): void
    {
        $admin = $this->admin();
        $lawyer = $this->lawyer();
        $exec = $this->exec($this->client(), ['stage' => 3, 'status' => ExecFlow::label(3), 'tone' => ExecFlow::tone(3), 'decision' => 'مقبول']);

        // الإدارة تسعّر مباشرةً (setFee) — مردودةٌ ما دام الملفّ بلا محامٍ
        $this->act($admin, $exec, 'setFee', ['fee' => 6000])->assertStatus(422);
        $this->assertSame(0, (int) $exec->refresh()->fee);

        // والمحامي كذلك (saveFee) قبل أن يُسنَد الملفّ لأحد
        $this->act($lawyer, $exec, 'saveFee', ['fee' => 6000])->assertStatus(422);
        $this->assertSame(0, (int) $exec->refresh()->fee);

        $this->act($admin, $exec, 'assignLawyer', ['lawyer_id' => $lawyer->id])->assertRedirect();
        $this->act($lawyer, $exec->refresh(), 'saveFee', ['fee' => 6000])->assertRedirect();

        $this->assertSame(6000, (int) $exec->refresh()->fee);
        $this->assertSame(4, (int) $exec->refresh()->stage);
    }

    // ── ٢ج. غير المسنَد عند «قيد الدراسة» يُرفع للإدارة ──

    public function test_reaching_study_without_an_owner_alerts_the_admins(): void
    {
        $admin = $this->admin();
        $exec = $this->exec($this->client(), ['stage' => 1, 'status' => ExecFlow::label(1), 'tone' => ExecFlow::tone(1)]);

        ExecService::refer($exec);

        $this->assertTrue(
            UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار إسناد الإدارة%')->exists(),
            'الإدارة تُوجّه ما لا صاحب له'
        );
    }

    /** واكتمالُ الدراسة الذكيّة طريقٌ ثانٍ للمرحلة 2 — التنبيه نفسه، بلا تكرار حين للملفّ صاحب. */
    public function test_a_completed_study_also_alerts_the_admins_but_not_when_the_file_has_an_owner(): void
    {
        $admin = $this->admin();
        $owned = $this->exec($this->client(), [
            'stage' => 1, 'status' => ExecFlow::label(1), 'tone' => ExecFlow::tone(1),
            'assigned_lawyer' => 'أ. فلان', 'assigned_lawyer_id' => $this->lawyer()->id,
        ]);

        ExecService::applyAnalysis($owned, [
            'summary' => 'مستوفٍ.', 'missing' => [], 'procedures' => ['قيد الطلب'],
            'source' => AiSource::AiSuccess->value,
        ]);

        $this->assertSame(2, (int) $owned->refresh()->stage);
        $this->assertFalse(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار إسناد الإدارة%')->exists());

        $orphan = $this->exec($this->client(), ['stage' => 1, 'status' => ExecFlow::label(1), 'tone' => ExecFlow::tone(1)]);
        ExecService::applyAnalysis($orphan, [
            'summary' => 'مستوفٍ.', 'missing' => [], 'procedures' => ['قيد الطلب'],
            'source' => AiSource::AiSuccess->value,
        ]);

        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار إسناد الإدارة%')->exists());
    }

    // ── ٢د. ما تحمله الشاشة ──

    public function test_the_screens_carry_the_roster_and_the_assign_flag_for_those_who_may_assign(): void
    {
        $exec = $this->exec($this->client());
        $lawyer = $this->lawyer();

        $this->actingAs($this->admin())->get(route('admin.execs'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('execs.0.canAssign', true)
                ->where('lawyers.0.id', $lawyer->id));

        $this->actingAs($this->employee())->get(route('employee.execs'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('execs.0.canAssign', true)->has('lawyers', 1));

        // موظّفٌ بلا الصلاحيّة: لا علمٌ ولا قائمة — فلا زرٌّ يردّه الخادم
        $this->actingAs($this->employee(['إدارة القضايا والأتعاب']))->get(route('employee.execs'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('execs.0.canAssign', false)->has('lawyers', 0));

        // والعميل لا يُسند ولا يعرف معرّف محاميه
        $this->actingAs($exec->user)->get(route('execs'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('execs.0.canAssign', false)->where('execs.0.lawyerId', null));
    }
}
