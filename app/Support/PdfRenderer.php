<?php

namespace App\Support;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * مُصيِّر PDF رسمي عبر Browsershot لتحميل ملفات PDF حقيقية مباشرة إلى جهاز المستخدم.
 */
class PdfRenderer
{
    public static function render(string $html, string $filename, string $format = 'A4'): HttpResponse
    {
        $tmpDir = storage_path('app/browsershot-tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $browsershot = Browsershot::html($html)
            ->setCustomTempPath($tmpDir)
            ->setNodeModulePath(base_path('node_modules'))
            ->setIncludePath('$PATH:/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin')
            ->newHeadless()
            ->noSandbox()
            ->addChromiumArguments([
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'no-first-run',
                'disable-extensions',
                'hide-scrollbars',
            ])
            ->timeout(60)
            ->format($format)
            ->showBackground()
            ->margins(12, 12, 12, 12);

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

        try {
            $pdf = $browsershot->pdf();
        } catch (\Throwable $e) {
            Log::warning("PdfRenderer: Browsershot error ({$e->getMessage()}), using native binary PDF fallback.");
            $pdf = NativePdf::build($html, $filename);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * اكتشاف مسار Node.js في السيرفر
     */
    public static function resolveNodePath(): ?string
    {
        $explicit = env('NODE_BINARY') ?: env('NODE_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $candidates = [
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/opt/homebrew/bin/node',
            '/home/salaselbabel2026/.nvm/versions/node/*/bin/node',
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

    /**
     * اكتشاف مسار NPM في السيرفر
     */
    public static function resolveNpmPath(): ?string
    {
        $explicit = env('NPM_BINARY') ?: env('NPM_PATH');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $candidates = [
            '/usr/bin/npm',
            '/usr/local/bin/npm',
            '/opt/homebrew/bin/npm',
            '/home/salaselbabel2026/.nvm/versions/node/*/bin/npm',
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
            '/home/salaselbabel2026/.cache/puppeteer/chrome/linux-152.0.7977.42/chrome-linux64/chrome',
            $home.'/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            $home.'/.cache/puppeteer/chrome/*/*/chrome',
            '/home/*/.cache/puppeteer/chrome/*/chrome-linux64/chrome',
            '/home/*/.cache/puppeteer/chrome/*/*/chrome',
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
