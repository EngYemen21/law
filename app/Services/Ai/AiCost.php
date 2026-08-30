<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * تقدير كلفة نداء — **من أسعار مُهيَّأة لا من أرقام مخترعة**.
 *
 * أسعار المزوّدين تتغيّر وتختلف بالمنطقة والعقد، فلا يجوز تثبيتها في الشيفرة.
 * وحين لا يُهيَّأ سعرٌ للنموذج تُعاد `null` — «لا كلفة معلومة» لا «صفر». الصفر
 * يقول إن النداء مجّانيّ وهو ادّعاء كاذب، ويُفسد كل ميزانية تُبنى عليه.
 *
 * التهيئة في `config/services.php`:
 *   'ai' => ['pricing' => ['gemini-2.5-flash' => ['input' => 0.075, 'output' => 0.30]]]
 * الأسعار **لكل مليون توكن** بعملة واحدة يحدّدها المكتب.
 */
final class AiCost
{
    /**
     * الكلفة التقديريّة، أو `null` حين لا سعر مُهيَّأ لهذا النموذج.
     *
     * تُعاد بست منازل: نداءٌ واحد قد يكلّف أجزاءً من الهللة، والتقريب المبكر
     * يُضيّع الفرق حين تُجمَع آلاف النداءات.
     */
    public static function estimate(?string $model, ?AiUsage $usage): ?float
    {
        if ($model === null || $usage === null || $usage->isEmpty()) {
            return null;
        }

        // الأولويّة لما تضبطه الإدارة من اللوحة، ثم التهيئة — فتعديل سعرٍ لا يلزمه نشر
        $rates = Setting::aiPricing()[$model] ?? null;
        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])) {
            return null; // لا سعر مُهيَّأ ⇒ لا كلفة مُدّعاة
        }

        $cost = ($usage->inputTokens / 1_000_000) * (float) $rates['input']
            + ($usage->outputTokens / 1_000_000) * (float) $rates['output'];

        return round($cost, 6);
    }

    /** هل يعرف النظام سعر هذا النموذج أصلاً؟ — للوحة التشغيل كي تميّز «صفر» من «مجهول». */
    public static function hasRate(?string $model): bool
    {
        $rates = $model === null ? null : (Setting::aiPricing()[$model] ?? null);

        return is_array($rates) && isset($rates['input'], $rates['output']);
    }
}
