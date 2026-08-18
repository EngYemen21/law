/**
 * تشخيص دقيق لسبب فشل Page.printToPDF مع HTML القالب المعقد.
 * يختبر عدة مستويات من تعقيد HTML لتحديد أي عنصر يسبب الفشل.
 */
const fs = require('fs');
const path = require('path');

let puppet;
for (const m of ['puppeteer', 'puppeteer-core',
    path.resolve(process.cwd(), 'node_modules/puppeteer'),
    path.resolve(process.cwd(), 'node_modules/puppeteer-core')]) {
    try { puppet = require(m); if (puppet) break; } catch(e) {}
}
if (!puppet) { console.error('Cannot load puppeteer'); process.exit(1); }

const chromePath = process.argv[2] || '/opt/google/chrome/chrome';

const tests = [
    {
        name: '1. HTML بسيط',
        html: '<html><body><h1 style="color:red">Test</h1></body></html>'
    },
    {
        name: '2. HTML مع CSS معقد',
        html: `<html><head><style>
            *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            body{margin:0;padding:0;background:#fff;font-family:Tahoma,Arial,sans-serif}
            .box{border-radius:18px;overflow:hidden;background:#fff;max-width:560px;margin:0 auto;border:1px solid #E1E8EE}
            .head{background:linear-gradient(135deg,#5B4BD6,#7C3AED 55%,#9061F9);color:#fff;padding:18px 20px}
        </style></head><body><div class="box"><div class="head"><h1>بطاقة تجريبية</h1></div><p style="padding:20px">محتوى تجريبي بالعربي</p></div></body></html>`
    },
    {
        name: '3. HTML مع SVG',
        html: `<html><head><style>*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0;font-family:Tahoma,sans-serif}</style></head><body>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <p>نص تجريبي</p></body></html>`
    },
    {
        name: '4. HTML مع جدول كبير',
        html: `<html><head><style>*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0;font-family:Tahoma,sans-serif}table{width:100%;border-collapse:collapse}td{border:1px solid #ccc;padding:8px}</style></head><body>
        <table>${'<tr><td>عمود 1</td><td>عمود 2</td><td>عمود 3</td></tr>'.repeat(50)}</table></body></html>`
    },
];

// If an HTML file path is provided as third argument, test that too
const htmlFilePath = process.argv[3];
if (htmlFilePath && fs.existsSync(htmlFilePath)) {
    let fileHtml = fs.readFileSync(htmlFilePath, 'utf8');
    fileHtml = fileHtml.replace(/@import\s+url\([^)]*\)\s*;?/gi, '');
    tests.push({ name: '5. HTML من ملف القالب الفعلي', html: fileHtml });

    // Also test with goto instead of setContent
    tests.push({ name: '6. HTML من ملف القالب عبر goto (file://)', file: htmlFilePath });
}

(async () => {
    console.log('=== تشخيص Page.printToPDF مع مستويات مختلفة من HTML ===\n');
    console.log(`Chrome: ${chromePath}\n`);

    const browser = await puppet.launch({
        executablePath: chromePath,
        headless: true,
        dumpio: false,
        args: [
            '--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu',
            '--disable-dev-shm-usage', '--no-first-run',
            '--disable-extensions', '--hide-scrollbars',
        ],
    });

    for (const test of tests) {
        process.stdout.write(`${test.name}: `);
        const page = await browser.newPage();
        try {
            if (test.file) {
                // Write stripped version to temp file
                let rawHtml = fs.readFileSync(test.file, 'utf8');
                rawHtml = rawHtml.replace(/@import\s+url\([^)]*\)\s*;?/gi, '');
                const tmpFile = test.file + '.stripped.html';
                fs.writeFileSync(tmpFile, rawHtml);
                await page.goto('file://' + tmpFile, { waitUntil: 'domcontentloaded', timeout: 15000 });
                fs.unlinkSync(tmpFile);
            } else {
                await page.setContent(test.html, { waitUntil: 'domcontentloaded', timeout: 15000 });
            }
            const pdf = await page.pdf({ format: 'A4', printBackground: true, margin: { top: '10mm', right: '10mm', bottom: '10mm', left: '10mm' } });
            console.log(`✅ نجح (${pdf.length} bytes)`);
        } catch (e) {
            console.log(`❌ فشل: ${e.message}`);
        }
        await page.close();
    }

    await browser.close();
    console.log('\n=== انتهى التشخيص ===');
})();
