<?php

namespace App\Support;

/**
 * مصيّر بطاقة الموعد كـPDF حقيقي — يطابق حرفياً تصميم .apptx المعروض في resources/js/pages/appointments.tsx
 * (المنقول أصلاً من openAppt بالتصميم المرجعي) وCSS الفعلي في resources/css/babylon.css:681-707.
 * عائلة تصميم منفصلة عمداً عن ReportPrint (بطاقة مضغوطة بتدرّج بنفسجي لا مستند رسمي بأقسام)،
 * تشارك معه فقط مولّد QR الزخرفي المشترك (App\Support\Qr).
 */
class AppointmentCardPdf
{
    private const ICONS = [
        'cal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'scale' => '<path d="M12 3v18M7 21h10M5 7h14M5 7l-2 6a3 3 0 006 0L9 7M19 7l-2 6a3 3 0 006 0l-2-6M12 3l-3 4M12 3l3 4"/>',
        'doc' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
        'office' => '<path d="M3 21h18M5 21V5a1 1 0 011-1h8a1 1 0 011 1v16M15 21V9h4a1 1 0 011 1v11M8 8h2M8 12h2M8 16h2"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'check' => '<path d="M5 12l5 5L20 7"/>',
    ];

    private const STYLE = <<<'CSS'
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap');
        *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        body{margin:0;padding:0;background:#fff;font-family:'Tajawal',Tahoma,Arial,sans-serif;-webkit-font-smoothing:antialiased}
        .apptx{border-radius:18px;overflow:hidden;background:#fff;max-width:560px;margin:0 auto;border:1px solid #E1E8EE}
        .apptx-head{background:linear-gradient(135deg,#5B4BD6,#7C3AED 55%,#9061F9);color:#fff;padding:18px 20px 20px}
        .apptx-brand{display:flex;align-items:center;gap:11px;margin-bottom:15px}
        .apptx-logo{width:42px;height:42px;border-radius:11px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-weight:800;font-size:15px;letter-spacing:.5px}
        .apptx-brand b{font-size:15px;display:block;line-height:1.3}
        .apptx-brand .bs{font-size:10px;opacity:.85;letter-spacing:.6px}
        .apptx-title{font-size:12.5px;opacity:.92;margin-bottom:3px;display:flex;align-items:center;gap:6px}
        .apptx-no{font-size:26px;font-weight:800;letter-spacing:.5px;direction:ltr;text-align:right}
        .apptx-chips{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
        .apptx-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);padding:6px 12px;border-radius:11px;font-size:12.5px;font-weight:700;direction:rtl}
        .apptx-body{padding:18px 20px;display:flex;gap:18px;align-items:flex-start}
        .apptx-qr{flex-shrink:0;text-align:center;width:136px}
        .apptx-qr .qrbox{background:#fff;border:1px solid #E1E8EE;border-radius:12px;padding:8px;display:grid;place-items:center}
        .apptx-qr .qrbox svg{width:108px;height:108px}
        .apptx-qr p{font-size:10.5px;color:#607689;margin-top:9px;line-height:1.6}
        .apptx-rows{flex:1;display:flex;flex-direction:column;gap:13px;min-width:0}
        .apptx-row{display:flex;align-items:flex-start;gap:11px}
        .apptx-row .ri{width:34px;height:34px;border-radius:9px;background:#EAEEF1;display:grid;place-items:center;color:#7C3AED;flex-shrink:0}
        .apptx-row .rc{flex:1;min-width:0}
        .apptx-row .rl{font-size:10.5px;color:#607689;font-weight:600;margin-bottom:2px}
        .apptx-row .rv{font-size:12.5px;font-weight:700;color:#13314F;line-height:1.55}
        .apptx-pay{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:800}
        .apptx-pay.wait{background:#FBF1E3;color:#C0832B}
        .apptx-pay.paid{background:#E6F6EF;color:#1E9D6B}
        .apptx-foot{border-top:1px solid #E1E8EE;padding:12px 20px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:10.5px;color:#607689}
        .ic{width:20px;height:20px;stroke:currentColor;stroke-width:1.85;fill:none;stroke-linecap:round;stroke-linejoin:round}
        @page{size:A4;margin:14mm}
        CSS;

    private static function icon(string $name): string
    {
        return '<svg class="ic" viewBox="0 0 24 24">'.(self::ICONS[$name] ?? '').'</svg>';
    }

    /**
     * @param  array{no:string,type:string,day:string,time:string,place:string,client:string,lawyer:string,consultRef:string,address:string,paid:bool,payLabel:string,qrSeed:string}  $a
     */
    public static function html(array $a): string
    {
        $payTone = $a['paid'] ? 'paid' : 'wait';

        return '<html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>'.e($a['no']).'</title><style>'.self::STYLE.'</style></head><body>'
            .'<div class="apptx">'
            .'<div class="apptx-head">'
            .'<div class="apptx-brand"><div class="apptx-logo">LM</div><div><b>النظام الإداري لمكاتب المحاماة</b><span class="bs">LEGAL OFFICE MANAGEMENT · المواعيد القانونية</span></div></div>'
            .'<div class="apptx-title">'.self::icon('cal').' بطاقة موعد '.e($a['type']).'</div>'
            .'<div class="apptx-no">'.e($a['no']).'</div>'
            .'<div class="apptx-chips"><span class="apptx-chip">'.self::icon('cal').' '.e($a['day']).'</span><span class="apptx-chip">'.self::icon('clock').' '.e($a['time']).'</span><span class="apptx-chip">'.self::icon('pin').' '.e($a['place']).'</span></div>'
            .'</div>'
            .'<div class="apptx-body">'
            .'<div class="apptx-qr"><div class="qrbox">'.Qr::svg($a['qrSeed']).'</div><p>امسح لتأكيد الحضور<br>وبدء الجلسة</p></div>'
            .'<div class="apptx-rows">'
            .'<div class="apptx-row"><div class="ri">'.self::icon('user').'</div><div class="rc"><div class="rl">العميل</div><div class="rv">'.e($a['client']).'</div></div></div>'
            .'<div class="apptx-row"><div class="ri">'.self::icon('scale').'</div><div class="rc"><div class="rl">المحامي المكلّف</div><div class="rv">'.e($a['lawyer']).'</div></div></div>'
            .'<div class="apptx-row"><div class="ri">'.self::icon('doc').'</div><div class="rc"><div class="rl">رقم الاستشارة</div><div class="rv" style="direction:ltr;text-align:right">'.e($a['consultRef']).'</div></div></div>'
            .'<div class="apptx-row"><div class="ri">'.self::icon('office').'</div><div class="rc"><div class="rl">العنوان</div><div class="rv">'.e($a['address']).'</div></div></div>'
            .'<div class="apptx-row"><div class="ri">'.self::icon('card').'</div><div class="rc"><div class="rl">حالة السداد</div><div class="rv"><span class="apptx-pay '.$payTone.'">'.self::icon('check').' '.e($a['payLabel']).'</span></div></div></div>'
            .'</div></div>'
            .'<div class="apptx-foot"><span>https://salaselbabel.net/ · 011 462 2277</span><span>يُرجى الحضور قبل الموعد بـ15 دقيقة وإحضار المستندات المطلوبة</span></div>'
            .'</div></body></html>';
    }
}
