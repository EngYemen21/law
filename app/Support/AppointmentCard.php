<?php

namespace App\Support;

use App\Models\Consult;

/**
 * بطاقة تأكيد الموعد داخل المحادثة — مصدر موحّد للترميز.
 * تُطابق تصميم المخطوطة الأصلية (عنصر .appt): ترويسة بتدرّج الهوية، صفوف بأيقونات،
 * وشريط تأكيد البريد. التنسيقات جاهزة في resources/css/babylon.css أسطر 199-210.
 *
 * ملاحظتان مقصودتان بخلاف المخطوطة:
 * - صفّ المستشار يستخدم أيقونة user لا clock (المخطوطة كانت تضع الساعة، وهو خطأ ظاهر فيها).
 * - رمز QR غير مُدرج: مولّد المخطوطة كان يرسم نمطاً عشوائياً لا يُشفّر شيئاً، ووضعه في
 *   الإنتاج يعني رمزاً يمسحه العميل فلا يحصل على شيء. يُضاف عند توفّر مكتبة QR حقيقية.
 */
class AppointmentCard
{
    /** أيقونات SVG مطابقة لمجموعة أيقونات الواجهة (resources/js/lib/icons.tsx) */
    private const ICONS = [
        'cal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'pin' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
        'video' => '<rect x="2" y="6" width="13" height="12" rx="2"/><path d="M22 8l-5 4 5 4z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 6l10 7L22 6"/>',
    ];

    private static function icon(string $key): string
    {
        return '<svg class="ic" viewBox="0 0 24 24">'.(self::ICONS[$key] ?? '').'</svg>';
    }

    /** الاسم الأول فقط (لقب + أول اسم) — لا يُظهَر الاسم الكامل للمحامي للعميل (يطابق lawyerFirst في الواجهة). */
    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (isset($parts[1]) && preg_match('/^(أ|د|م|الأستاذ|الأستاذة|المحامي|المحامية)\.?$/u', $parts[0])) {
            return $parts[0].' '.$parts[1];
        }

        return $parts[0] ?? $name;
    }

    /**
     * @param  array{label:string,place:string}  $meta  ناتج ConsultBooking::meta()
     */
    public static function render(Consult $consult, array $meta): string
    {
        $place = $consult->placeLabel() ?: $meta['place'];

        $rows = '<div class="row">'.self::icon('cal').'<b>'.e($consult->day).'</b><span>· '.e($consult->time).'</span></div>'
            .'<div class="row">'.self::icon('user').'<span>'.e(self::firstName($consult->lawyer)).'</span></div>'
            .'<div class="row">'.self::icon('pin').'<span>'.e($place).'</span></div>';

        // رابط الجلسة المرئية داخل المنصة حصراً
        if ($consult->channel === 'مرئية') {
            $rows .= '<div class="row">'.self::icon('video')
                .'<a href="'.e(url('/consults/room?ref='.$consult->ref)).'">الانتقال إلى الغرفة المرئية بالمنصة</a></div>';
        }

        return '<p>تم تأكيد موعدك. هذه بطاقة الموعد الخاصة بك:</p>'
            .'<div class="appt" style="margin-top:11px">'
            .'<div class="appt-top"><div><b>بطاقة موعد استشارة</b><span>'.e($consult->ref).'</span></div>'
            .'<div style="font-weight:800;font-size:13px">'.e($meta['label']).'</div></div>'
            .'<div class="appt-body"><div class="appt-meta">'.$rows.'</div></div>'
            .'<div class="email-note">'.self::icon('mail').' تم إرسال إشعار التأكيد إلى بريدك الإلكتروني.</div>'
            .'</div>';
    }
}
