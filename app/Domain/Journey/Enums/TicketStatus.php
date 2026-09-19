<?php

namespace App\Domain\Journey\Enums;

/**
 * **حالات التذكرة — الكتالوج الواحد** (المصدر الموثوق الوحيد للنظام).
 *
 * تتضمن الحالات التشغيلية المنضبطة من الاستلام وحتى الاعتماد،
 * وحالة جاهزية اتخاذ القرار ('بانتظار قرار المآل')،
 * والقرارين النهائيين الحتميين ('محولة إلى قضية'، 'مغلقة' المسببة).
 */
enum TicketStatus: string
{
    case New = 'جديدة';
    case Analyzing = 'قيد التحليل';
    case AwaitingDocs = 'بانتظار مستندات';
    case Referred = 'محالة للقسم القانوني';
    case AwaitingLawyerApproval = 'بانتظار اعتماد المستشار';
    case AwaitingAdminSummaryApproval = 'بانتظار اعتماد الإدارة للملخّص';
    case LegalOpinion = 'الرأي القانوني';
    case AwaitingBooking = 'بانتظار حجز الاستشارة';
    case AwaitingSchedule = 'بانتظار تحديد الموعد';
    case Scheduled = 'موعد مؤكد';
    case AwaitingSessionSummary = 'بانتظار ملخّص الجلسة';

    // ── محطة القرار النهائي ──
    case ReadyForOutcome = 'بانتظار قرار المآل';
    case AwaitingAdminOutcomeApproval = 'بانتظار اعتماد الإدارة للمسار';

    // ── الحالات النهائية (Terminal Decisions) ──
    case ConvertedToCase = 'محولة إلى قضية';
    case Closed = 'مغلقة';

    // ── قديمة / انتقالية: مكتملة (تنتقل لقرار المآل) وحالات مقروءة ──
    case Completed = 'مكتملة';
    case LegacyAwaitingPayment = 'بانتظار الدفع';
    case LegacyInExecution = 'قيد التنفيذ';
    case LegacyAwaitingResult = 'بانتظار اعتماد النتيجة';
    case LegacyAwaitingAdminResult = 'بانتظار اعتماد الإدارة';

    /** هل الحالة نهائية قطعية؟ (يُجمد السجل معها) */
    public function isTerminal(): bool
    {
        return in_array($this, [self::ConvertedToCase, self::Closed], true);
    }

    public function isFinal(): bool
    {
        return $this->isTerminal() || $this === self::Completed;
    }

    public function isLegacy(): bool
    {
        return in_array($this, [
            self::LegacyAwaitingPayment, self::LegacyInExecution,
            self::LegacyAwaitingResult, self::LegacyAwaitingAdminResult,
        ], true);
    }

    /** ما يقرؤه العميل — الحالات الداخليّة للاعتماد لا تُسمّى له. */
    public function clientLabel(): string
    {
        return match ($this) {
            self::AwaitingLawyerApproval, self::AwaitingAdminSummaryApproval => 'قيد إعداد الرأي القانوني',
            self::AwaitingSessionSummary => 'جارٍ إعداد ملخّص الجلسة',
            self::ReadyForOutcome => 'اكتملت الدراسة — بانتظار القرار النهائي',
            self::AwaitingAdminOutcomeApproval => 'قيد دراسة وتوجيه الإدارة العليا',
            self::ConvertedToCase => 'تم تحويل الطلب إلى قضية رسمية',
            self::Closed => 'طلب مكتمل ومغلق',
            default => $this->value,
        };
    }

    /** تسمية العميل لنصّ حالةٍ مخزَّن — وما ليس في الكتالوج يُعرض كما هو. */
    public static function labelForClient(?string $status): string
    {
        return self::tryFrom((string) $status)?->clientLabel() ?? (string) $status;
    }

    /**
     * خيارات «الحالة» التي تُعرض للعميل: تسمياته بلا تكرار، والقديمة المطويّة خارجها.
     *
     * @return list<string>
     */
    public static function clientLabels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            if (! $case->isLegacy()) {
                $labels[] = $case->clientLabel();
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return list<string> */
    public static function finals(): array
    {
        return [self::ConvertedToCase->value, self::Closed->value, self::Completed->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
