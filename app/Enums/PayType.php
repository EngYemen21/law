<?php

namespace App\Enums;

/**
 * **نوع أجر الموظّف — المصدر الواحد** (`users.pay_type`).
 *
 * يقرؤه نموذج التسجيل (`Admin\StaffController`)، ووصف الأجر (`User::payLabel`)، وحساب المستحقّات
 * (`Finance\StaffEarnings`). النسبة **للمحامي وحده** (قرار المالك 2026-09-28): تُحسب من أتعاب قضاياه
 * وملفّات تنفيذه (`Finance\LawyerShare`)، وغير المحامي لا يُسند إليه ما يُحسب منه نسبة.
 */
enum PayType: string
{
    case Salary = 'salary';
    case Percent = 'pct';
    case SalaryAndPercent = 'both';
    case Session = 'session';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'راتب شهري ثابت',
            self::Percent => 'نسبة من الأتعاب',
            self::SalaryAndPercent => 'راتب + نسبة',
            self::Session => 'بالجلسة الواحدة',
        };
    }

    public function hasSalary(): bool
    {
        return $this === self::Salary || $this === self::SalaryAndPercent;
    }

    public function hasPercent(): bool
    {
        return $this === self::Percent || $this === self::SalaryAndPercent;
    }

    public function isSession(): bool
    {
        return $this === self::Session;
    }

    /** الأنواع المتاحة للدور: النسبة للمحامي وحده. */
    public function allowedFor(Role $role): bool
    {
        return ! $this->hasPercent() || $role === Role::Lawyer;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
