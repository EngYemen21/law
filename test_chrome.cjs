const puppet = require('puppeteer-core');
const fs = require('fs');

async function run() {
    console.log('1. Starting Chrome launch test...');
    const start = Date.now();
    try {
        const chromePath = '/opt/google/chrome/chrome';
        console.log(`2. Using Chrome executable: ${chromePath}`);
        
        const browser = await puppet.launch({
            executablePath: chromePath,
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
            dumpio: true, // Output Chrome's internal logs directly to console!
        });

        console.log(`3. Browser launched in ${((Date.now() - start) / 1000).toFixed(2)}s!`);
        
        console.log('4. Creating new page...');
        const page = await browser.newPage();
        
        console.log('5. Setting page HTML content...');
        await page.setContent('<h1 style="color:red; font-family:sans-serif;">بطاقة اختبار PDF التجريبية</h1><p>إذا ظهر هذا الملف فالنظام يعمل 100%</p>');
        
        console.log('6. Generating PDF stream...');
        const pdf = await page.pdf({ format: 'A4', printBackground: true });
        
        console.log(`7. PDF generated successfully! Size: ${pdf.length} bytes in ${((Date.now() - start) / 1000).toFixed(2)}s`);
        
        const testOutputFile = '/var/www/law/storage/app/test_node_output.pdf';
        fs.writeFileSync(testOutputFile, pdf);
        console.log(`8. Saved test PDF to: ${testOutputFile}`);
        
        await browser.close();
        console.log('🎉 SUCCESS! Chrome and Puppeteer are 100% working on this server!');
    } catch (e) {
        console.error('❌ ERROR in Chrome test:', e);
    }
}

run();
