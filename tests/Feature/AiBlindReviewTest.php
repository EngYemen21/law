<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiBlindReview;
use App\Models\AiRun;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\AiBlindSample;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الطبقة الثالثة — مراجعة محامٍ لعيّنة عمياء.
 *
 * الخطة: «عيّنة دوريّة يراجعها محامٍ **لا يعرف** أنها من الذكاء … تُقارن نتيجته بحكم
 * الطبقة الثانية، واختلافهما يكشف عيباً في **المعايير** لا في النموذج».
 */
class AiBlindReviewTest extends TestCase
{
    use RefreshDatabase;

    private function lawyer(): User
    {
        $this->seed(PermissionSeeder::class);

        return User::factory()->create(['role' => Role::Admin]);
    }

    // لا تُسمَّ `run`: `PHPUnit\Framework\TestCase::run()` نهائيّة، وتجاوزها خطأ قاتل
    private function aiRun(array $overrides = []): AiRun
    {
        return AiRun::create(array_merge([
            'task_type' => 'ticket.triage',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'confidence' => 85,
        ], $overrides));
    }

    // ── العمى عقدٌ خادميّ ──

    /**
     * ما قبل الحكم **لا يُرسَل أصلاً**: إخفاؤه في الواجهة يُبقيه في الحمولة، ومن يفتح
     * أدوات المطوّر يراه — فيسقط العمى وتصير الشهادة غير مستقلّة.
     */
    public function test_the_machine_verdict_never_reaches_the_browser_before_judgement(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun(['confidence' => 93]);
        AiBlindSample::draw($lawyer, 1);

        $this->actingAs($lawyer)
            ->get(route('admin.ai-blind-review'))
            ->assertInertia(fn ($page) => $page
                ->where('items.0.judged', false)
                ->where('items.0.machineStatus', null)
                ->where('items.0.machineConfidence', null)
                ->where('items.0.agrees', null)
            );
    }

    /** وبعد الحكم يُكشف كلّه للمقارنة والتعلّم. */
    public function test_everything_is_revealed_once_the_verdict_is_recorded(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun(['confidence' => 93]);
        AiBlindSample::draw($lawyer, 1);
        $review = AiBlindReview::firstOrFail();

        $this->actingAs($lawyer)
            ->post(route('admin.ai-blind-review.judge', $review), ['verdict' => AiReviewAction::Accept->value])
            ->assertRedirect();

        $this->actingAs($lawyer)
            ->get(route('admin.ai-blind-review'))
            ->assertInertia(fn ($page) => $page
                ->where('items.0.judged', true)
                ->where('items.0.machineConfidence', 93)
                ->where('items.0.agrees', true)
            );
    }

    /** والحكم يُختم بوقته قبل الكشف — فصلُ الزمنين يجعل «الأعمى» واقعةً لا ادّعاءً. */
    public function test_the_judgement_is_stamped_and_counted_as_blind(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun();
        AiBlindSample::draw($lawyer, 1);
        $review = AiBlindReview::firstOrFail();

        $this->actingAs($lawyer)->post(route('admin.ai-blind-review.judge', $review), [
            'verdict' => AiReviewAction::Accept->value,
        ]);

        $review->refresh();
        $this->assertNotNull($review->judged_at);
        $this->assertTrue($review->wasBlind());
    }

    /** ولا يُعاد الحكم بعد الكشف: حكمٌ ثانٍ يعرف صاحبه النتيجة ليس شهادة. */
    public function test_a_verdict_cannot_be_revised_after_the_reveal(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun();
        AiBlindSample::draw($lawyer, 1);
        $review = AiBlindReview::firstOrFail();

        $this->actingAs($lawyer)->post(route('admin.ai-blind-review.judge', $review), ['verdict' => AiReviewAction::Accept->value]);
        $this->actingAs($lawyer)
            ->post(route('admin.ai-blind-review.judge', $review), ['verdict' => AiReviewAction::Reject->value, 'reason' => AiReviewReason::Incomplete->value])
            ->assertSessionHasErrors('verdict');

        $this->assertSame(AiReviewAction::Accept, $review->fresh()->verdict);
    }

    // ── سحب العيّنة ──

    /** لا يُسحب ما سبق أن راجعه المراجع نفسه — الحكم على المرئيّ سابقاً ليس أعمى. */
    public function test_a_reviewer_never_draws_the_same_output_twice(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun();

        $this->assertSame(1, AiBlindSample::draw($lawyer, 5));
        $this->assertSame(0, AiBlindSample::draw($lawyer, 5), 'لا شيء جديد يُسحب');
        $this->assertSame(1, AiBlindReview::count());
    }

    /** والاحتياطيّ ليس مخرج نموذج — الحكم عليه لا يقول شيئاً عن النموذج. */
    public function test_fallback_outputs_are_never_sampled(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun(['source' => AiSource::Fallback->value]);
        $this->aiRun(['confidence' => null]);

        $this->assertSame(0, AiBlindSample::draw($lawyer, 10));
    }

    /**
     * حكم الآلة **يُجمَّد وقت السحب**: معايرة العتبة لاحقاً تغيّر الحكم، فتصير المقارنة
     * بين حكمٍ بشريّ قديم وحكمٍ آليّ جديد — وهي مقارنة بلا معنى.
     */
    public function test_the_machine_verdict_is_frozen_at_draw_time(): void
    {
        $lawyer = $this->lawyer();
        $run = $this->aiRun(['status' => AiRun::STATUS_COMPLETED]);
        AiBlindSample::draw($lawyer, 1);

        $run->update(['status' => AiRun::STATUS_NEEDS_REVIEW]);

        $this->assertSame(AiRun::STATUS_COMPLETED, AiBlindReview::firstOrFail()->machine_status);
    }

