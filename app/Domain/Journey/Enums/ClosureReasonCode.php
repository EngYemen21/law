<?php

namespace App\Domain\Journey\Enums;

/**
 * **كتالوج أسباب إغلاق التذكرة دون قضية** (القرار النهائي الصريح).
 *
 * يمنع إغلاق أي تذكرة بنص عام أو دون اختيار سبب نظامي وتدوين تسبيب مبرر.
 */
enum ClosureReasonCode: string
{
    case OpinionSatisfied = 'OPINION_SATISFIED';
    case SettledAmicably = 'SETTLED_AMICABLY';
    case NoLegalMerit = 'NO_LEGAL_MERIT';
    case OutsideFirmScope = 'OUTSIDE_FIRM_SCOPE';
    case ClientInactivityDrop = 'CLIENT_INACTIVITY_DROP';
    case ClientRequestedClosure = 'CLIENT_REQUESTED_CLOSURE';
    case OtherWithReason = 'OTHER_WITH_REASON';

    public function label(): string
    {
        return match ($this) {
            self::OpinionSatisfied => 'اكتفاء بالرأي القانوني دون وجود نزاع',
            self::SettledAmicably => 'تمت التسوية الودية والصلح بين الأطراف',
            self::NoLegalMerit => 'انعدام السند النظامي أو ضعف الجدوى من التقاضي',
            self::OutsideFirmScope => 'الموضوع يخرج عن نطاق اختصاص المكتب',
            self::ClientInactivityDrop => 'حفظ الملف لعدم تجاوب العميل واستكمال النواقص',
            self::ClientRequestedClosure => 'رغبة العميل الصريحة في عدم متابعة الإجراءات',
            self::OtherWithReason => 'سبب نظامي آخر (مع تسبيب مفصل)',
        };
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'code' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
