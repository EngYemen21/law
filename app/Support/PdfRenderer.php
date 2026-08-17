<?php

namespace App\Support;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * مُصيِّر PDF رسمي واحترافي عبر Spatie Browsershot v4.
 * يضمن توليد ملفات PDF حقيقية ومصممة بدقة كاملة عبر Chromium/Chrome على كافة بيئات التشغيل (Windows, Linux, macOS).
 */
class PdfRenderer
{
    /**
     * تصيير محتوى HTML إلى استجابة PDF مباشرة للتنزيل.
     */
    public static function render(string $html, string $filename, string $format = 'A4'): HttpResponse
    {
        $pdf = static::generatePdfBinary($html, $filename, $format);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * توليد محتوى الـ PDF الثنائي (Binary Stream).
     */
    public static function generatePdfBinary(string $html, string $filename = 'document.pdf', string $format = 'A4'): string
    {
        $tmpDir = storage_path('app/browsershot-tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        try {
            $browsershot = Browsershot::html($html)
                ->writeOptionsToFile()
                ->setCustomTempPath($tmpDir)
                ->newHeadless()
                ->noSandbox()
                ->emulateMedia('screen')
                ->showBackground()
                ->timeout(90)
                ->format($format)
                ->margins(10, 10, 10, 10)
                ->addChromiumArguments([
                    'disable-gpu',
                    'disable-setuid-sandbox',
                    'disable-dev-shm-usage',
                    'no-first-run',
                    'no-default-browser-check',
                    'disable-extensions',
                    'hide-scrollbars',
                ]);

            $nodeModules = base_path('node_modules');
            if (is_dir($nodeModules)) {
                $browsershot->setNodeModulePath($nodeModules);
            }

            // تعيين مسارات Node و NPM و Chrome/Edge المكتشفة تلقائياً أو من متغيرات البيئة
            $nodeBinary = static::resolveNodePath();
            if ($nodeBinary) {
                $browsershot->setNodeBinary($nodeBinary);
            }

            $npmBinary = static::resolveNpmPath();
            if ($npmBinary) {
                $browsershot->setNpmBinary($npmBinary);
            }

            $chromePath = static::resolveChromePath();
            if ($chromePath) {
                $browsershot->setChromePath($chromePath);
            }

            return $browsershot->pdf();
        } catch (\Throwable $e) {
            try {
                if (function_exists('logger')) {
                    logger()->error("PdfRenderer error: {$e->getMessage()}", [
                        'filename' => $filename,
                    ]);
                }
            } catch (\Throwable $ignored) {
            }

            // شبكة الأمان المتقدمة في حال فشل المتصفح لأي سبب غير متوقع
            return NativePdf::build($html, $filename);
        }
    }

    /**
     * اكتشاف مسار Node.js التنفيذي على مختلف بيئات التشغيل (Windows, Linux, macOS).
     */
    public static function resolveNodePath(): ?string
    {
        $explicit = env('NODE_BINARY') ?: env('NODE_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $candidates = $isWindows ? [
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            (getenv('LOCALAPPDATA') ?: '').'\\Programs\\nodejs\\node.exe',
            (getenv('APPDATA') ?: '').'\\npm\\node.exe',
        ] : [
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/opt/homebrew/bin/node',
            '/home/*/.nvm/versions/node/*/bin/node',
            '/root/.nvm/versions/node/*/bin/node',
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
            } elseif (!empty($pattern) && is_file($pattern) && file_exists($pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * اكتشاف مسار NPM التنفيذي على مختلف بيئات التشغيل.
     */
    public static function resolveNpmPath(): ?string
    {
        $explicit = env('NPM_BINARY') ?: env('NPM_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $candidates = $isWindows ? [
            'C:\\Program Files\\nodejs\\npm.cmd',
            'C:\\Program Files (x86)\\nodejs\\npm.cmd',
            (getenv('LOCALAPPDATA') ?: '').'\\Programs\\nodejs\\npm.cmd',
            (getenv('APPDATA') ?: '').'\\npm\\npm.cmd',
        ] : [
            '/usr/bin/npm',
            '/usr/local/bin/npm',
            '/opt/homebrew/bin/npm',
            '/home/*/.nvm/versions/node/*/bin/npm',
            '/root/.nvm/versions/node/*/bin/npm',
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
            } elseif (!empty($pattern) && is_file($pattern) && file_exists($pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * اكتشاف مسار متصفح Chromium / Google Chrome / Microsoft Edge على مختلف بيئات التشغيل.
     */
    public static function resolveChromePath(): ?string
    {
        $explicit = env('CHROME_PATH') ?: env('PUPPETEER_EXECUTABLE_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $userProfile = getenv('USERPROFILE') ?: '';
        $localAppData = getenv('LOCALAPPDATA') ?: '';
        $home = getenv('HOME') ?: '';

        $candidates = $isWindows ? [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            $localAppData.'\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            $userProfile.'\\.cache\\puppeteer\\chrome\\*\\chrome-win64\\chrome.exe',
            $userProfile.'\\.cache\\puppeteer\\chrome\\*\\*\\chrome.exe',
            base_path('node_modules\\puppeteer\\.local-chromium\\*\\chrome-win32\\chrome.exe'),
            base_path('node_modules\\puppeteer\\.local-chromium\\*\\chrome-win64\\chrome.exe'),
        ] : [
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            '/opt/google/chrome/chrome',
            '/opt/google/chrome/google-chrome',
            '/usr/lib/chromium/chromium',
            '/usr/lib/chromium-browser/chromium-browser',
            $home.'/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            $home.'/.cache/puppeteer/chrome/*/*/chrome',
            '/home/*/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/home/*/.cache/puppeteer/chrome/*/*/chrome',
            '/root/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/var/www/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/var/www/.cache/puppeteer/chrome/*/*/chrome',
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
            } elseif (!empty($pattern) && is_file($pattern) && file_exists($pattern)) {
                return $pattern;
            }
        }

        // محاولة استخدام أمر which على سيرفرات Linux
        if (!$isWindows && function_exists('exec')) {
            foreach (['google-chrome-stable', 'google-chrome', 'chromium-browser', 'chromium'] as $bin) {
                $output = [];
                $returnCode = 0;
                @exec("which {$bin} 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0 && !empty($output[0]) && is_file($output[0])) {
                    return $output[0];
                }
            }
        }

        return null;
    }
}
