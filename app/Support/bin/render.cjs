const fs = require('fs');
const path = require('path');

let puppet;
const candidates = [
    'puppeteer',
    'puppeteer-core',
    path.resolve(__dirname, '../../../node_modules/puppeteer'),
    path.resolve(__dirname, '../../../node_modules/puppeteer-core'),
    path.resolve(process.cwd(), 'node_modules/puppeteer'),
    path.resolve(process.cwd(), 'node_modules/puppeteer-core'),
];

for (const candidate of candidates) {
    try {
        puppet = require(candidate);
        if (puppet) break;
    } catch {
        // غير مثبّت في هذا المسار — يُجرَّب المرشّح التالي
    }
}

if (!puppet) {
    console.error('Error: Could not load puppeteer or puppeteer-core from any known path.');
    process.exit(1);
}

const [, , htmlFile, outputFile, chromePath, format = 'A4', timeoutArg] = process.argv;

if (!htmlFile || !outputFile) {
    console.error('Usage: node render.cjs <htmlFile> <outputFile> [chromePath] [format] [timeoutSec]');
    process.exit(1);
}

// حارس داخلي: يقتل العملية بعد المهلة مهما علق كروم (WS timeout/ProtocolError) —
// خروج نظيف بكود 1 يلتقطه PHP فيسقط للاحتياطي بدل تعليق يقتل PHP نفسه.
const timeoutSec = Math.max(5, parseInt(timeoutArg || '20', 10) || 20);
const watchdog = setTimeout(() => {
    console.error('PDF rendering timed out after ' + timeoutSec + 's (watchdog).');
    process.exit(1);
}, timeoutSec * 1000);

(async () => {
    try {
        let html = fs.readFileSync(htmlFile, 'utf8');
        // Strip @import url(...) to prevent network timeouts on servers
        // that cannot reach fonts.googleapis.com. Fallback fonts (Tahoma, Arial)
        // are already defined in the CSS and render Arabic text correctly.
        html = html.replace(/@import\s+url\([^)]*\)\s*;?/gi, '');

        const browser = await puppet.launch({
            executablePath: chromePath || '/opt/google/chrome/chrome',
            // بروفايل معزول في مجلد قابل للكتابة لمستخدم الويب (يمرّره PdfRenderer) —
            // بدونه يحاول كروم الكتابة في HOME وقد يتحطم تحت www-data
            userDataDir: process.env.CHROME_USER_DATA_DIR || undefined,
            timeout: timeoutSec * 1000,
            protocolTimeout: timeoutSec * 1000,
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--disable-extensions',
                '--hide-scrollbars',
            ],
        });

        const page = await browser.newPage();

        // الصفحة تحمل HTML كتبه مستخدم (محرّر المستندات) — فلا سكربت يعمل فيها، ولا طلب يخرج منها
        // إلّا خطوط Google المعلَنة في القوالب. غير ذلك (file:// أو عناوين داخليّة كـ169.254.169.254)
        // يُرفض: كروم الخادم ليس وسيطاً يقرأ ملفّاته أو شبكته لحساب كاتب المستند.
        await page.setJavaScriptEnabled(false);
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const url = request.url();
            if (url.startsWith('data:') || /^https:\/\/fonts\.(googleapis|gstatic)\.com\//.test(url)) {
                request.continue();
            } else {
                request.abort();
            }
        });

        await page.setContent(html, { waitUntil: 'domcontentloaded' });

        // Use buffer-based pdf() then write to file — identical to the
        // pattern that succeeded in test_chrome.cjs on the production server.
        const pdf = await page.pdf({
            format: format,
            printBackground: true,
            margin: { top: '10mm', right: '10mm', bottom: '10mm', left: '10mm' }
        });

        fs.writeFileSync(outputFile, pdf);
        await browser.close();
        clearTimeout(watchdog);
        console.log('PDF_OK');
        process.exit(0);
    } catch (e) {
        console.error('PDF rendering failed:', e.message);
        process.exit(1);
    }
})();
