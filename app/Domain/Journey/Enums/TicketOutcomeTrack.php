<?php

namespace App\Domain\Journey\Enums;

/**
 * **مسارات مآل التذكرة الأربعة (الفرز والقرارات السيادية)**.
 */
enum TicketOutcomeTrack: string
{
    case Consultation = 'consultation';
    case Case = 'case';
    case Execution = 'execution';
    case Close = 'close';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'طلب استشارة قانونية',
            self::Case => 'تحويل إلى قضية رسمية',
            self::Execution => 'تحويل إلى ملف تنفيذ قضائي',
            self::Close => 'إلغاء / حفظ مسبّب',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Consultation => 'b-blue',
            self::Case => 'b-green',
            self::Execution => 'b-amber',
            self::Close => 'b-grey',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Consultation => 'chat',
            self::Case => 'scale',
            self::Execution => 'card',
            self::Close => 'close',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string, tone: string, icon: string}> */
    public static function options(): array
    {
        return array_map(fn (self $track) => [
            'value' => $track->value,
            'label' => $track->label(),
            'tone' => $track->tone(),
            'icon' => $track->icon(),
        ], self::cases());
    }
}
