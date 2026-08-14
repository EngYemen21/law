#!/bin/bash
set -e

echo "=========================================="
echo "🚀 Starting Automated Deployment..."
echo "=========================================="

# 1. تفعيل وضع الصيانة المؤقت
php artisan down || true

# 2. سحب آخر التحديثات من الفرع الرئيسي
git pull origin main

# 3. تحديث حزم Composer للإنتاج
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 4. تثبيت حزم الواجهة ومحرك طباعة التقارير PDF
npm ci
if ! npx puppeteer browsers installed | grep -q "chrome"; then
    npx puppeteer browsers install chrome || true
fi
npm run build

# 5. ترحيل قواعد البيانات
php artisan migrate --force

# 6. إعداد مجلدات التخزين والمؤقت لتقارير PDF
mkdir -p storage/app/browsershot-tmp
chmod -R 775 storage || true
php artisan storage:link || true

# 7. تفريغ وإعادة بناء الكاشات لتحقيق أعلى سرعة
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. إعادة تشغيل طوابير الانتظار وخدمات البث
php artisan queue:restart
if command -v supervisorctl &> /dev/null && [ "$EUID" -eq 0 ]; then
    supervisorctl restart all || true
fi

# 9. إتاحة الموقع للزوار
php artisan up

echo "=========================================="
echo "✅ Deployment Completed Successfully!"
echo "=========================================="
