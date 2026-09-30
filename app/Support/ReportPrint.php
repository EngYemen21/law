<?php

namespace App\Support;

/**
 * مولّد تقارير رسمية قابل لإعادة الاستخدام — النظير الخادميّ لـ resources/js/lib/reportPrint.ts.
 * يبني نفس تصميم بطاقة .cf (رأس بالشعار، عنوان متدرّج، أقسام مرقّمة كاملة العرض أو عمودين،
 * قسم اعتماد برمز استجابة حقيقيّ، ملاحظة — بلا شريط تذييل) كسلسلة HTML واحدة، تُغذّى إلى Browsershot لإصدار PDF حقيقي
 * مطابق تماماً لما يعرضه المتصفح — لا صورة/محاكاة.
 *
 * @phpstan-type ReportCell array{0: string, 1: string}
 * @phpstan-type ReportSection array{title: string, cellRows?: array<int, array<int, ReportCell>>, lines?: string, html?: string, list?: array<int, string>, chips?: array<int, string>, table?: ReportTable}
 * @phpstan-type ReportTable array{head: list<string>, rows: list<list<string>>, foot?: list<string>, ltr?: list<int>}
 */
class ReportPrint
{
    private const STYLE = <<<'CSS'
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap');
        *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        body{margin:0;padding:0;background:#fff;font-family:'Tajawal',Tahoma,Arial,sans-serif;-webkit-font-smoothing:antialiased}
        .cf{font-family:'Tajawal',Tahoma,Arial,sans-serif;color:#1a2540;background:#fff;max-width:820px;margin:0 auto}
        .cf-hd{display:flex;align-items:center;gap:12px;padding:16px 22px 10px;border-bottom:2px solid #0E5C9C}
        .cf-hd img{height:56px}
        .cf-hd .cf-name{font-size:15px;color:#0A2A55;font-weight:800}
        .cf-hd .meta{margin-inline-start:auto;text-align:left;font-size:10px;color:#7a8aa3;line-height:1.8}
        .cf-title{background:linear-gradient(135deg,#0E5C9C,#11A0C8);color:#fff;margin:12px 22px;border-radius:9px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .cf-title b{font-size:15px;font-weight:800}
        .cf-title .s{font-size:10.5px;opacity:.92;margin-top:2px;display:block}
        .cf-title .rf{background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35);border-radius:16px;padding:5px 12px;font-size:11.5px;font-weight:800}
        .cf-sec{margin:0 22px 12px;border:1.5px solid #D3E2F0;border-radius:10px;overflow:hidden}
        .cf-sec .sh{background:#EDF4FA;color:#0A2A55;font-weight:800;font-size:12px;padding:8px 13px;border-bottom:1.5px solid #D3E2F0}
        .cf-sec .sb{padding:11px 13px}
        .cf-2col{display:flex;gap:12px;margin:0 22px 12px}
        .cf-2col>.cf-sec{flex:1;margin:0}
        .cf-cells{display:flex;border-bottom:1px solid #E7EFF6}
        .cf-cells:last-child{border-bottom:none}
        .cf-cell{flex:1;min-width:110px;padding:7px 12px;border-left:1px solid #E7EFF6}
        .cf-cells .cf-cell:last-child{border-left:none}
        .cf-cell .l{font-size:9.5px;color:#7a8aa3;font-weight:700;margin-bottom:3px}
        .cf-cell .v{font-size:12px;color:#0A2A55;font-weight:700}
        .cf-lines{font-size:12px;color:#33415c;line-height:2.1;white-space:pre-wrap}
        .cf-rich{font-size:12px;color:#33415c;line-height:2}.cf-rich p{margin:0 0 6px}.cf-rich ul,.cf-rich ol{margin:2px 0 8px;padding-inline-start:20px}.cf-rich hr{border:0;border-top:1px solid #e2e8f0;margin:8px 0}
        .cf-ul{margin:0;padding:0;list-style:none}
        .cf-ul li{font-size:11.5px;color:#33415c;padding:6px 0;border-bottom:1px dashed #E7EFF6;display:flex;gap:8px;line-height:1.7}
        .cf-ul li:last-child{border-bottom:none}
        .cf-ul li:before{content:'';width:6px;height:6px;border-radius:50%;background:#11A0C8;margin-top:7px;flex-shrink:0}
        .cf-chips{display:flex;flex-wrap:wrap;gap:7px}
        .cf-chip{font-size:11px;border:1px solid #C9DEF0;background:#F2F8FC;border-radius:6px;padding:5px 11px;color:#0E5C9C;font-weight:700}
        .cf-appr{display:flex;justify-content:space-between;gap:10px;align-items:center}
        .cf-appr .qr{flex-shrink:0;text-align:center;width:132px}
        .cf-appr .qr svg{width:120px;height:120px;display:block;margin:0 auto}
        .cf-appr .qr small{display:block;font-size:9px;color:#7a8aa3;line-height:1.5;margin-top:3px}
        .cf-appr .rows{flex:1}
        .cf-appr .r{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dashed #E7EFF6;font-size:11px}
        .cf-appr .r:last-child{border-bottom:none}
        .cf-appr .r span{color:#7a8aa3}
        .cf-appr .r b{color:#0A2A55}
        .cf-tbl{width:100%;border-collapse:collapse;font-size:10.5px;color:#33415c}
        .cf-tbl th{background:#F5F9FC;color:#0A2A55;font-weight:800;padding:6px 8px;border-bottom:1.5px solid #D3E2F0;text-align:right}
        .cf-tbl td{padding:5px 8px;border-bottom:1px solid #E7EFF6;vertical-align:top}
        .cf-tbl tr{page-break-inside:avoid}
        .cf-tbl .ltr{text-align:left;white-space:nowrap;direction:ltr;unicode-bidi:plaintext}
        .cf-tbl tfoot td{font-weight:800;color:#0A2A55;background:#F5F9FC;border-top:1.5px solid #D3E2F0;border-bottom:none}
        .cf-note{margin:0 22px 12px;background:#FFF8EC;border:1px solid #F0DDB0;border-radius:8px;padding:9px 13px;font-size:10.5px;color:#8A6D2F;line-height:1.85}
        @page{size:A4;margin:12mm}
        CSS;

    /**
     * @param  array{title:string,subtitle:string,ref:string,blocks:array<int, ReportSection|array{0:ReportSection,1:ReportSection}>,approval?:array{qr?:string|null,qrCaption?:string,rows:array<int,ReportCell>,title?:string},note?:string}  $doc
     */
    public static function html(array $doc): string
    {
        $blocksHtml = implode('', array_map(function ($b) {
            if (isset($b[0]) && isset($b[1]) && ! isset($b['title'])) {
                return '<div class="cf-2col">'.self::renderSection($b[0]).self::renderSection($b[1]).'</div>';
            }

            return self::renderSection($b);
        }, $doc['blocks']));

        $approvalHtml = isset($doc['approval']) ? self::renderApproval($doc['approval']) : '';
        $noteHtml = isset($doc['note']) ? '<div class="cf-note">'.e($doc['note']).'</div>' : '';
        $logo = self::logoDataUri();

        // بيانات المكتب من الإعدادات لا منقوشةً هنا: تغييرُ رقم هاتفٍ كان يحتاج نشرَ كود.
        $officeName = SettingsRegistry::str('office_name');
        $officeUrl = SettingsRegistry::str('office_url');
        $officePhone = SettingsRegistry::str('office_phone');

        return '<html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>'.e($doc['ref']).'</title><style>'.self::STYLE.'</style></head><body>'
            .'<div class="cf">'
            // الشعار وحده في الترويسة (طلب المالك 2026-09-26) — كان بجانبه اسم المكتب وسطرٌ إنجليزيّ
            // «LEGAL OFFICE MANAGEMENT SYSTEM»؛ واسم المكتب في نصّ الشعار البديل. ولا شريط تذييل
            // باسم المكتب أسفل الوثيقة (طلب المالك 2026-09-28): الشعار هويّتها الوحيدة
            .'<div class="cf-hd">'.($logo ? '<img src="'.$logo.'" alt="'.e($officeName).'">' : '<b class="cf-name">'.e($officeName).'</b>').'<div class="meta">'.e($officeUrl).'<br>'.e($officePhone).'</div></div>'
            .'<div class="cf-title"><div><b>'.e($doc['title']).'</b><span class="s">'.e($doc['subtitle']).'</span></div><div class="rf">'.e($doc['ref']).'</div></div>'
            .$blocksHtml.$approvalHtml.$noteHtml
            .'</div></body></html>';
    }

    /**
     * @param  array<int, ReportCell>  $row
     */
    private static function renderCells(array $row): string
    {
        return '<div class="cf-cells">'.implode('', array_map(
            fn ($cell) => '<div class="cf-cell"><div class="l">'.e($cell[0]).'</div><div class="v">'.e($cell[1]).'</div></div>',
            $row
        )).'</div>';
    }

    /**
     * @param  ReportSection  $s
     */
    private static function renderSection(array $s): string
    {
        $cells = implode('', array_map(fn ($row) => self::renderCells($row), $s['cellRows'] ?? []));
        $lines = isset($s['lines']) ? '<div class="cf-lines">'.e($s['lines']).'</div>' : '';
        // HTML منسّقٌ من محرّر (ملخّص التذكرة والاستشارة — `HasRichText::html`) — يُنقّى هنا أيضاً فلا يطبع كرومُ
        // الخادم إلّا المسموح
        if (isset($s['html'])) {
            $lines .= '<div class="cf-rich">'.RichHtml::clean($s['html']).'</div>';
        }
        $list = ! empty($s['list'])
            ? '<ul class="cf-ul">'.implode('', array_map(fn ($li) => '<li><div>'.e($li).'</div></li>', $s['list'])).'</ul>'
            : '';
        $chips = ! empty($s['chips'])
            ? '<div class="cf-chips">'.implode('', array_map(fn ($c) => '<span class="cf-chip">'.e($c).'</span>', $s['chips'])).'</div>'
            : '';
        $table = isset($s['table']) ? self::renderTable($s['table']) : '';
        $flush = ($cells !== '' || $table !== '') && $lines === '' && $list === '' && $chips === '';

        return '<div class="cf-sec"><div class="sh">'.e($s['title']).'</div><div class="sb"'.($flush ? ' style="padding:0"' : '').'>'.$cells.$table.$lines.$list.$chips.'</div></div>';
    }

    /**
     * جدولُ صفوفٍ (كشف الحساب والتقارير الماليّة) — كلّ خليّةٍ مهرَّبة، وأعمدة `ltr` (أرقامٌ ومراجع
     * لاتينيّة) تُكتب من اليسار بلا انكسار فتصطفّ منازلها ولا ينقسم رقم مرجع.
     *
     * @param  ReportTable  $t
     */
    private static function renderTable(array $t): string
    {
        $ltr = array_flip($t['ltr'] ?? []);
        $row = fn (array $cells, string $tag) => '<tr>'.implode('', array_map(
            fn ($cell, $i) => "<{$tag}".(isset($ltr[$i]) ? ' class="ltr"' : '').'>'.e($cell)."</{$tag}>",
            $cells,
            array_keys($cells),
        )).'</tr>';

        return '<table class="cf-tbl"><thead>'.$row($t['head'], 'th').'</thead><tbody>'
            .implode('', array_map(fn ($r) => $row($r, 'td'), $t['rows'])).'</tbody>'
            .(isset($t['foot']) ? '<tfoot>'.$row($t['foot'], 'td').'</tfoot>' : '').'</table>';
    }

    /**
     * كتلة ذيل الوثيقة — **عنوانها يتبع مضمونها**.
     *
     * كان العنوان «اعتماد وتوقيع الإدارة العليا» مثبَّتاً في كل وثيقةٍ لها كتلةُ ذيل،
     * وأكثرها لا اعتماد فيه: تقرير الاستشارة صفوفه (الجهة · الحالة · تاريخ الطباعة)،
     * وعرض التنفيذ مثله، وتقرير الملخّص يُطبع قبل الاعتماد أصلاً. فيقرأ المتلقّي
     * توقيعَ إدارةٍ عليا تحت بياناتِ إصدارٍ لا توقيع فيها.
     *
     * والتقصير هو الصادق: من يملك اعتماداً فعلياً يُعلنه بـ`title`.
     *
     * **والرمز يحمل ما يُمرَّر في `qr` بعينه** (`Qr` — مولّدٌ حقيقيّ يُمسح): رابط التحقّق الموقَّع
     * (`DocumentVerification::url`) أو حمولة الفاتورة الضريبيّة (`Finance\ZatcaQr`). كان هنا
     * بذرةٌ لنقشٍ زخرفيّ لا يُقرأ. ولا `qr` ⇒ لا رمز: لا يُرسم ما لا يحيل إلى شيء.
     *
     * @param  array{qr?:string|null,qrCaption?:string,rows:array<int,ReportCell>,title?:string}  $a
     */
    private static function renderApproval(array $a): string
    {
        $rows = implode('', array_map(
            fn ($row) => '<div class="r"><span>'.e($row[0]).'</span><b>'.e($row[1]).'</b></div>',
            $a['rows']
        ));

        $title = trim((string) ($a['title'] ?? '')) ?: 'بيانات إصدار الوثيقة';

        $qr = trim((string) ($a['qr'] ?? '')) !== ''
            ? '<div class="qr">'.Qr::svg((string) $a['qr'], 120, $title).(isset($a['qrCaption']) ? '<small>'.e($a['qrCaption']).'</small>' : '').'</div>'
            : '';

        return '<div class="cf-sec"><div class="sh">'.e($title).'</div><div class="sb"><div class="cf-appr">'.$qr.'<div class="rows">'.$rows.'</div></div></div></div>';
    }

    /** يضمّن شعار المكتب كـdata URI حتى يظهر داخل PDF المُصيَّر بمعزل عن الخادم المحلي (بلا طلب شبكة). */
    public static function logoDataUri(): ?string
    {
        $path = public_path('images/021.png');
        if (! is_file($path)) {
            $path = public_path('images/logo.jpg');
        }
        if (! is_file($path)) {
            return null;
        }

        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
