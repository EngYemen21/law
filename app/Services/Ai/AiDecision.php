<?php

namespace App\Services\Ai;

/**
 * قرار بوّابة السياسة في مخرج ذكاء اصطناعيّ — الجواب الوحيد عن: **ماذا نفعل به؟**
 *
 * كان القرار مبعثراً وضمنياً: `$isRealAnalysis ? completed : needs_review` مكرّراً
 * في ثلاثة مواضع، بلا وزنٍ لحساسيّة المهمّة ولا للثقة. فرأيٌ قانونيّ ناجح ومسودّة
 * لائحة ناجحة كانا يُسجَّلان «مكتمل» تماماً كتصنيف مستندٍ روتينيّ.
 */
enum AiDecision: string
{
    /** صالح للاستعمال بلا مراجعة — للمهام منخفضة المخاطر وحدها. */
    case Accept = 'accept';

    /** مخرج حقيقيّ لكنه لا يُعتمد قبل مراجعة إنسان مفوَّض. */
    case NeedsReview = 'needs_review';

    /** لا تحليل — قالب حتميّ يُعرض بوصفه كذلك. */
    case Fallback = 'fallback';

    /** لا مخرج صالحاً أصلاً؛ يلزم عمل بشريّ من الصفر. */
    case Reject = 'reject';

    /** الحالة المقابلة في `ai_runs.status`. */
    public function status(): string
    {
        return match ($this) {
            self::Accept => 'completed',
            self::NeedsReview, self::Fallback => 'needs_review',
            self::Reject => 'failed',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'مقبول',
            self::NeedsReview => 'يتطلّب مراجعة بشرية',
            self::Fallback => 'قالب احتياطيّ',
            self::Reject => 'مرفوض — يلزم عمل يدويّ',
        };
    }
}
