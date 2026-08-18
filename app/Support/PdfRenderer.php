<?php

namespace App\Support;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;

/**
 * مُصيِّر PDF احترافي فائق السرعة والأداء عبر Puppeteer Core و Google Chrome.
 * يضمن توليد ملفات PDF حقيقية ومصممة بدقة كاملة خلال أجزاء من الثانية.
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
            @mkdir($tmpDir, 0775, true);
        }

        $uniq = uniqid();
        $htmlFile = $tmpDir . '/render-' . $uniq . '.html';
        $pdfFile = $tmpDir . '/render-' . $uniq . '.pdf';

        file_put_contents($htmlFile, $html);

        $node = static::resolveNodePath() ?: 'node';
        $chrome = static::resolveChromePath() ?: '/opt/google/chrome/chrome';
        $script = base_path('app/Support/bin/render.cjs');
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        try {
            $cmd = $isWindows
                ? '"' . $node . '" "' . $script . '" "' . $htmlFile . '" "' . $pdfFile . '" "' . $chrome . '" ' . escapeshellarg($format) . ' 2>&1'
                : 'env -i HOME=/root PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin NODE_PATH="' . base_path('node_modules') . '" "' . $node . '" "' . $script . '" "' . $htmlFile . '" "' . $pdfFile . '" "' . $chrome . '" ' . escapeshellarg($format) . ' 2>&1';
            
            $output = @shell_exec($cmd);

            if (file_exists($pdfFile) && filesize($pdfFile) > 0) {
                $content = file_get_contents($pdfFile);
                @unlink($htmlFile);
                @unlink($pdfFile);
                return $content;
            }

            throw new \RuntimeException("PDF generation failed: {$output}");
        } catch (\Throwable $e) {
            @unlink($htmlFile);
            @unlink($pdfFile);

            try {
                Log::error("PdfRenderer error: {$e->getMessage()}", [
                    'filename' => $filename,
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $ignored) {
            }

            if (class_exists(NativePdf::class)) {
                return NativePdf::build($html, $filename);
            }

            throw new \RuntimeException("PDF generation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * اكتشاف مسار Node.js التنفيذي على مختلف بيئات التشغيل (Windows, Linux, macOS).
     */
    public static function resolveNodePath(): ?string
    {
        $explicit = env('NODE_BINARY') ?: env('NODE_PATH');
        if ($explicit && (is_executable($explicit) || file_exists($explicit))) {
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

        return static::findFirstExisting($candidates);
    }

    /**
     * اكتشاف مسار NPM التنفيذي على مختلف بيئات التشغيل.
     */
    public static function resolveNpmPath(): ?string
    {
        $explicit = env('NPM_BINARY') ?: env('NPM_PATH');
        if ($explicit && (is_executable($explicit) || file_exists($explicit))) {
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

        return static::findFirstExisting($candidates);
    }

    /**
     * اكتشاف مسار متصفح Chromium / Google Chrome / Microsoft Edge على مختلف بيئات التشغيل.
     */
    public static function resolveChromePath(): ?string
    {
        $explicit = env('CHROME_PATH') ?: env('PUPPETEER_EXECUTABLE_PATH');
        if ($explicit && (is_executable($explicit) || file_exists($explicit))) {
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
            '/opt/google/chrome/chrome',
            '/opt/google/chrome/google-chrome',
            '/usr/lib/chromium/chromium',
            '/usr/lib/chromium-browser/chromium-browser',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            $home.'/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            $home.'/.cache/puppeteer/chrome/*/*/chrome',
            '/home/*/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/home/*/.cache/puppeteer/chrome/*/*/chrome',
            '/root/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/var/www/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/var/www/.cache/puppeteer/chrome/*/*/chrome',
            base_path('node_modules/puppeteer/.local-chromium/*/chrome-linux/chrome'),
        ];

        $path = static::findFirstExisting($candidates);
        if ($path) {
            $real = realpath($path);
            if ($real && is_file($real) && is_executable($real) && !str_ends_with($real, '.sh')) {
                return $real;
            }
            return $path;
        }

        // محاولة استخدام أمر which على سيرفرات Linux
        if (!$isWindows && function_exists('exec')) {
            foreach (['google-chrome-stable', 'google-chrome', 'chromium-browser', 'chromium'] as $bin) {
                $output = [];
                $returnCode = 0;
                @exec("which {$bin} 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0 && !empty($output[0]) && (is_executable($output[0]) || file_exists($output[0]))) {
                    return $output[0];
                }
            }
        }

        return null;
    }

    /**
     * دالة مساعدة لإيجاد أول مسار موجود من قائمة المرشحين.
     */
    private static function findFirstExisting(array $candidates): ?string
    {
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
            } elseif (!empty($pattern) && is_file($pattern) && (is_executable($pattern) || file_exists($pattern))) {
                return $pattern;
            }
        }

        return null;
    }
}
