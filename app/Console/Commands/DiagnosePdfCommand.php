<?php

namespace App\Console\Commands;

use App\Support\AppointmentCardPdf;
use App\Support\PdfRenderer;
use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;

class DiagnosePdfCommand extends Command
{
    protected $signature = 'pdf:diagnose';
    protected $description = 'تشخيص واختبار بيئة تشغيل Spatie Browsershot ومحرك Chrome/Node.js على السيرفر';

    public function handle(): int
    {
        $this->info('====================================================');
        $this->info('🔍 فحص بيئة تشغيل PDF و Browsershot على السيرفر');
        $this->info('====================================================');

        // 1. فحص الدوال التنفيذية في PHP
        $this->newLine();
        $this->info('1️⃣ فحص دوال النظام في PHP (proc_open / exec):');
        if (!function_exists('proc_open') || !function_exists('exec')) {
            $this->error('❌ دوال proc_open أو exec معطلة في إعدادات php.ini (disable_functions).');
            $this->warn('👉 الحل: قم بحذف proc_open و exec من سطر disable_functions في ملف php.ini الخاص بالسيرفر.');
            return 1;
        }
        $this->info('   ✅ دوال proc_open و exec مفعلة وتعمل.');

        // 2. فحص Node.js
        $this->newLine();
        $this->info('2️⃣ فحص مسار وإصدار Node.js:');
        $nodePath = PdfRenderer::resolveNodePath();
        if (!$nodePath) {
            $this->error('❌ لم يتم العثور على مسار Node.js التنفيذي تلقائياً.');
            $this->warn('👉 الحل: تأكد من تثبيت Node.js أو حدد مساره في ملف .env:');
            $this->line('   NODE_BINARY=/usr/bin/node (أو مسار nvm لديك)');
        } else {
            $this->info("   📍 المسار: {$nodePath}");
            $nodeVer = @shell_exec('"' . $nodePath . '" -v');
            $this->info('   ✅ الإصدار: ' . trim((string)$nodeVer));
        }

        // 3. فحص NPM
        $this->newLine();
        $this->info('3️⃣ فحص مسار وإصدار NPM:');
        $npmPath = PdfRenderer::resolveNpmPath();
        if (!$npmPath) {
            $this->warn('   ⚠️ لم يتم العثور على مسار npm (اختياري إذا كان puppeteer مثبتاً محلياً).');
        } else {
            $this->info("   📍 المسار: {$npmPath}");
            $npmVer = @shell_exec('"' . $npmPath . '" -v');
            $this->info('   ✅ الإصدار: ' . trim((string)$npmVer));
        }

        // 4. فحص Chrome / Chromium
        $this->newLine();
        $this->info('4️⃣ فحص مسار متصفح Chromium / Chrome:');
        $chromePath = PdfRenderer::resolveChromePath();
        if (!$chromePath) {
            $this->error('❌ لم يتم العثور على متصفح Chrome أو Chromium على السيرفر.');
            $this->warn('👉 خطوات التثبيت السريعة على سيرفر Linux (اختر أحد الخيارين):');
            $this->line('   الخيار أ (موصى به لـ Puppeteer):');
            $this->line('   npx puppeteer browsers install chrome');
            $this->line('');
            $this->line('   الخيار ب (تثبيت حزمة النظام Chromium):');
            $this->line('   sudo apt update && sudo apt install -y chromium-browser');
            $this->line('   ثم إضافة في ملف .env:');
            $this->line('   CHROME_PATH=/usr/bin/chromium-browser');
        } else {
            $this->info("   📍 المسار المكتشف: {$chromePath}");
            $this->info('   ✅ متصفح Chrome/Chromium موجود وجاهز.');
        }

        // 5. فحص المجلد المؤقت والصلاحيات
        $this->newLine();
        $this->info('5️⃣ فحص المجلد المؤقت storage/app/browsershot-tmp:');
        $tmpDir = storage_path('app/browsershot-tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        if (!is_writable($tmpDir)) {
            $this->error("❌ المجلد {$tmpDir} غير قابل للكتابة من قبل مستخدم الويب.");
            $this->warn("👉 الحل: sudo chmod -R 775 {$tmpDir}");
        } else {
            $this->info("   ✅ المجلد المؤقت موجود وقابل للكتابة: {$tmpDir}");
        }

        // 6. تجربة توليد PDF فعلي عبر Browsershot
        $this->newLine();
        $this->info('6️⃣ اختبار تصيير وتوليد PDF حقيقي عبر Browsershot...');

        $sampleHtml = AppointmentCardPdf::html([
            'no' => 'TEST-2026-0001',
            'type' => 'موعد تجريبي',
            'day' => 'الاثنين 24 أغسطس 2026',
            'time' => '10:00 صباحاً',
            'place' => 'الفرع الرئيسي',
            'client' => 'مستخدم تجريبي',
            'lawyer' => 'المحامي المشرف',
            'consultRef' => 'REF-TEST-001',
            'address' => 'الرياض — طريق الملك فهد',
            'paid' => true,
            'payLabel' => 'مدفوع ومؤكد',
            'qrSeed' => 'TEST-2026-0001',
        ]);

        try {
            $browsershot = Browsershot::html($sampleHtml)
                ->writeOptionsToFile()
                ->setCustomTempPath($tmpDir)
                ->newHeadless()
                ->noSandbox()
                ->emulateMedia('screen')
                ->showBackground()
                ->timeout(90)
                ->format('A4')
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

            if ($nodePath) $browsershot->setNodeBinary($nodePath);
            if ($npmPath) $browsershot->setNpmBinary($npmPath);
            if ($chromePath) $browsershot->setChromePath($chromePath);

            $pdfContent = $browsershot->pdf();
            $size = strlen($pdfContent);

            $this->info("   🎉 نجح التصيير عبر Browsershot بنجاح تام!");
            $this->info("   📄 حجم ملف الـ PDF الناتج: {$size} bytes (ملف ثنائي حقيقي ومصمم 100%)");

            return 0;
        } catch (\Throwable $e) {
            $this->error("   ❌ فشل تصيير Browsershot مع الخطأ التالي:");
            $this->line("   " . $e->getMessage());
            $this->newLine();
            $this->warn("🔧 إرشادات حل المشكلة على سيرفر Linux:");
            $this->line("   1. نفّذ الأمر التالي لتثبيت متصفح chrome الخاص بـ puppeteer:");
            $this->line("      npx puppeteer browsers install chrome");
            $this->line("   2. أو قم بتثبيت حزمة chromium عبر مدير الحزم:");
            $this->line("      sudo apt update && sudo apt install -y chromium-browser libgbm1 libnss3");
            $this->line("   3. تأكد من تحديد المسار في ملف .env إذا لزم الأمر:");
            $this->line("      CHROME_PATH=/usr/bin/chromium-browser");
            return 1;
        }
    }
}
