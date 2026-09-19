<?php

namespace App\Domain\Journey\Enums;

/**
 * **أسباب إغلاق وإنهاء ملف التنفيذ القضائي** (نظام التنفيذ السعودي).
 *
 * يحدد كيف انتهى الحق التنفيذي استناداً إلى وقائع الإجراءات القضائية.
 */
enum ClosureExecReasonCode: string
{
    case FullSettlement = 'سداد كامل';
    case Settlement = 'تسوية';
    case Insolvency = 'إعسار';
    case Waiver = 'تنازل طالب التنفيذ';
    case Other = 'أخرى';

    public function label(): string
    {
        return match ($this) {
            self::FullSettlement => 'سداد كامل المبلغ محل السند التنفيذي',
            self::Settlement => 'اتفاق تسوية وإنهاء المطالبة بين الأطراف',
            self::Insolvency => 'ثبوت إعسار المنفذ ضده قضائياً',
            self::Waiver => 'تنازل طالب التنفيذ عن السند أو الإجراءات',
            self::Other => 'إنهاء الملف لسبب نظامي آخر',
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
