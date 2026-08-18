<?php

namespace App\Support;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * مُصيِّر PDF احترافي فائق السرعة والأداء عبر Puppeteer Core و Google Chrome.
 * يضمن توليد ملفات PDF حقيقية ومصممة بدقة كاملة خلال أجزاء من الثانية.
 *
 * ضمانة صلبة: لا يخرج من هنا أبداً شيء غير PDF. الثغرة السابقة: shell_exec بلا مهلة —
 * حين يعلق كروم أطول من max_execution_time (عادة 30s على cPanel) يسقط PHP بخطأ فادح
 * غير قابل للالتقاط قبل بلوغ الاحتياطي، فيتنزّل خطأ HTML باسم card.html.
 *
 * ضبط .env على السيرفر: PDF_TIMEOUT (افتراض 20 ثانية — أقل من 30 عمداً)،
 * وPDF_ENGINE=native لتجاوز كروم كليّاً إن استمر التعليق (راجع config/pdf.php).
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
     * توليد محتوى الـ PDF الثنائي (Binary Stream) — يعود دائماً بـPDF صالح مهما حدث.
     */
    public static function generatePdfBinary(string $html, string $filename = 'document.pdf', string $format = 'A4'): string
    {
        // فكّ قيد مهلة PHP حيث تسمح الاستضافة — يمنع الخطأ الفادح الذي كان يقطع الطريق على الاحتياطي
        @set_time_limit(240);

        $engine = (string) config('pdf.engine', 'auto');
        $node = static::resolveNodePath();

        // native: تجاوز كروم كليّاً. auto بلا Node: render.cjs مستحيل — لا نحاول أصلاً (المحاولة هي ما كان يعلق)
        if ($engine === 'native' || ($engine !== 'chrome' && $node === null && static::resolveChromePath() === null)) {
            if ($engine !== 'native') {
                Log::warning('PdfRenderer: no Node/Chrome found, skipping Chrome renderer.');
            }

            return static::fallbackPdf($html, $filename);
        }

        $tmpDir = storage_path('app/browsershot-tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $uniq = uniqid();
        $htmlFile = $tmpDir.'/render-'.$uniq.'.html';
        $pdfFile = $tmpDir.'/render-'.$uniq.'.pdf';
        // بروفايل كروم معزول لكل عملية داخل storage — قابل للكتابة لمستخدم الويب
        $profileDir = $tmpDir.'/profile-'.$uniq;

        file_put_contents($htmlFile, $html);

        $node = $node ?: 'node';
        $chrome = static::resolveChromePath() ?: '/opt/google/chrome/chrome';
        $script = base_path('app/Support/bin/render.cjs');
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        // مهلة أقل من max_execution_time الشائع (30s) عمداً — قتل العملية المعلّقة بدل سقوط PHP
        $timeout = max(5, (int) config('pdf.timeout', 20));

        try {
            // HOME كان مثبّتاً على /root فينجح الأمر من طرفية root ويفشل من طلب الويب
            // (www-data لا يكتب في /root فيتحطم كروم) — يُوجَّه كل شيء إلى storage القابل للكتابة
            $envPrefix = 'env -i'
                .' HOME="'.$tmpDir.'"'
                .' XDG_CONFIG_HOME="'.$tmpDir.'/.config"'
                .' XDG_CACHE_HOME="'.$tmpDir.'/.cache"'
                .' TMPDIR="'.$tmpDir.'"'
                .' CHROME_USER_DATA_DIR="'.$profileDir.'"'
                .' PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
                .' NODE_PATH="'.base_path('node_modules').'"';

            $cmd = $isWindows
                ? '"'.$node.'" "'.$script.'" "'.$htmlFile.'" "'.$pdfFile.'" "'.$chrome.'" '.escapeshellarg($format).' '.$timeout.' 2>&1'
                : $envPrefix.' "'.$node.'" "'.$script.'" "'.$htmlFile.'" "'.$pdfFile.'" "'.$chrome.'" '.escapeshellarg($format).' '.$timeout.' 2>&1';

            // Symfony Process بمهلة صلبة تقتل العملية (بدل shell_exec الذي يعلّق بلا حدود حتى يسقط PHP)
            $process = Process::fromShellCommandline($cmd, base_path(), null, null, (float) ($timeout + 5));
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            if (file_exists($pdfFile) && filesize($pdfFile) > 0) {
                $content = file_get_contents($pdfFile);
                @unlink($htmlFile);
                @unlink($pdfFile);
                static::cleanupDir($profileDir);

                return $content;
            }

            throw new \RuntimeException("PDF generation failed: {$output}");
        } catch (\Throwable $e) {
            @unlink($htmlFile);
            @unlink($pdfFile);
            static::cleanupDir($profileDir);

            try {
                Log::error("PdfRenderer error: {$e->getMessage()}", [
                    'filename' => $filename,
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $ignored) {
            }

            return static::fallbackPdf($html, $filename);
        }
    }

    /** حذف مجلد البروفايل المؤقت بعد كل تصيير (لا تراكم بروفايلات كروم في storage) */
    private static function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        } catch (\Throwable $ignored) {
        }
    }

    /** الاحتياطي المضمون: NativePdf، وإن فشل هو أيضاً فأصغر PDF صالح — لا HTML أبداً */
    private static function fallbackPdf(string $html, string $filename): string
    {
        try {
            return NativePdf::build($html, $filename);
        } catch (\Throwable $e) {
            try {
                Log::error("PdfRenderer: NativePdf fallback failed ({$e->getMessage()}), serving minimal PDF.");
            } catch (\Throwable $ignored) {
            }

            return static::minimalPdf($filename);
        }
    }

    /** أصغر PDF صالح (صفحة واحدة برسالة إنجليزية) — ملاذ أخير لا يفشل */
    private static function minimalPdf(string $title): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', ' ', $title) ?: 'document';
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
        $stream = "BT\n/F1 14 Tf\n50 780 Td\n({$safe}) Tj\n0 -24 Td\n/F1 11 Tf\n(Document is temporarily unavailable - please try again later.) Tj\nET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
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
            if ($real && is_file($real) && is_executable($real) && ! str_ends_with($real, '.sh')) {
                return $real;
            }

            return $path;
        }

        // محاولة استخدام أمر which على سيرفرات Linux
        if (! $isWindows && function_exists('exec')) {
            foreach (['google-chrome-stable', 'google-chrome', 'chromium-browser', 'chromium'] as $bin) {
                $output = [];
                $returnCode = 0;
                @exec("which {$bin} 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0 && ! empty($output[0]) && (is_executable($output[0]) || file_exists($output[0]))) {
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
                if (! empty($matches)) {
                    foreach ($matches as $match) {
                        if (is_file($match) && (is_executable($match) || file_exists($match))) {
                            return $match;
                        }
                    }
                }
            } elseif (! empty($pattern) && is_file($pattern) && (is_executable($pattern) || file_exists($pattern))) {
                return $pattern;
            }
        }

        return null;
    }
}
