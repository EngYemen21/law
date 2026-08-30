<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiCost;
use App\Services\Ai\AiDataClass;
use App\Services\Ai\AiDecision;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use App\Services\Ai\AiUsage;
use App\Services\Ai\LegalKnowledge;
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
