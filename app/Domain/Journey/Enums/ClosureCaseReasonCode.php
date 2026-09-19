<?php

namespace App\Domain\Journey\Enums;

/**
 * **كتالوج أسباب إغلاق القضية القضائية** (المرحلة ب — تسبيب الإغلاق القضائي).
 *
 * يمنع إغلاق أي قضية بلا تسبيب نظامي صريح يحدد كيف انتهت الدعوى.
 */
enum ClosureCaseReasonCode: string
{
    case RulingFinalized = 'RULING_FINALIZED';
    case JudgmentEnforced = 'JUDGMENT_ENFORCED';
    case AmicableSettlement = 'AMICABLE_SETTLEMENT';
    case ClaimRelinquished = 'CLAIM_RELINQUISHED';
    case NoCompetence = 'NO_COMPETENCE';
    case ClientRequest = 'CLIENT_REQUEST';
    case OtherWithJustification = 'OTHER_WITH_JUSTIFICATION';

    public function label(): string
    {
        return match ($this) {
            self::RulingFinalized => 'صدور حكم نهائي مكتسب القطعية واستيفاء الإجراءات',
            self::JudgmentEnforced => 'صدور الحكم وتنفيذه بالكامل واستلام الحقوق',
            self::AmicableSettlement => 'انتهاء النزاع بالصلح والتسوية الودية بين الأطراف',
            self::ClaimRelinquished => 'تنازل المدعي عن الدعوى أو التنازل عن الحكم',
            self::NoCompetence => 'الحكم بعدم الاختصاص أو صرف النظر لعدم الصحة',
            self::ClientRequest => 'طلب العميل الصريح إغلاق الملف والاكتفاء بما تم',
            self::OtherWithJustification => 'سبب نظامي آخر (مع تسبيب مفصل)',
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
