<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * **المولّد الوحيد لرمز الاستجابة السريعة في المشروع** — رمزٌ حقيقيّ يُمسح، يُرمِّز النصّ الممرَّر
 * بعينه (ISO/IEC 18004 عبر `bacon/bacon-qr-code`).
 *
 * **لماذا أُعيدت كتابته؟** كان هنا نقشٌ **زخرفيّ** يشبه الرمز: مربّعات الزوايا ثمّ تعبئةٌ عشوائيّة
 * حتميّة من بذرة (`crc32`) — لا بيانات فيه، ولا ماسح يقرؤه. وكان على بطاقة الموعد وتقارير
 * الاستشارة والملخّص وعرض التنفيذ، فيحاول العميل مسحه فلا شيء؛ وهذا عرضُ شيءٍ وهميّ بصفته
 * حقيقيّاً، وهو ما يمنعه المالك. فصار كلّ رمزٍ في المشروع يخرج من هنا ويحمل بياناتٍ تُقرأ:
 * رابطَ تحقّقٍ موقَّعاً (`DocumentVerification`) أو حمولةَ الفاتورة الضريبيّة (`Finance\ZatcaQr`).
 *
 * **ولماذا مصفوفةٌ من المكتبة ورسمٌ هنا لا مُصيِّرُها الجاهز؟** لأنّ الرمز يُرسَم في ثلاثة مواضع:
 * HTML يصيّره كروم إلى PDF، والشاشة (`<img>` من مسار الخادم)، والاحتياطيّ `NativePdf` الذي لا
 * يفهم SVG فيرسم المربّعات بأوامر PDF. والمصفوفة الواحدة تغذّي الثلاثة بلا نسخةٍ ثانية من الترميز.
 *
 * ضوابط المسح (مقصودة لا اعتباطيّة):
 * - **تصحيح الخطأ M** (≈15٪): يحتمل طيّ الورقة وبقعةً صغيرة، دون تضخيم الرمز كـQ/H.
 * - **منطقةٌ هادئة ٤ وحدات** بيضاء حول الرمز — الحدّ الأدنى في المعيار؛ بدونها يخطئ الماسح الحدود.
 * - **داكنٌ خالص على أبيض**: الكحليّ الفاتح كان يُضعف التباين عند الطباعة الرماديّة.
 * - الحجم المطبوع يضبطه المنادي (`$px`)، ولا يقلّ في المستندات عن ≈٢٫٥ سم.
 */
final class Qr
{
    /** عرض المنطقة الهادئة بالوحدات — الحدّ الأدنى في المعيار. */
    public const QUIET_ZONE = 4;

    /**
     * مصفوفة الرمز بلا منطقة هادئة: `true` = وحدةٌ داكنة. صفوفٌ ثمّ أعمدة.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        // النصّ اللاتينيّ (الروابط وBase64) بالترميز الافتراضيّ بلا مقطع ECI — أوسع توافقاً
        // مع الماسحات؛ وغيره بـUTF-8 مُعلَن، وإلّا قرأه الماسح بترميزٍ خاطئ.
        $ascii = preg_match('/^[\x20-\x7E]*$/', $text) === 1;
        $code = $ascii
            ? Encoder::encode($text, ErrorCorrectionLevel::M())
            : Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8');

        $m = $code->getMatrix();
        $rows = [];
        for ($y = 0; $y < $m->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $m->getWidth(); $x++) {
                $row[] = $m->get($x, $y) === 1;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * الرمز SVG مضمَّناً (لا ملفّ ولا طلب شبكة) — يصلح لـHTML يصيّره كروم ولردّ `image/svg+xml`.
     *
     * `data-qr` يحمل النصّ المُرمَّز نفسه: منه يعيد `NativePdf` رسم الرمز حين يسقط كروم،
     * ومنه يقرأ الفاحص ما يَعِد به الرمز دون فكّ صورة.
     */
    public static function svg(string $text, int $px = 120, string $label = 'رمز الاستجابة السريعة'): string
    {
        $rows = self::matrix($text);
        $q = self::QUIET_ZONE;
        $size = count($rows) + 2 * $q;

        // مسارٌ واحد يدمج الوحدات المتجاورة أفقيّاً — أصغر بكثير من مستطيلٍ لكلّ وحدة
        $path = '';
        foreach ($rows as $y => $row) {
            $x = 0;
            $n = count($row);
            while ($x < $n) {
                if (! $row[$x]) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $n && $row[$x]) {
                    $x++;
                }
                $run = $x - $start;
                $path .= 'M'.($start + $q).' '.($y + $q).'h'.$run.'v1h-'.$run.'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$px.'" height="'.$px.'" viewBox="0 0 '.$size.' '.$size.'"'
            .' shape-rendering="crispEdges" role="img" aria-label="'.e($label).'" data-qr="'.e($text).'">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="#FFFFFF"/>'
            .'<path fill="#000000" d="'.$path.'"/>'
            .'</svg>';
    }
}
