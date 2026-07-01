<?php

namespace App\Enums;

// أدوار المنصة — تطابق ROLE_DEFS في الواجهة
enum Role: string
{
    case Client = 'client';
    case Employee = 'employee';
    case Lawyer = 'lawyer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'العميل',
            self::Employee => 'الموظف',
            self::Lawyer => 'المحامي',
            self::Admin => 'الإدارة',
        };
    }

    // الصفحة الرئيسية لكل دور (مسار Inertia)
    public function home(): string
    {
        return match ($this) {
            self::Client => '/dashboard',
            self::Employee => '/employee/dashboard',
            self::Lawyer => '/lawyer/dashboard',
            self::Admin => '/admin/dashboard',
        };
    }

    // بادئة المسار التي يملكها الدور (للحماية)
    public function prefix(): string
    {
        return match ($this) {
            self::Client => '/',
            self::Employee => '/employee',
            self::Lawyer => '/lawyer',
            self::Admin => '/admin',
        };
    }
}
