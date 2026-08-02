<?php

namespace App\Support;

/**
 * أدوات الجوال المشتركة — توحيد للصيغة الدوليّة السعوديّة وتقنيع للعرض.
 * مصدر واحد يُستدعى من خدمات الرسائل/التحقّق (تفادي تكرار المنطق).
 */
class Phone
{
    /** توحيد الرقم للصيغة الدوليّة بلا + أو 00 (مثال: 0555555555 → 966555555555). */
    public static function intl(string $phone): string
    {
        $p = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($p, '00')) {
            $p = substr($p, 2);
        }
        if (str_starts_with($p, '966')) {
            return $p;
        }
        if (str_starts_with($p, '0')) {
            return '966'.substr($p, 1);
        }
        if (strlen($p) === 9 && str_starts_with($p, '5')) {
            return '966'.$p;
        }

        return $p;
    }

    /** تقنيع الجوال للعرض (يُبقي آخر رقمين) — «•••• •• XX». */
    public static function mask(string $phone): string
    {
        $p = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($p) >= 2 ? '•••• •• '.substr($p, -2) : $p;
    }
}
