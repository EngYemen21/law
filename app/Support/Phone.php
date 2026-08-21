<?php

namespace App\Support;

/**
 * أدوات الجوال المشتركة — توحيد للصيغة الدوليّة وتقنيع للعرض.
 * مصدر واحد يُستدعى من خدمات الرسائل/التحقّق (تفادي تكرار المنطق).
 */
class Phone
{
    /** نمط القبول: محليّ سعوديّ (05xxxxxxxx) أو دوليّ كامل (+CC…) بطول 8–15 رقماً. */
    public const RULE = 'regex:/^(?:05\d{8}|\+?[1-9]\d{7,14})$/';

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

        // رقم دوليّ كامل (مثل 967779475324): يُعاد بعد التنظيف — كان يمرّ بلا تطبيع
        // فيصل لمزوّد الرسائل بصيغة غير صالحة ويفشل الإرسال صامتاً.
        return $p;
    }

    /** هل الرقم بصيغة صالحة للإرسال (بعد التطبيع)؟ */
    public static function isSendable(string $phone): bool
    {
        $p = self::intl($phone);

        return strlen($p) >= 8 && strlen($p) <= 15;
    }

    /** تقنيع الجوال للعرض (يُبقي آخر رقمين) — «•••• •• XX». */
    public static function mask(string $phone): string
    {
        $p = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($p) >= 2 ? '•••• •• '.substr($p, -2) : $p;
    }
}
