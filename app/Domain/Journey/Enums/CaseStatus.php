<?php

namespace App\Domain\Journey\Enums;

/**
 * **حالات القضية القضائية — الكتالوج الواحد.**
 *
 * يوحّد حالات القضية في تعداد رسمي منضبط مع مراحلها ونغماتها وتسمياتها للعميل.
 */
enum CaseStatus: string
{
    // المرحلة 0: الأتعاب
    case AwaitingFeeApproval = 'بانتظار اعتماد الأتعاب';
    case AwaitingFeePayment = 'بانتظار سداد الأتعاب';

    // المرحلة 1: التحضير واللائحة
    case InPreparation = 'قيد التحضير';

    // المرحلة 2: رفع ناجز والقيد والترافع
    case AwaitingRegistration = 'بانتظار القيد';
    case InCourt = 'منظورة';

    // المرحلة 3: الحكم
    case Judged = 'صدر الحكم';

    // المرحلة 4: الإغلاق والأرشفة
    case Closed = 'مغلقة';
    case Archived = 'مؤرشفة';

    /** مرحلة الحالة على خط سير القضية (0 إلى 4) */
    public function stage(): int
    {
        return match ($this) {
            self::AwaitingFeeApproval, self::AwaitingFeePayment => 0,
            self::InPreparation => 1,
            self::AwaitingRegistration, self::InCourt => 2,
            self::Judged => 3,
            self::Closed, self::Archived => 4,
        };
    }

    /** النغمة البصرية للشارة */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingFeeApproval, self::AwaitingFeePayment, self::AwaitingRegistration => 'b-amber',
            self::InPreparation, self::InCourt => 'b-blue',
            self::Judged => 'b-cyan',
            self::Closed, self::Archived => 'b-grey',
        };
    }

    /**
     * تسمية العميل — تسميات مبسطة واضحة للعميل النهائي
     */
    public function clientLabel(): string
    {
        return match ($this) {
            self::AwaitingFeeApproval => 'بانتظار اعتماد الأتعاب',
            self::AwaitingFeePayment => 'بانتظار سداد الأتعاب',
            self::InPreparation => 'إعداد اللائحة وخطة العمل',
            self::AwaitingRegistration => 'تم الرفع — بانتظار قيد المحكمة',
            self::InCourt => 'منظورة في المحكمة',
            self::Judged => 'صدر الحكم القضائي',
            self::Closed => 'مغلقة',
            self::Archived => 'مؤرشفة',
        };
    }

    /** هل القضية في مرحلة الأتعاب؟ */
    public function isPendingFee(): bool
    {
        return in_array($this, [self::AwaitingFeeApproval, self::AwaitingFeePayment], true);
    }

    /** هل القضية نشطة؟ */
    public function isActive(): bool
    {
        return in_array($this, [self::InPreparation, self::AwaitingRegistration, self::InCourt], true);
    }

    /** هل القضية منتهية (مغلقة أو مؤرشفة)؟ */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Archived], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
