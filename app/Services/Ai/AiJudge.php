<?php

namespace App\Services\Ai;

use App\Services\LegalAiService;

/**
 * نموذج حكمٍ **مستقلّ عن نموذج الإنتاج** — مطلب P6.
 *
 * الخطة تنصّ: «تقييم داخليّ للمخرجات: نموذج حكم **منفصل عن نموذج الإنتاج** … تجنّب
 * التقييم الذاتيّ لنفس النموذج».
 *
 * وموضعه الذي ينقص فعلاً: المهامّ **حرّة النصّ** (المسودّات والملخّصات). مجموعة
 * التقييم تقيس فيها البنية وحدها — أن الحقول موجودة وأن الاستشهاد يقابل مصدراً —
 * ولا تقيس أهي مكتوبة كما ينبغي. فذلك حكمُ جودة لا مطابقةُ مخطّط.
 *
 * **القاعدة غير القابلة للتفاوض هنا:** لا يحكم النموذج على نفسه. إن كان المنتج هو
 * المزوّد الوحيد المتاح، يُعاد `null` — «تعذّر الحكم المستقلّ» — لا حكمٌ ذاتيّ يُقدَّم
 * على أنه مستقلّ. حكمُ النموذج على مخرجه يميل إلى قبوله، فيصير القياس تزكية.
 */
class AiJudge
{
    /** تعليمة الحَكَم — قصيرة ومقيَّدة: حكمٌ وسبب، لا إعادة كتابة. */
    public const SYSTEM = 'أنت مُحكِّم مستقلّ لمخرجات نظام قانونيّ. احكم على المخرج المعروض '
        .'بمعيارين فقط: (1) هل يلتزم بما ورد في المعطيات دون إضافة وقائع؟ (2) هل صياغته '
        .'مهنيّة ومفهومة؟ لا تُعِد كتابة المخرج ولا تقترح بديلاً. أعد JSON فقط: '
        .'{"acceptable": true|false, "reason": "سبب موجز"}. لا نصّ خارج JSON.';

    /**
     * حكمٌ مستقلّ على مخرج.
     *
     * @param  string  $producedBy  المزوّد الذي أنتج المخرج ('gemini' أو 'glm')
     * @return array{judge:string, acceptable:bool, reason:string}|null `null` = تعذّر حكمٌ مستقلّ
     */
    public static function assess(string $output, string $producedBy, ?callable $caller = null): ?array
    {
        $judge = self::independentProvider($producedBy);

        if ($judge === null) {
            return null; // لا مزوّد آخر متاح — والحكم الذاتيّ ليس حكماً مستقلّاً
        }

        $caller ??= fn (string $provider, string $system, string $prompt) => app(LegalAiService::class)
            ->evaluationCall($system, $prompt, $provider)['data'] ?? null;

        $data = $caller($judge, self::SYSTEM, "المخرج المعروض للتحكيم:\n\n".$output);

        if (! is_array($data) || ! array_key_exists('acceptable', $data) || ! is_bool($data['acceptable'])) {
            // حكمٌ بلا بنية صالحة ليس حكماً — ولا يُفسَّر «رفضاً»
            return null;
        }

        return [
            'judge' => $judge,
            'acceptable' => $data['acceptable'],
            'reason' => trim((string) ($data['reason'] ?? '')),
        ];
    }

    /**
     * مزوّدٌ **غير** الذي أنتج المخرج، ومُهيَّأ وغير مهدَّأ.
     *
     * يُعاد `null` حين لا يوجد — فيُعلَن تعذّر الحكم المستقلّ بدل أن يُستبدَل بحكمٍ ذاتيّ.
     */
    public static function independentProvider(string $producedBy): ?string
    {
        foreach (AiGateway::PROVIDERS as $provider) {
            if ($provider !== $producedBy && ! empty(config("services.{$provider}.key"))) {
                return $provider;
            }
        }

        return null;
    }
}
