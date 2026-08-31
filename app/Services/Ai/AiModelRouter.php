<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * توجيه النماذج حسب المهمّة — مطلب P6.
 *
 * الخطة تفرض «توجيه نماذج حسب نوع المهمّة: سريع للفرز، متعدّد الوسائط للوثائق، أقوى
 * لصياغة المسودات». وكان الاعتراض قائماً: **نموذجٌ واحد لكل مزوّد مُهيَّأ، فلا شيء
 * يُوجَّه بينه**. وهو اعتراض على القيمة لا على البنية — والبنية هي ما ينقص.
 *
 * فهذا الصنف يفتح الباب بلا أن يفتحه على فراغ: يقرأ تجاوزاً لكل مهمّة من الإعدادات،
 * ويقع على النموذج المهيَّأ حين لا تجاوز. **فلا يتغيّر سلوكٌ عند النشر**، ويصير
 * التوجيه ممكناً فور أن يقرّر المكتب نموذجاً ثانياً.
 *
 * ما لا يفعله عمداً: **لا يختار نموذجاً بالنيابة عن المكتب**. اختيار نموذج لمهمّة
 * قانونيّة قرارُ جودةٍ وكلفة، ولا يُشتقّ من اسم النموذج ولا من حدس مطوّر — بل من
 * مجموعة التقييم بعد تشغيلها على النموذجين.
 */
class AiModelRouter
{
    /**
     * النموذج الذي يُنادى لهذه المهمّة على هذا المزوّد.
     *
     * @param  string|null  $promptId  معرّف التعليمة، أو `null` لنداءٍ بلا مهمّة معلومة
     */
    public static function modelFor(string $provider, ?string $promptId = null): string
    {
        $configured = (string) config("services.{$provider}.model");

        if ($promptId === null) {
            return $configured;
        }

        $override = self::overrides()[$provider][$promptId] ?? null;

        return is_string($override) && trim($override) !== '' ? $override : $configured;
    }

    /**
     * التجاوزات المضبوطة: مزوّد ← مهمّة ← نموذج.
     *
     * @return array<string, array<string, string>>
     */
    public static function overrides(): array
    {
        $stored = json_decode((string) Setting::get('ai_model_overrides', ''), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * هل لهذه المهمّة توجيهٌ خاصّ؟ — تعرضه اللوحة كي لا يبقى التوجيه خفيّاً.
     *
     * توجيهٌ لا يُرى في الشاشة يُنتج مخرجات بنموذجٍ يظنّ القارئ أنه غيره، فيُنسب
     * تراجع الجودة إلى التعليمة وهو من النموذج.
     */
    public static function isRouted(string $provider, string $promptId): bool
    {
        $override = self::overrides()[$provider][$promptId] ?? null;

        return is_string($override) && trim($override) !== '';
    }

    /**
     * صفوف اللوحة: كل مهمّة وما تُنادى به فعلاً على كل مزوّد.
     *
     * @return array<int, array{task:string, models:array<string, array{model:string, routed:bool}>}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (array_keys(AiEvaluator::GATES) as $task) {
            $models = [];
            foreach (AiGateway::PROVIDERS as $provider) {
                $models[$provider] = [
                    'model' => self::modelFor($provider, $task),
                    'routed' => self::isRouted($provider, $task),
                ];
            }
            $rows[] = ['task' => $task, 'models' => $models];
        }

        return $rows;
    }
}
