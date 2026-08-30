<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Services\Ai\AiDecision;
use App\Services\Ai\AiPolicyGate;
use App\Services\Ai\AiPromptRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بوّابة السياسة — الموضع الوحيد الذي تُترجَم فيه سياسة المنتج إلى قرار.
 *
 * «الذكاء الاصطناعي يجهّز ويلخّص ويصنّف ويقترح ويستخرج؛ أما المحامي أو الموظف
 * المفوَّض فيعتمد ويرسل ويقرّر وينفّذ». وكان القرار قبلها شرطاً ضمنياً مكرّراً
 * (`$isRealAnalysis ? completed : needs_review`) لا يزن حساسيّة المهمّة ولا الثقة.
 */
class AiPolicyGateTest extends TestCase
{
    // البوّابة تقرأ العتبة السارية من الإعدادات (نمط Setting::vatRate القائم)
    use RefreshDatabase;

    // ── القاعدة غير القابلة للتفاوض ──

    /** رأي قانونيّ أو مسودّة رسميّة لا تُقبل آلياً مهما بلغت ثقتها. */
    public function test_high_sensitivity_never_auto_accepts_even_at_full_confidence(): void
    {
        foreach (['consult.analyze', 'case.pleading', 'ticket.summary', 'execution.analyze', 'assistant.draft'] as $task) {
            $this->assertSame(
                AiDecision::NeedsReview,
                AiPolicyGate::decide($task, AiSource::AiSuccess, confidence: 100),
                "المهمّة «{$task}» عالية الحساسيّة ولا يجوز قبولها آلياً"
            );
        }
    }

    /** المهمّة غير المسجَّلة تُعامَل عالية — الافتراض الآمن لا المتساهل. */
    public function test_unknown_task_defaults_to_the_safe_side(): void
    {
        $this->assertSame('high', AiPolicyGate::sensitivity('مهمّة.لم.تُسجَّل'));
        $this->assertSame(
            AiDecision::NeedsReview,
            AiPolicyGate::decide('مهمّة.لم.تُسجَّل', AiSource::AiSuccess, confidence: 100)
        );
    }

    // ── المصدر يسبق كل شيء ──

    public function test_fallback_is_never_accepted_whatever_the_task(): void
    {
        foreach (['chat.reply', 'ticket.triage', 'consult.analyze'] as $task) {
            $this->assertSame(
                AiDecision::Fallback,
                AiPolicyGate::decide($task, AiSource::Fallback, confidence: 100)
            );
        }
    }

    public function test_no_usable_output_is_rejected_outright(): void
    {
        $this->assertSame(
            AiDecision::Reject,
            AiPolicyGate::decide('document.analyze', AiSource::ManualRequired, hasUsableOutput: false)
        );
        // حتى تحليل ناجح بلا مخرج صالح يُرفض — الأولويّة للمخرج لا للمصدر
        $this->assertSame(
            AiDecision::Reject,
            AiPolicyGate::decide('ticket.triage', AiSource::AiSuccess, confidence: 90, hasUsableOutput: false)
        );
    }

    // ── المتوسّطة: العتبة والقياس ──

    public function test_medium_sensitivity_respects_the_threshold(): void
    {
        // العتبة السارية لا الثابت: صارت تُعاير من لوحة التحكّم، والثابت افتراضٌ فقط
        $above = AiPolicyGate::threshold();

        $this->assertSame(AiDecision::Accept, AiPolicyGate::decide('ticket.triage', AiSource::AiSuccess, $above));
        $this->assertSame(AiDecision::NeedsReview, AiPolicyGate::decide('ticket.triage', AiSource::AiSuccess, $above - 1));
    }

    /** «تعذّر القياس» ليس «ثقة كافية» — لا قياس ⇒ لا قبول آليّ. */
    public function test_unmeasured_confidence_does_not_auto_accept(): void
    {
        $this->assertSame(
            AiDecision::NeedsReview,
            AiPolicyGate::decide('document.analyze', AiSource::AiSuccess, confidence: null)
        );
    }

    // ── المنخفضة ──

    public function test_low_sensitivity_accepts_without_a_threshold(): void
    {
        $this->assertSame(AiDecision::Accept, AiPolicyGate::decide('chat.reply', AiSource::AiSuccess, confidence: null));
        $this->assertSame(AiDecision::Accept, AiPolicyGate::decide('chat.reply', AiSource::AiSuccess, confidence: 10));
    }

    /** الردّ الإجرائيّ وحده منخفض — كل ما عداه يحمل رأياً أو يغيّر مساراً. */
    public function test_only_the_procedural_reply_is_low_sensitivity(): void
    {
        $low = array_keys(array_filter(AiPolicyGate::SENSITIVITY, fn ($s) => $s === 'low'));

        $this->assertSame(['chat.reply'], $low);
    }

    // ── الاعتماد البشريّ ──

    public function test_human_review_is_required_for_every_legal_output(): void
    {
        foreach (['consult.analyze', 'consult.summary', 'case.pleading', 'assistant.draft', 'meeting.summary'] as $task) {
            $this->assertTrue(AiPolicyGate::requiresHumanReview($task), "«{$task}» مخرج قانونيّ يلزمه اعتماد بشريّ");
        }
    }

    // ── الاتّساق مع بقيّة الطبقات ──

    /** كل تعليمة مسجَّلة لها حساسيّة معلنة — لا مهمّة تمرّ بلا تصنيف. */
    public function test_every_registered_prompt_has_a_declared_sensitivity(): void
    {
        foreach (array_keys(AiPromptRegistry::PROMPTS) as $id) {
            $this->assertArrayHasKey($id, AiPolicyGate::SENSITIVITY, "التعليمة «{$id}» بلا حساسيّة معلنة");
        }
    }

    public function test_decisions_map_to_recordable_statuses(): void
    {
        $this->assertSame('completed', AiDecision::Accept->status());
        $this->assertSame('needs_review', AiDecision::NeedsReview->status());
        $this->assertSame('needs_review', AiDecision::Fallback->status());
        $this->assertSame('failed', AiDecision::Reject->status());
    }
}
