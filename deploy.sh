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

# 4. بناء ملفات الواجهات والأصول للإنتاج عبر Vite
npm ci
npm run build

# 5. ترحيل قواعد البيانات
php artisan migrate --force

# 6. تفريغ وإعادة بناء الكاشات لتحقيق أعلى سرعة
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. التأكد من ربط مجلد التخزين
php artisan storage:link || true

# 8. إعادة تشغيل طوابير الانتظار وخدمات البث
php artisan queue:restart
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl restart all || true
fi

# 9. إتاحة الموقع للزوار
php artisan up

echo "=========================================="
echo "✅ Deployment Completed Successfully!"
echo "=========================================="
