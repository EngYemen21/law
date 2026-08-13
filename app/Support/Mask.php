<?php

namespace App\Support;

/** إخفاء بيانات حساسة عند العرض لدور العميل — نظير خادميّ لـ resources/js/lib/utils.ts (maskLawyer). */
class Mask
{
    /** يطابق maskLawyer في الواجهة حرفياً — تشفير اسم المحامي لدور العميل. */
    public static function lawyer(?string $name): string
    {
        if (! $name || $name === '—') {
            return $name ?: '—';
        }

        $core = preg_replace('/^أ\.?\s*/u', '', $name) ?? $name;
        $parts = preg_split('/\s+/u', trim($core)) ?: [];
        $f = $parts[0] ?? '';
        $masked = mb_substr($f, 0, 1).'••••'.mb_substr($f, -1);
        $second = isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'•••' : '';

        return 'أ. '.$masked.$second.' (مشفّر)';
    }
}
