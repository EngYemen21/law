<?php
// اختبار تشغيل render.cjs من PHP بنفس الطريقة التي يستخدمها PdfRenderer
$htmlFile = '/var/www/law/storage/app/browsershot-tmp/diag-6a83bb8fcda8e.html';
$pdfFile = '/tmp/php_shell_test.pdf';
$node = '/usr/bin/node';
$script = '/var/www/law/app/Support/bin/render.cjs';
$chrome = '/opt/google/chrome/chrome';

$cmd = 'NODE_PATH="/var/www/law/node_modules" "' . $node . '" "' . $script . '" "' . $htmlFile . '" "' . $pdfFile . '" "' . $chrome . '" A4 2>&1';

echo "CMD: $cmd\n\n";
echo "ENV HOME=" . getenv('HOME') . "\n";
echo "ENV USER=" . getenv('USER') . "\n\n";

$output = shell_exec($cmd);
echo "OUTPUT: $output\n";

if (file_exists($pdfFile)) {
    echo "SUCCESS: " . filesize($pdfFile) . " bytes\n";
} else {
    echo "FAILED: PDF file not created\n";
}