    // ── الحصيلة ──

    /**
     * الخلافان ليسا سواءً: **تساهل** الآلة يصل الملفَّ بلا مراجعة، و**تشدّدها** يكلّف
     * وقتاً. فيُعدّان منفصلين، وإلّا أخفى مجموعُهما أيَّهما وقع.
     */
    public function test_machine_leniency_and_strictness_are_counted_apart(): void
    {
        $lawyer = $this->lawyer();

        // الآلة قبِلت والمحامي رفض ⇒ تساهل
        $lax = $this->aiRun(['status' => AiRun::STATUS_COMPLETED]);
        // الآلة صعّدت والمحامي قبِل ⇒ تشدّد
        $strict = $this->aiRun(['status' => AiRun::STATUS_NEEDS_REVIEW]);

        AiBlindSample::draw($lawyer, 10);

        foreach (AiBlindReview::all() as $review) {
            $accept = $review->ai_run_id === $strict->id;
            $this->actingAs($lawyer)->post(route('admin.ai-blind-review.judge', $review), [
                'verdict' => $accept ? AiReviewAction::Accept->value : AiReviewAction::Reject->value,
                'reason' => $accept ? null : AiReviewReason::MissingFacts->value,
            ]);
        }

        $summary = AiBlindSample::summary($lawyer);

        $this->assertSame(2, $summary['judged']);
        $this->assertSame(0.0, $summary['agreement'], 'اختلفا في الحالتين');
        $this->assertSame(1, $summary['machineTooLax'], "الآلة قبلت ما رفضه المحامي (#{$lax->id})");
        $this->assertSame(1, $summary['machineTooStrict']);
    }

    /** ولا أحكام ⇒ «غير مقيس» لا صفر: نسبةٌ من صفرٍ ليست صفر اتّفاق. */
    public function test_agreement_is_null_not_zero_before_any_judgement(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun();
        AiBlindSample::draw($lawyer, 1);

        $summary = AiBlindSample::summary($lawyer);

        $this->assertNull($summary['agreement']);
        $this->assertSame(1, $summary['pending']);
    }

    /** والرفض يلزمه سبب منظَّم هنا كما في صندوق المراجعة. */
    public function test_a_rejection_requires_a_structured_reason(): void
    {
        $lawyer = $this->lawyer();
        $this->aiRun();
        AiBlindSample::draw($lawyer, 1);

        $this->actingAs($lawyer)
            ->post(route('admin.ai-blind-review.judge', AiBlindReview::firstOrFail()), [
                'verdict' => AiReviewAction::Reject->value,
            ])
            ->assertSessionHasErrors('reason');
    }

    /** ولا يحكم أحدٌ على عيّنة غيره. */
    public function test_a_reviewer_cannot_judge_someone_elses_sample(): void
    {
        $owner = $this->lawyer();
        $other = User::factory()->create(['role' => Role::Admin]);
        $this->aiRun();
        AiBlindSample::draw($owner, 1);

        $this->actingAs($other)
            ->post(route('admin.ai-blind-review.judge', AiBlindReview::firstOrFail()), ['verdict' => AiReviewAction::Accept->value])
            ->assertForbidden();
    }
    // ── وصول المحامي: الفعل بيد أهله ──

    /**
     * الخطة تنصّ أن **المحامي** يراجع العيّنة العمياء. وحصرُها في لوحة الإدارة يجعل
     * الفعل الموصوف مستحيلاً على صاحبه — شاشةٌ تصف عملاً لا يستطيعه من كُلِّف به.
     */
    public function test_a_lawyer_can_reach_the_blind_review_screen(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($lawyer)->get(route('lawyer.ai-blind-review'))->assertOk();
    }

    /**
     * **ويحفظ فعلاً**: الشاشة كانت تُرسل إلى `/admin` ثابتاً، فتُعرض للمحامي ثم تُمنع
     * عند الحفظ بـ403 — شاشةٌ تُرى ولا تعمل، وهي أسوأ من غيابها.
     */
    public function test_a_lawyer_can_actually_record_a_verdict_not_just_view(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->aiRun();
        AiBlindSample::draw($lawyer, 1);

        $this->actingAs($lawyer)
            ->post(route('lawyer.ai-blind-review.judge', AiBlindReview::firstOrFail()), [
                'verdict' => AiReviewAction::Accept->value,
            ])
            ->assertRedirect();

        $this->assertNotNull(AiBlindReview::firstOrFail()->judged_at);
    }

    /** واعتماد المصادر كذلك: المحامي المسؤول هو من يعتمد كما تنصّ الخطة. */
    public function test_a_lawyer_can_approve_a_legal_source(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $source = LegalSource::create([
            'ref' => 'LS-LAWYER',
            'system_name' => 'نظام تجريبيّ',
            'text' => 'نصّ المادّة.',
            'jurisdiction' => 'السعودية',
            'effective_from' => '2020-01-01',
            'source_owner' => 'الفريق القانونيّ',
            'status' => LegalSource::STATUS_DRAFT,
        ]);

        $this->actingAs($lawyer)
            ->post(route('lawyer.legal-sources.approve', $source))
            ->assertRedirect();

        $source->refresh();
        $this->assertSame(LegalSource::STATUS_APPROVED, $source->status);
        // والاعتماد يُسجَّل باسم المحامي — وهو أهل الفعل
        $this->assertSame($lawyer->id, $source->reviewed_by);
    }
}
