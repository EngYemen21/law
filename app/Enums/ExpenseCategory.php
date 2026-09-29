<?php

namespace App\Enums;

/**
 * **تصنيف مصروفات المكتب** (`expenses.category`) — ثابتٌ في الشيفرة مع «أخرى» (قرار المالك 2026-09-29).
 *
 * **الرواتب ومستحقّات الموظّفين ليست هنا**: تُصرف من «المستحقّات والصرف» (`StaffPayout`) ولها سند
 * صرفها، وتقرير الأرباح والخسائر يقرؤها من هناك — فتصنيفٌ لها هنا يعدّها مرّتين.
 */
enum ExpenseCategory: string
{
    case Rent = 'rent';
    case GovernmentFees = 'government_fees';
    case Utilities = 'utilities';
    case Subscriptions = 'subscriptions';
    case OfficeSupplies = 'office_supplies';
    case Maintenance = 'maintenance';
    case Marketing = 'marketing';
    case Transport = 'transport';
    case ProfessionalServices = 'professional_services';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'إيجار',
            self::GovernmentFees => 'رسوم حكوميّة وقضائيّة',
            self::Utilities => 'كهرباء ومياه واتصالات',
            self::Subscriptions => 'اشتراكات وأنظمة',
            self::OfficeSupplies => 'قرطاسيّة ومستلزمات مكتب',
            self::Maintenance => 'صيانة',
            self::Marketing => 'تسويق وإعلان',
            self::Transport => 'نقل ومواصلات',
            self::ProfessionalServices => 'خدمات مهنيّة خارجيّة',
            self::Other => 'أخرى',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
