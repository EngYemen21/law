<?php

namespace App\Services\Ai;

/**
 * طوابير منفصلة حسب الحساسيّة والحجم — مطلب المرحلة P6.
 *
 * اليوم كل مهام الذكاء في الطابور الافتراضيّ نفسه مع البريد والأرشفة والتذكيرات.
 * فتحليل مستند PDF ثقيل يحتجز العامل، ويتأخّر خلفه فرزُ تذكرةٍ خفيف يفتحه عميل
 * الآن وينتظر ردّاً. الفصل يمنع أن يجوّع الثقيلُ الخفيفَ.
 *
 * والفصل بالحساسيّة لا بالحجم وحده: مسارٌ عالي المخاطر يمكن إيقافه أو تهدئته
 * وحده عند حادثة جودة، دون تعطيل الردود الإجرائيّة التي لا تحمل رأياً.
 */
final class AiQueue
{
    /** ردود ومهام لا تحمل رأياً قانونياً — أخفّها وأسرعها. */
    public const LOW_RISK = 'ai-low-risk';

    /** فحص المستندات: PDF وصور، نداءات طويلة وثقيلة. */
    public const DOCUMENTS = 'ai-documents';

    /** مخرجات قانونيّة تلزمها مراجعة بشريّة — تُفصل كي تُدار وتُوقَف وحدها. */
    public const LEGAL_REVIEW = 'ai-legal-review';

    /**
     * الطابور الفعليّ للمهمّة، أو `null` = الطابور الافتراضيّ.
     *
     * **مُطفأ افتراضياً عمداً.** الإنتاج يشغّل `queue:work --queue=default`، فنقلُ
     * المهام إلى طوابير جديدة بلا تحديث العامل يوقف **كل** معالجة الذكاء صامتةً —
     * لا خطأ ولا سجلّ، فقط مهامّ لا تُلتقط أبداً. يُفعَّل بـ`AI_SEPARATE_QUEUES=true`
     * **بعد** أن يصير العامل يستمع للطوابير الثلاثة:
     *
     *   queue:work --queue=ai-low-risk,ai-documents,ai-legal-review,default
     *
     * وهذا ما تفرضه الخطة: كل مرحلة خلف مفتاح، وتبدأ بلا أثر حتى يُقرَّر تفعيلها.
     */
    public static function resolve(string $promptId): ?string
    {
        return config('services.ai.separate_queues') ? self::for($promptId) : null;
    }

    /**
     * الطابور المناسب لمعرّف التعليمة. المهمّة غير المسجَّلة تذهب إلى
     * `LEGAL_REVIEW` — الافتراض الآمن: تُعامَل كأنها قانونيّة حتى يُقرَّر غير ذلك،
     * نظير `AiPolicyGate::sensitivity` التي تعدّ المجهول عالي الحساسيّة.
     */
    public static function for(string $promptId): string
    {
        return match ($promptId) {
            'chat.reply' => self::LOW_RISK,
            'ticket.triage' => self::LOW_RISK,
            'document.analyze' => self::DOCUMENTS,
            default => self::LEGAL_REVIEW,
        };
    }

    /** كل الطوابير — لأمر التشغيل ومراقبتها. */
    public static function all(): array
    {
        return [self::LOW_RISK, self::DOCUMENTS, self::LEGAL_REVIEW];
    }
}
