<?php

namespace App\Services\Ai;

use App\Enums\AiSource;
use App\Models\Setting;

/**
 * بوّابة السياسة — تحوّل (المصدر + الثقة + حساسيّة المهمّة) إلى قرارٍ واحد صريح.
 *
 * سياسة المنتج المعلنة: «الذكاء الاصطناعي يجهّز ويلخّص ويصنّف ويقترح ويستخرج؛
 * أما المحامي أو الموظف المفوَّض فيعتمد ويرسل ويقرّر وينفّذ». هذا الصنف هو الموضع
 * الوحيد الذي تُترجَم فيه تلك السياسة إلى قرار — بدلاً من شرطٍ ضمنيّ مكرّر في كل
 * مسار لا يزن حساسيّة المهمّة ولا الثقة.
 *
 * **القاعدة غير القابلة للتفاوض:** مهمّة عالية الحساسيّة لا تُقبل آلياً مهما بلغت
 * ثقتها. رأي قانونيّ ومسودّة لائحة وتصنيف يغيّر مسار القضية تمرّ بإنسان — دائماً.
 */
class AiPolicyGate
{
    /**
     * حساسيّة كل مهمّة. `high` ⇒ مراجعة بشريّة إلزاميّة مهما كانت الثقة.
     *
     * @var array<string, string>
     */
    public const SENSITIVITY = [
        // منخفضة: ردّ إجرائيّ لا يحمل رأياً ولا يغيّر مساراً
        'chat.reply' => 'low',

        // متوسّطة: اقتراح يراه إنسان قبل أن يترتّب عليه أثر
        'ticket.triage' => 'medium',
        'document.analyze' => 'medium',

        // عالية: رأي قانونيّ، أو مسودّة رسميّة، أو قرار يغيّر مسار الملفّ
        'consult.analyze' => 'high',
        'consult.summary' => 'high',
        'execution.analyze' => 'high',
        'ticket.summary' => 'high',
        'case.classify' => 'high',
        'case.pleading' => 'high',
        'assistant.draft' => 'high',
        'meeting.summary' => 'high',
        // مخرجها يُنشئ مهامّ في النظام: قرارٌ مختلَق واحد يُنشئ التزاماً لم يتّفق عليه أحد
        'meeting.decisions' => 'high',
    ];

    /**
     * العتبة الافتراضيّة — **قيمة أوّليّة لا نهائيّة**. المعايرة الفعليّة تجري من
     * لوحة التحكّم (`Setting::aiAutoAcceptThreshold`) بعد تشغيل مجموعة التقييم،
     * فقرار العتبة قانونيّ لا هندسيّ ولا يصحّ أن يمرّ عبر تعديل كود ونشر.
     */
    public const DEFAULT_THRESHOLD = 70;

    /** العتبة السارية الآن: ما ضبطته الإدارة، وإلّا الافتراضيّة. */
    public static function threshold(): int
    {
        return Setting::aiAutoAcceptThreshold();
    }

    /**
     * القرار في مخرجٍ واحد.
     *
     * @param  string  $taskType  معرّف التعليمة كما في `AiPromptRegistry::PROMPTS` —
     *                            المفردات نفسها لا مفردات ثانية. والحساسيّة تُقاس بدقّة
     *                            التعليمة لا بخشونة نوع المهمّة: `ticket.triage` اقتراحٌ
     *                            متوسّط بينما `ticket.summary` رأيٌ للمحامي عالي الحساسيّة،
     *                            وكلاهما ضمن «التذاكر».
     * @param  int|null  $confidence  الدرجة المشتقّة خادمياً، أو `null` = لا قياس
     * @param  bool  $hasUsableOutput  أوُجد مخرج صالح للعرض (ولو قالباً)؟
     */
    public static function decide(
        string $taskType,
        AiSource $source,
        ?int $confidence = null,
        bool $hasUsableOutput = true,
    ): AiDecision {
        // لا مخرج صالحاً أصلاً — لا تحليل ولا قالب: عملٌ بشريّ من الصفر
        if (! $hasUsableOutput) {
            return AiDecision::Reject;
        }

        // مخرج قائم بلا تحليل فعليّ: قالب احتياطيّ يُعرض بوصفه كذلك
        if (! $source->isRealAnalysis()) {
            return AiDecision::Fallback;
        }

        // المهام عالية الحساسيّة: لا قبول آليّ مهما بلغت الثقة
        if (self::sensitivity($taskType) === 'high') {
            return AiDecision::NeedsReview;
        }

        // منخفضة الحساسيّة: تُقبل بلا عتبة (ردّ إجرائيّ لا يحمل رأياً)
        if (self::sensitivity($taskType) === 'low') {
            return AiDecision::Accept;
        }

        // متوسّطة: لا قياس ⇒ لا قبول آليّ. «تعذّر القياس» ليس «ثقة كافية».
        if ($confidence === null) {
            return AiDecision::NeedsReview;
        }

        return $confidence >= self::threshold()
            ? AiDecision::Accept
            : AiDecision::NeedsReview;
    }

    /** حساسيّة مهمّة؛ غير المسجَّلة تُعامَل **عالية** — الافتراض الآمن لا المتساهل. */
    public static function sensitivity(string $taskType): string
    {
        return self::SENSITIVITY[$taskType] ?? 'high';
    }

    /** هل تُلزم هذه المهمّة بمراجعة بشريّة دائماً؟ */
    public static function requiresHumanReview(string $taskType): bool
    {
        return self::sensitivity($taskType) === 'high';
    }
}
