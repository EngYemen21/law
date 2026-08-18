const puppet = require('puppeteer-core');
const fs = require('fs');

const [, , htmlFile, outputFile, chromePath, format = 'A4'] = process.argv;

if (!htmlFile || !outputFile) {
    console.error('Usage: node render.cjs <htmlFile> <outputFile> [chromePath] [format]');
    process.exit(1);
}

(async () => {
    try {
        const html = fs.readFileSync(htmlFile, 'utf8');
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
        process.exit(0);
    } catch (e) {
        console.error('PDF rendering failed:', e);
        process.exit(1);
    }
})();
