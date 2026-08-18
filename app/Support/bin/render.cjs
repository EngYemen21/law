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
    } catch (e) {}
}

if (!puppet) {
    console.error('Error: Could not load puppeteer or puppeteer-core from any known path.');
    process.exit(1);
}

const [, , htmlFile, outputFile, chromePath, format = 'A4'] = process.argv;

if (!htmlFile || !outputFile) {
    console.error('Usage: node render.cjs <htmlFile> <outputFile> [chromePath] [format]');
    process.exit(1);
}

(async () => {
    try {
        let html = fs.readFileSync(htmlFile, 'utf8');
        // Strip @import url(...) to prevent network timeouts on servers
        // that cannot reach fonts.googleapis.com. Fallback fonts (Tahoma, Arial)
        // are already defined in the CSS and render Arabic text correctly.
        html = html.replace(/@import\s+url\([^)]*\)\s*;?/gi, '');

        const browser = await puppet.launch({
            executablePath: chromePath || '/opt/google/chrome/chrome',
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--hide-scrollbars',
                '--disable-software-rasterizer',
                '--force-color-profile=srgb',
                '--lang=ar-SA',
            ],
        });

        const page = await browser.newPage();
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
        await page.emulateMediaType('screen');
        
        await page.pdf({
            path: outputFile,
            format: format,
            printBackground: true,
            margin: { top: '10mm', right: '10mm', bottom: '10mm', left: '10mm' }
        });

        await browser.close();
        console.log('PDF_OK');
        process.exit(0);
    } catch (e) {
        console.error('PDF rendering failed:', e.message);
        process.exit(1);
    }
})();
