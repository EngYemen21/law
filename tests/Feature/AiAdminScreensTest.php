<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalSource;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiCost;
use App\Services\Ai\AiDataClass;
use App\Services\Ai\AiDecision;
use App\Services\Ai\AiEvaluator;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use App\Services\Ai\AiUsage;
use App\Services\Ai\LegalKnowledge;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * شاشتا لوحة التحكّم اللتان تُخرجان القرارات من الشيفرة إلى يد المكتب.
 *
 * كانت ثلاثة قرارات حبيسة الكود: اعتماد المصدر القانونيّ (بلا مسار أصلاً — طريق
 * مسدود)، وعتبة القبول الآليّ، وأسعار النماذج ومدد الاحتفاظ. وكلّها قرارات مكتب:
 * الاعتماد قانونيّ، والعتبة قانونيّة، والأسعار محاسبيّة، والاحتفاظ نظاميّ.
 */
class AiAdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);

        return User::factory()->create(['role' => Role::Admin]);
    }

    private function source(array $overrides = []): LegalSource
    {
        return LegalSource::create(array_merge([
            'ref' => 'LS-'.uniqid(),
            'system_name' => 'نظام تجريبيّ للاختبار',
            'article_no' => '5',
            'text' => 'نصّ المادّة التجريبيّة.',
            'jurisdiction' => 'السعودية',
            'domain' => 'تجاري',
            'effective_from' => '2020-01-01',
            'source_owner' => 'الفريق القانونيّ',
            'status' => LegalSource::STATUS_DRAFT,
        ], $overrides));
    }

    // ── اعتماد المصادر: الحلقة التي كانت مفقودة ──

    /**
     * قبل الشاشة كان `ai:import-sources` يُدخل «مسودة» ولا شيء يعتمدها — فالمصادر
     * تدخل إلى طريق مسدود ولا يُستشهد بأيّها أبداً.
     */
    public function test_approving_a_source_makes_it_citable(): void
    {
        $source = $this->source();
        $admin = $this->admin();

        $this->assertCount(0, LegalKnowledge::retrieve('تجاري'), 'المسودّة لا تُسترجَع');

        $this->actingAs($admin)
            ->post(route('admin.legal-sources.approve', $source))
            ->assertRedirect();

        $this->assertSame([$source->ref], LegalKnowledge::retrieve('تجاري')->pluck('ref')->all());
    }

    /** لا اعتماد مجهول صاحبه: الحالة والمُعتمِد وتاريخ مراجعته تُكتب معاً. */
    public function test_approval_records_who_reviewed_it_and_when(): void
    {
        $source = $this->source();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.legal-sources.approve', $source));

        $source->refresh();
        $this->assertSame(LegalSource::STATUS_APPROVED, $source->status);
        $this->assertSame($admin->id, $source->reviewed_by);
        $this->assertNotNull($source->legal_review_at);
    }

    /** الإيقاف يُخرجه من الاسترجاع فوراً دون حذف — يبقى لتدقيق ما استُشهد به سابقاً. */
    public function test_suspending_removes_it_from_retrieval_without_deleting(): void
    {
        $source = $this->source(['status' => LegalSource::STATUS_APPROVED]);

        $this->actingAs($this->admin())->post(route('admin.legal-sources.suspend', $source));

        $this->assertCount(0, LegalKnowledge::retrieve('تجاري'));
        $this->assertDatabaseHas('legal_sources', ['ref' => $source->ref]);
    }

    public function test_the_screen_shows_the_full_text_and_governing_fields(): void
    {
        $this->source(['ref' => 'LS-SHOWN', 'text' => 'نصّ يجب أن يراه المحامي كاملاً.']);

        $this->actingAs($this->admin())
            ->get(route('admin.legal-sources'))
            ->assertInertia(fn ($page) => $page
                ->component('admin/legal-sources')
                ->where('sources.0.ref', 'LS-SHOWN')
                // الاعتماد على ما قُرئ لا على ما أُخبِر عنه
                ->where('sources.0.text', 'نصّ يجب أن يراه المحامي كاملاً.')
                ->has('sources.0.sourceOwner')
                ->has('sources.0.effectiveFrom')
                ->where('stats.draft', 1)
            );
    }

    public function test_a_client_cannot_reach_the_sources_screen(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->getJson(route('admin.legal-sources'))->assertForbidden();
    }

    // ── قاعدة بحجمها الحقيقيّ ──

    /** 786 مادّة لا تُعرض في صفحة واحدة — الترقيم شرط قابليّة الاستعمال لا زينة. */
    public function test_the_list_is_paginated_and_filterable(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->source(['ref' => "LS-P-{$i}", 'system_name' => 'نظام الترقيم', 'text' => "نصّ المادّة {$i}."]);
        }
        $this->source(['ref' => 'LS-OTHER', 'system_name' => 'نظام آخر', 'text' => 'نصّ مختلف تماماً.']);

        $this->actingAs($this->admin())
            ->get(route('admin.legal-sources', ['system' => 'نظام الترقيم']))
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 30)
                ->has('sources', 25)
                ->where('pagination.lastPage', 2)
            );
    }

    /** المحامي يبحث عن حكمٍ لا عن ترقيم — فالبحث يمسّ نصّ المادّة نفسه. */
    public function test_search_matches_the_article_text_not_just_its_number(): void
    {
        $this->source(['ref' => 'LS-A', 'text' => 'يلتزم المتعاقد بتعويض الضرر الذي أحدثه.']);
        $this->source(['ref' => 'LS-B', 'text' => 'تحسب المدد بالتقويم الهجري.']);

        $this->actingAs($this->admin())
            ->get(route('admin.legal-sources', ['q' => 'تعويض']))
            ->assertInertia(fn ($page) => $page
                ->has('sources', 1)
                ->where('sources.0.ref', 'LS-A')
            );
    }

    // ── اعتماد نظام كامل ──

    public function test_a_whole_system_can_be_approved_at_once(): void
    {
        foreach (range(1, 5) as $i) {
            $this->source(['ref' => "LS-SYS-{$i}", 'system_name' => 'نظام التنفيذ']);
        }
        $this->source(['ref' => 'LS-KEEP', 'system_name' => 'نظام آخر']);

        $this->actingAs($this->admin())
            ->post(route('admin.legal-sources.approve-system'), [
                'system' => 'نظام التنفيذ',
                'confirm' => 'نظام التنفيذ',
            ])
            ->assertRedirect();

        $this->assertSame(5, LegalSource::where('system_name', 'نظام التنفيذ')
            ->where('status', LegalSource::STATUS_APPROVED)->count());
        // ولا يمتدّ الاعتماد إلى نظامٍ لم يُقصد
        $this->assertSame(LegalSource::STATUS_DRAFT, LegalSource::where('ref', 'LS-KEEP')->value('status'));
    }

    /** فعلٌ واسع الأثر لا يُترك لزرٍّ يُضغط سهواً: يلزمه كتابة اسم النظام حرفياً. */
    public function test_approving_a_whole_system_requires_typing_its_name(): void
    {
        $this->source(['system_name' => 'نظام التنفيذ']);

        $this->actingAs($this->admin())
            ->post(route('admin.legal-sources.approve-system'), [
                'system' => 'نظام التنفيذ',
                'confirm' => 'نعم',
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, LegalSource::where('status', LegalSource::STATUS_APPROVED)->count());
    }

    /** والاعتماد الجماعيّ يسجّل المُعتمِد على كل مادّة — لا اعتماد مجهول صاحبه. */
    public function test_bulk_approval_still_records_the_reviewer_on_every_article(): void
    {
        $this->source(['ref' => 'LS-BULK', 'system_name' => 'نظام التنفيذ']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.legal-sources.approve-system'), [
            'system' => 'نظام التنفيذ',
            'confirm' => 'نظام التنفيذ',
        ]);

        $row = LegalSource::where('ref', 'LS-BULK')->first();
        $this->assertSame($admin->id, $row->reviewed_by);
        $this->assertNotNull($row->legal_review_at);
    }

    /**
     * نظامٌ اعتُمد ولم يبدأ سريانه لا يُسترجَع في وقائع اليوم. هذه ليست حالة نظريّة:
     * نظام التنفيذ الجديد نُشر 2026/05/01 ويُعمل به بعد (180) يوماً.
     */
    public function test_an_approved_but_not_yet_effective_source_is_not_retrievable(): void
    {
        $future = $this->source([
            'ref' => 'LS-FUTURE',
            'domain' => 'التنفيذ',
            'effective_from' => now()->addMonths(2)->toDateString(),
        ]);

        $this->actingAs($this->admin())->post(route('admin.legal-sources.approve', $future));

        $this->assertSame(LegalSource::STATUS_APPROVED, $future->fresh()->status);
        $this->assertCount(0, LegalKnowledge::retrieve('التنفيذ'), 'نصٌّ لم يبدأ سريانه لا يحكم واقعة اليوم');
    }

    /** والشاشة تقول ذلك صراحةً بدل أن تعرضه «معتمداً» فيُظنّ عاملاً. */
    public function test_the_screen_declares_a_source_that_is_not_yet_in_force(): void
    {
        $this->source(['ref' => 'LS-SOON', 'effective_from' => now()->addYear()->toDateString()]);

        $this->actingAs($this->admin())
            ->get(route('admin.legal-sources'))
            ->assertInertia(fn ($page) => $page
                ->where('sources.0.inForce', false)
                ->where('systems.0.inForce', false)
            );
    }

    // ── معايرة العتبة من اللوحة ──

    /** المعايرة قرار قانونيّ — كانت تلزمها تعديل كود ونشر. */
    public function test_the_threshold_can_be_calibrated_without_a_deploy(): void
    {
        $this->assertSame(AiPolicyGate::DEFAULT_THRESHOLD, AiPolicyGate::threshold());

        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.threshold'), ['threshold' => 85])
            ->assertRedirect();

        $this->assertSame(85, AiPolicyGate::threshold());
    }

    /** والتغيير يغيّر القرار فعلاً لا الرقم المعروض وحده. */
    public function test_calibrating_the_threshold_changes_the_actual_decision(): void
    {
        Setting::put('ai_auto_accept_threshold', 90);

        $this->assertSame(
            AiDecision::NeedsReview,
            AiPolicyGate::decide('ticket.triage', AiSource::AiSuccess, confidence: 80),
            'ثقة 80 دون عتبة 90 ⇒ تصعيد'
        );

        Setting::put('ai_auto_accept_threshold', 75);

        $this->assertSame(
            AiDecision::Accept,
            AiPolicyGate::decide('ticket.triage', AiSource::AiSuccess, confidence: 80)
        );
    }

    public function test_an_out_of_range_threshold_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.threshold'), ['threshold' => 140])
            ->assertSessionHasErrors('threshold');
    }

    // ── الأسعار ومدد الاحتفاظ ──

    public function test_pricing_saved_from_the_screen_drives_cost(): void
    {
        $this->actingAs($this->admin())->post(route('admin.ai-ops.pricing'), [
            'pricing' => [['model' => 'gemini-2.5-flash', 'input' => 1.0, 'output' => 4.0]],
        ])->assertRedirect();

        $this->assertSame(
            3.0,
            AiCost::estimate('gemini-2.5-flash', new AiUsage(1_000_000, 500_000))
        );
    }

    public function test_retention_saved_from_the_screen_overrides_the_default(): void
    {
        $before = AiDataClass::Restricted->retentionDays();

        $this->actingAs($this->admin())->post(route('admin.ai-ops.retention'), [
            'retention' => [AiDataClass::Restricted->value => 30],
        ])->assertRedirect();

        $this->assertNotSame($before, AiDataClass::Restricted->retentionDays());
        $this->assertSame(30, AiDataClass::Restricted->retentionDays());
    }

    /** الفراغ = «بلا حدّ» لا صفر — والصفر يعني حذفاً فورياً وهو معنى مختلف تماماً. */
    public function test_an_empty_retention_means_unlimited_not_zero(): void
    {
        $this->actingAs($this->admin())->post(route('admin.ai-ops.retention'), [
            'retention' => [AiDataClass::Confidential->value => null],
        ])->assertRedirect();

        $this->assertNull(AiDataClass::Confidential->retentionDays());
    }

    // ── خطّ الأساس على الشاشة ──

    /** أوّل تشغيل: تُقال حقيقتُه — «صار خطَّ الأساس» لا فرقٌ صفريّ يوهم بالثبات. */
    public function test_the_screen_says_there_is_no_baseline_on_the_first_run(): void
    {
        AiEvaluator::remember([['task' => 'ticket.triage', 'rate' => 1.0]], false, null);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-ops'))
            ->assertInertia(fn ($page) => $page
                ->where('evaluation.runsRecorded', 1)
                ->where('evaluation.diff', [])
                ->where('evaluation.baselineAt', null)
            );
    }

    /** وبعد تشغيلين يظهر الفرق موسوماً بالتراجع — لا رقمٌ مجرَّد. */
    public function test_the_screen_shows_the_diff_and_flags_a_regression(): void
    {
        AiEvaluator::remember([['task' => 'case.pleading', 'rate' => 0.95]], false, null);
        AiEvaluator::remember([['task' => 'case.pleading', 'rate' => 0.8]], false, null);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-ops'))
            ->assertInertia(fn ($page) => $page
                ->where('evaluation.diff.0.task', 'case.pleading')
                ->where('evaluation.diff.0.regressed', true)
                ->where('evaluation.diff.0.previous', 0.95)
                ->where('evaluation.diff.0.rate', 0.8)
            );
    }
    // ── مفاتيح المسارات ──

    /** الافتراض **مفعَّل**: غياب المفتاح ليس إطفاءً، وإلّا أطفأ النشرُ الأوّل كل شيء صامتاً. */
    public function test_every_path_is_enabled_until_explicitly_switched_off(): void
    {
        foreach (array_keys(AiEvaluator::GATES) as $task) {
            $this->assertTrue(Setting::aiTaskEnabled($task), "«{$task}» يجب أن يكون مفعَّلاً افتراضاً");
        }
    }

    /** وإطفاء مسار يُسقطه إلى الاحتياطيّ الموسوم لا إلى خطأ يراه العميل. */
    public function test_a_disabled_path_falls_back_instead_of_failing(): void
    {
        config(['services.gemini.key' => 'k']);
        Setting::put('ai_enabled_tasks', json_encode(['ticket.triage' => false]));

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-OFF-1',
            'type' => 'نزاع تجاري', 'department' => 'القضايا التجارية',
            'status' => 'جديدة', 'tone' => 'b-blue',
        ]);

        $result = app(LegalAiService::class)->triageTicket($ticket, 'تفاصيل النزاع.');

        $this->assertSame(AiSource::Fallback->value, $result['source'], 'احتياطيّ موسوم');
        $this->assertSame('القضايا التجارية', $result['department'], 'والقسم يبقى كما اختاره العميل');
        // ورمزٌ يميّز الإطفاء المتعمَّد عن عطلٍ يُنتظَر زواله
        $this->assertSame(AiFailure::TASK_DISABLED, $result['meta']['failure_code']);
    }

    /** المفاتيح تُحفَظ من الشاشة، ومسارٌ خارج البوّابات يُتجاهَل. */
    public function test_switches_are_saved_and_unknown_tasks_are_ignored(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.tasks'), ['tasks' => [
                'case.pleading' => false,
                'مسار.وهميّ' => false,
            ]])
            ->assertRedirect();

        $this->assertFalse(Setting::aiTaskEnabled('case.pleading'));
        $this->assertSame(['case.pleading'], array_keys(Setting::aiDisabledTasks()));
    }

    /** والشاشة تعرض حصيلة التقييم بجانب كل مفتاح — لا تفعيل بالحدس. */
    public function test_the_screen_shows_each_gate_result_beside_its_switch(): void
    {
        AiEvaluator::remember([[
            'task' => 'ticket.triage', 'rate' => 0.92, 'meets' => true,
        ]], false, null);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-ops'))
            ->assertInertia(fn ($page) => $page
                ->where('taskSwitches.0.task', 'ticket.triage')
                ->where('taskSwitches.0.enabled', true)
                ->where('taskSwitches.0.rate', 0.92)
                // مسارٌ لم يُقَس يُعلَن كذلك لا يُعرض صفراً
                ->where('taskSwitches.1.rate', null)
            );
    }
    // ── الميزانيّة من الشاشة ──

    public function test_the_budget_is_set_from_the_screen(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.budget'), ['cap' => 250, 'warnAt' => 75, 'stop' => true])
            ->assertRedirect();

        $budget = Setting::aiBudget();
        $this->assertSame(250.0, $budget['cap']);
        $this->assertSame(0.75, $budget['warnAt']);
        $this->assertTrue($budget['stop']);
    }

    /** السقف الفارغ **بلا سقف** لا صفراً — والصفر يمنع كل نداء، وهو معنى مختلف تماماً. */
    public function test_an_empty_cap_means_unlimited_not_zero(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.budget'), ['cap' => null, 'warnAt' => 80])
            ->assertRedirect();

        $this->assertNull(Setting::aiBudget()['cap']);
        $this->assertFalse(AiOpsMetrics::budgetStopsCalls());
    }

    /** والإيقاف مُطفأ ما لم يُطلَب صراحةً — لا يُفتَرض بالنيابة عن المكتب. */
    public function test_the_stop_switch_defaults_to_off_when_omitted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ai-ops.budget'), ['cap' => 100, 'warnAt' => 80]);

        $this->assertFalse(Setting::aiBudget()['stop']);
    }
    // ── لوحة الحوكمة ──

    public function test_the_ops_screen_carries_the_governance_agenda(): void
    {
        AiRun::create([
            'task_type' => 'consult', 'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED, 'trace_id' => (string) Str::uuid(),
            'review_action' => AiReviewAction::Reject->value,
            'review_reason' => AiReviewReason::FabricatedFact->value,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.ai-ops'))
            ->assertInertia(fn ($page) => $page
                ->component('admin/ai-ops')
                ->has('metrics')
                ->has('alerts')
                ->has('settings.threshold')
                ->has('settings.pricing')
                ->has('settings.retention')
                // الأخطر أولاً: تراجعه يمنع اعتماد نموذج جديد ولو تحسّن المتوسّط
                ->where('rejectionReasons.0.code', AiReviewReason::FabricatedFact->value)
                ->where('rejectionReasons.0.highRisk', true)
            );
    }
}
