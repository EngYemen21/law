<?php

namespace App\Support;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * مُصيِّر PDF مرن وآمن لخوادم الإنتاج وcPanel.
 * يحاول التوليد عبر Browsershot، ويسقط بسلاسة فائقة إلى صفحة طباعة وحفظ PDF
 * متوافقة مع A4 ومزوّدة بنافذة الطباعة التلقائية (window.print()) عند غياب Chrome على السيرفر.
 */
class PdfRenderer
{
    public static function render(string $html, string $filename, string $format = 'A4'): HttpResponse
    {
        $pdf = null;

        // نحاول توليد PDF حقيقي عبر Browsershot بمهلة سريعة (8 ثوانٍ كحد أقصى)
        try {
            $pdf = Browsershot::html($html)
                ->setCustomTempPath(storage_path('app/browsershot-tmp'))
                ->setNodeModulePath(base_path('node_modules'))
                ->noSandbox()
                ->timeout(8)
                ->format($format)
                ->showBackground()
                ->margins(12, 12, 12, 12)
                ->pdf();
        } catch (\Throwable $e) {
            Log::warning("PdfRenderer: Browsershot unavailable on server ({$e->getMessage()}), falling back to printable view.");
        }

        if ($pdf !== null) {
            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        // السقوط الآمن: صفحة طباعة HTML متطابقة ومُهيّأة للحفظ كـ PDF فوراً
        $cleanFilename = str_replace('.pdf', '', $filename);
        $printToolbar = <<<HTML
<div id="print-action-bar" style="position:fixed;top:0;left:0;right:0;background:#0A2A55;color:#ffffff;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;z-index:999999;box-shadow:0 4px 14px rgba(0,0,0,0.18);font-family:Tajawal,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;direction:rtl;">
    <div style="font-weight:bold;font-size:13.5px;display:flex;align-items:center;gap:8px;">
        <span style="font-size:16px;">📄</span>
        <span>{$cleanFilename} — جاهز للحفظ كـ PDF والطباعة</span>
    </div>
    <div style="display:flex;gap:10px;">
        <button onclick="window.print()" style="background:linear-gradient(135deg, #11A0C8 0%, #0E5C9C 100%);color:#ffffff;border:none;padding:7px 18px;border-radius:6px;font-weight:bold;cursor:pointer;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,0.15);">🖨️ طباعة / حفظ كـ PDF</button>
        <button onclick="window.close()" style="background:rgba(255,255,255,0.15);color:#ffffff;border:1px solid rgba(255,255,255,0.3);padding:7px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;">إغلاق</button>
    </div>
</div>
<style>
    body { padding-top: 52px !important; }
    @media print {
        #print-action-bar { display: none !important; }
        body { padding-top: 0 !important; margin: 0 !important; }
        @page { size: A4; margin: 10mm; }
    }
</style>
<script>
    window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            window.print();
        }, 400);
    });
</script>
HTML;

        if (str_contains($html, '</body>')) {
            $output = str_replace('</body>', $printToolbar.'</body>', $html);
        } else {
            $output = $html.$printToolbar;
        }

        return response($output, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
