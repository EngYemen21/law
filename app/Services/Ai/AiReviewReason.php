<?php

namespace App\Services\Ai;

/**
 * سبب منظَّم لقرار المراجع — لا ملاحظة حرّة تضيع.
 *
 * الخطة صريحة: «يطلب سبباً منظماً عند الرفض حتى يتحوّل إلى بيانات تقييم لا إلى
 * ملاحظة ضائعة». فالرفض بنصٍّ حرّ يُصلح مخرجاً واحداً ثم يتبخّر؛ والرفض برمزٍ
 * معياريّ يتراكم فيكشف **نمطاً**: أن ثلث المخرجات تُرفض لاختلاق استشهاد يعني أن
 * قاعدة المعرفة ناقصة، لا أن النموذج «سيّئ».
 *
 * تُغذّي هذه الرموز مجموعة التقييم (P4) وتقرير الحوكمة الدوريّ.
 */
enum AiReviewReason: string
{
    /** تصنيف/قسم خاطئ. */
    case WrongClassification = 'wrong_classification';

    /** وقائع ناقصة أو محذوفة من الملخّص. */
    case MissingFacts = 'missing_facts';

    /** واقعة أو رقم لا أصل له في الملفّ — أخطر الأصناف. */
    case FabricatedFact = 'fabricated_fact';

    /** استشهاد نظاميّ بلا مصدر معتمد أو بمصدر لا يطابق. */
    case UnsupportedCitation = 'unsupported_citation';

    /** ترشيح محامٍ غير مناسب للتخصّص أو غير متاح. */
    case WrongLawyer = 'wrong_lawyer';

    /** المخرج ناقص أو مبتور. */
    case Incomplete = 'incomplete';

    /** الصياغة أو النبرة لا تليق بمخاطبة قضائيّة/عميل. */
    case Tone = 'tone';

    /** سبب خارج التصنيف — يلزمه شرح، ويُراجَع دورياً لعلّه يستحقّ رمزاً. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WrongClassification => 'تصنيف خاطئ',
            self::MissingFacts => 'وقائع ناقصة',
            self::FabricatedFact => 'واقعة مختلَقة',
            self::UnsupportedCitation => 'استشهاد بلا مصدر',
            self::WrongLawyer => 'محامٍ غير مناسب',
            self::Incomplete => 'مخرج ناقص',
            self::Tone => 'صياغة غير لائقة',
            self::Other => 'سبب آخر',
        };
    }

    /**
     * أصناف عالية الخطورة: تراجعُ الجودة فيها يمنع اعتماد نموذج أو Prompt جديد
     * **ولو تحسّن المتوسّط العام** (شرط صريح في الخطة).
     */
    public function isHighRisk(): bool
    {
        return in_array($this, [self::FabricatedFact, self::UnsupportedCitation], true);
    }

    /** @return array<int, array{value:string,label:string,high_risk:bool}> للواجهة */
    public static function options(): array
    {
        return array_map(fn (self $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'high_risk' => $c->isHighRisk(),
        ], self::cases());
    }
}
