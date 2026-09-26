<?php

namespace App\Support;

/**
 * مُنشئ ملفات PDF ثنائية نقية بـ PHP بدون أي متطلبات خارجية.
 * يعمل كشبكة أمان مطلقة تضمن تنزيل ملف .pdf حقيقي وصحيح 100% في كافة الحالات.
 */
class NativePdf
{
    /**
     * تحويل محتوى HTML إلى ملف PDF ثنائي صالح بنسبة 100%
     */
    public static function build(string $html, string $title = 'مستند رسمي'): string
    {
        // تنظيف أكواد CSS و JavaScript قبل استخراج النصوص لتجنب طباعة الأكواد
        $cleanedHtml = preg_replace('/<(style|script)\b[^>]*>(.*?)<\/\1>/is', '', $html);
        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</tr>', '</div>', '</p>', '</h1>', '</h2>', '</h3>'], "\n", (string) $cleanedHtml));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $plain)), fn ($l) => ! empty($l)));

        // تنظيف النصوص للعرض القياسي في كائن الـ PDF
        // اسم المكتب من الإعدادات — الاحتياطيّ يحمل الهويّة نفسها التي يحملها المستند الأصليّ
        $officeName = SettingsRegistry::str('office_name');
        $docTitle = ! empty($title) ? $title : 'مستند رسمي — '.$officeName;

        $contentStream = "BT\n";
        $contentStream .= "/F1 16 Tf\n";
        $contentStream .= "50 790 Td\n";
        $contentStream .= '('.static::escapePdfText($docTitle).") Tj\n";
        $contentStream .= "/F1 10 Tf\n";
        $contentStream .= "0 -22 Td\n";
        $contentStream .= '('.static::escapePdfText($officeName.' — تاريخ الإصدار: '.date('Y-m-d H:i')).") Tj\n";
        $contentStream .= "0 -15 Td\n";
        $contentStream .= '('.static::escapePdfText('----------------------------------------------------------------------------------------------------').") Tj\n";

        $yOffset = -22;
        $maxLines = 45;
        $count = 0;

        foreach ($lines as $line) {
            if ($count >= $maxLines) {
                break;
            }

            // تحديد الحجم بناء على أهمية السطر
            if (mb_strlen($line) < 40 && (str_contains($line, 'تقرير') || str_contains($line, 'بطاقة') || str_contains($line, 'فاتورة') || str_contains($line, 'الموضوع'))) {
                $contentStream .= "/F1 12 Tf\n";
            } else {
                $contentStream .= "/F1 10 Tf\n";
            }

            $contentStream .= "0 {$yOffset} Td\n";
            $contentStream .= '('.static::escapePdfText($line).") Tj\n";
            $yOffset = -18;
            $count++;
        }

        $contentStream .= "0 -30 Td\n";
        $contentStream .= "/F1 9 Tf\n";
        $contentStream .= '('.static::escapePdfText('وثيقة إلكترونية صادرة وموثقة آلياً من المنصة — جميع الحقوق محفوظة © '.date('Y')).") Tj\n";
        $contentStream .= "ET\n";
        $contentStream .= static::qrOperators($html);

        // بناء كائنات هيكل ملف الـ PDF (%PDF-1.4)
        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Length ".strlen($contentStream)." >>\nstream\n".$contentStream."endstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $currentOffset = strlen($pdf);

        foreach ($objects as $obj) {
            $offsets[] = $currentOffset;
            $pdf .= $obj;
            $currentOffset = strlen($pdf);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF\n";

        return $pdf;
    }

    /**
     * **رمز الاستجابة يبقى في الاحتياطيّ.** هذا المُنشئ نصّيّ لا يفهم SVG، فكان يُسقط الرمز مع كلّ
     * صورة — والرمز هنا ليس زينة: رمز الهيئة على الفاتورة الضريبيّة، أو رابط التحقّق على الوثيقة.
     * فيُقرأ النصّ المُرمَّز من `data-qr` (يكتبه `Qr::svg`) وتُعاد مصفوفته من المولّد نفسه، وتُرسم
     * وحداته مستطيلاتٍ PDF أعلى يمين الصفحة — بعد النصّ وبخلفيّةٍ بيضاء، فلا يُغطّيه سطرٌ طويل.
     * الأوّل وحده: الصفحة واحدة، وكلّ وثيقةٍ اليوم تحمل رمزاً واحداً.
     */
    private static function qrOperators(string $html): string
    {
        if (preg_match('/data-qr="([^"]*)"/', $html, $m) !== 1 || $m[1] === '') {
            return '';
        }

        $rows = Qr::matrix(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $side = 100.0; // ≈ ٣٫٥ سم شاملةً المنطقة الهادئة
        $unit = $side / (count($rows) + 2 * Qr::QUIET_ZONE);
        $x0 = 595.28 - 40 - $side;
        $top = 841.89 - 40;
        $f = fn (float $v): string => sprintf('%.3F', $v);

        $ops = "% qr\nq\n1 g\n".$f($x0).' '.$f($top - $side).' '.$f($side).' '.$f($side)." re f\n0 g\n";
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
                $ops .= $f($x0 + ($start + Qr::QUIET_ZONE) * $unit).' '.$f($top - ($y + Qr::QUIET_ZONE + 1) * $unit)
                    .' '.$f(($x - $start) * $unit).' '.$f($unit)." re\n";
            }
        }

        return $ops."f\nQ\n";
    }

    private static function escapePdfText(string $text): string
    {
        $sanitized = preg_replace('/[^\x20-\x7E]/', ' ', $text);
        if (empty(trim($sanitized))) {
            $sanitized = 'Document Item: '.substr(md5($text), 0, 8);
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $sanitized);
    }
}
