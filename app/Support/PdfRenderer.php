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

        $tmpDir = storage_path('app/browsershot-tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        // نحاول توليد PDF حقيقي عبر Browsershot مع كامل وسائط Linux الضرورية
        try {
            $browsershot = Browsershot::html($html)
                ->setCustomTempPath($tmpDir)
                ->setNodeModulePath(base_path('node_modules'))
                ->noSandbox()
                ->addChromiumArguments([
                    'disable-setuid-sandbox',
                    'disable-dev-shm-usage',
                    'disable-gpu',
                    'no-first-run',
                    'no-zygote',
                    'single-process',
                    'disable-extensions',
                ])
                ->timeout(30)
                ->format($format)
                ->showBackground()
                ->margins(12, 12, 12, 12);

            $chromePath = static::resolveChromePath();
            if ($chromePath) {
                $browsershot->setChromePath($chromePath);
            }

            $pdf = $browsershot->pdf();
        } catch (\Throwable $e) {
            Log::warning("PdfRenderer: Browsershot error ({$e->getMessage()}), falling back to printable view.");
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

    /**
     * اكتشاف تلقائي لمسار متصفح Chromium / Chrome على خوادم الإنتاج وPuppeteer.
     */
    public static function resolveChromePath(): ?string
    {
        $explicit = env('CHROME_PATH') ?: env('PUPPETEER_EXECUTABLE_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        $candidates = [
            $home.'/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            $home.'/.cache/puppeteer/chrome/*/*/chrome',
            '/home/*/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            base_path('node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome'),
        ];

        foreach ($candidates as $pattern) {
            if (str_contains($pattern, '*')) {
                $matches = glob($pattern);
                if (!empty($matches)) {
                    foreach ($matches as $match) {
                        if (is_file($match) && (is_executable($match) || file_exists($match))) {
                            return $match;
                        }
                    }
                }
            } elseif (is_file($pattern) && (is_executable($pattern) || file_exists($pattern))) {
                return $pattern;
            }
        }

        return null;
    }
}
