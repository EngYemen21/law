<?php

namespace App\Domain\Journey\Enums;

use App\Support\ExecFlow;

/**
 * **حالات طلب وملف التنفيذ القضائي — الكتالوج الواحد.**
 *
 * يوحّد حالات التنفيذ القضائي (المراحل العشر، و«مغلق» تشترك فيها الصفوف القديمة) في تعداد موثوق وحيد.
 */
enum ExecutionStatus: string
{
    case NewRequest = 'طلب جديد';
    case AiAnalysis = 'تحليل ذكي';
    case UnderStudy = 'قيد الدراسة';
    case FeeEstimation = 'تحديد الأتعاب';
    case AdminApproval = 'اعتماد الإدارة';
    case ServiceOffer = 'عرض الخدمة';
    case Payment = 'السداد';
    case PendingNajiz = 'بانتظار الرفع في ناجز';
    case InProgress = 'قيد التنفيذ';
    case Closed = 'مغلق';
    // «مكتمل» (صفوف الإغلاق القديمة) حُذفت 2026-09-19 — لا يكتبها شيء، والخادم بيئة تطوير

    /** مرحلة الحالة على خط تدفق التنفيذ (0 إلى 9) */
    public function stage(): int
    {
        return match ($this) {
            self::NewRequest => 0,
            self::AiAnalysis => 1,
            self::UnderStudy => 2,
            self::FeeEstimation => 3,
            self::AdminApproval => 4,
            self::ServiceOffer => 5,
            self::Payment => 6,
            self::PendingNajiz => 7,
            self::InProgress => 8,
            self::Closed => 9,
        };
    }

    /** النغمة البصرية للشارة */
    public function tone(): string
    {
        return ExecFlow::tone($this->stage());
    }

    /** التسمية المبسطة المعروضة للعميل */
    public function clientLabel(): string
    {
        return match ($this) {
            self::NewRequest => 'طلب جديد',
            self::AiAnalysis => 'قيد التحليل الذكي',
            self::UnderStudy => 'قيد دراسة المحامي',
            self::FeeEstimation => 'قيد تحديد الأتعاب',
            self::AdminApproval => 'مراجعة واعتماد العرض',
            self::ServiceOffer => 'عرض الأتعاب جاهز',
            self::Payment => 'بانتظار سداد الأتعاب',
            self::PendingNajiz => 'بانتظار الرفع في ناجز',
            self::InProgress => 'قيد التنفيذ القضائي',
            self::Closed => 'مغلق',
        };
    }

    /** هل الملف منتهٍ/مغلق؟ */
    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /** هل الحالة نهائية؟ */
    public function isTerminal(): bool
    {
        return $this->isClosed();
    }

    /** اشتقاق الحالة من رقم المرحلة */
    public static function fromStage(int $stage): self
    {
        return match ($stage) {
            0 => self::NewRequest,
            1 => self::AiAnalysis,
            2 => self::UnderStudy,
            3 => self::FeeEstimation,
            4 => self::AdminApproval,
            5 => self::ServiceOffer,
            6 => self::Payment,
            7 => self::PendingNajiz,
            8 => self::InProgress,
            default => self::Closed,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
