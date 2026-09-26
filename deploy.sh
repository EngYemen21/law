#!/bin/bash
set -e

echo "=========================================="
echo "🚀 Starting Automated Deployment..."
echo "=========================================="

# أي إخفاق (set -e) كان يترك الموقع في وضع الصيانة أبداً — هذا يعيده دائماً
trap 'php artisan up || true' EXIT

# 0. نسخة احتياطية قبل أي ترحيل — المهاجرات قد تُسقط أعمدة/جداول بلا رجعة
BACKUP_DIR="${BACKUP_DIR:-$HOME/law-backups}"
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/law-$(date +%Y%m%d-%H%M%S).sql"
if command -v mysqldump &> /dev/null; then
    DB_NAME=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".database");' 2>/dev/null | tail -n1)
    DB_USER=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".username");' 2>/dev/null | tail -n1)
    DB_PASS=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".password");' 2>/dev/null | tail -n1)
    echo "💾 Backing up $DB_NAME -> $BACKUP_FILE"
    MYSQL_PWD="$DB_PASS" mysqldump --no-tablespaces --single-transaction -u "$DB_USER" "$DB_NAME" > "$BACKUP_FILE"
    echo "✅ Backup written ($(du -h "$BACKUP_FILE" | cut -f1))"
else
    echo "⛔ mysqldump غير متوفّر — أوقف النشر وخذ نسخة احتياطية يدوياً قبل الترحيل."
    exit 1
fi

# 1. تفعيل وضع الصيانة المؤقت
php artisan down || true

# 2. سحب آخر التحديثات من الفرع الرئيسي
# ملفّا القفل لا يُعدَّلان على الخادم أبداً — نسختهما من المستودع هي الحقّ. محاولة `npm ci` فاشلة سابقة
# عدّلت package-lock.json محلّياً فرفض `git pull` السحب وبقي الموقع على نصف تحديث (2026-09-26)
git checkout -- package-lock.json composer.lock 2>/dev/null || true
git pull origin main

# 3. تحديث حزم Composer للإنتاج
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 4. تثبيت حزم الواجهة ومحرك طباعة التقارير PDF
# ملفّ القفل مولَّدٌ بـnpm 11؛ وnpm 10 يرفضه في `npm ci` («Missing: @emnapi/core …» — حزمٌ اختياريّة يسجّلها
# الإصداران بطريقتين). فمع npm 10 يُكمل `npm install` الناقص بنفسه، وتعديله لملفّ القفل على الخادم لا يبقى:
# السحب القادم يعيده لنسخة المستودع (الخطوة 2). وnpm 11 يبقى الأصحّ: `npm install -g npm@11`.
if [ "$(npm --version | cut -d. -f1)" -ge 11 ]; then
    npm ci
else
    echo "⚠️ npm $(npm --version) — يُستعمل npm install بدل npm ci (حدّثه لاحقاً إلى 11)"
    npm install --no-audit --no-fund
fi
if ! npx puppeteer browsers installed | grep -q "chrome"; then
    npx puppeteer browsers install chrome || true
fi
npm run build

# 5. إيقاف العمّال قبل الترحيل — عامل يعمل بالكود القديم يضرب أعمدة مُرحَّلة (SQLSTATE 42S22)
php artisan queue:restart
if command -v supervisorctl &> /dev/null && [ "$EUID" -eq 0 ]; then
    supervisorctl stop all || true
fi

# 6. ترحيل قواعد البيانات
php artisan migrate --force

# 6-ب. مزامنة المصادر القانونيّة المشحونة في database/legal-sources — متكرّرة بلا أثر:
# الجديد مسودة، والمعتمد لا يُخفَّض، وملفٌّ معطوب يُسقط النشر هنا قبل أيّ كتابة (set -e)
php artisan ai:sync-sources

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

# 8. إعادة تشغيل طوابير الانتظار وخدمات البث بالكود الجديد
php artisan queue:restart
if command -v supervisorctl &> /dev/null && [ "$EUID" -eq 0 ]; then
    supervisorctl start all || true
    supervisorctl restart all || true
fi

# 9. إتاحة الموقع للزوار
php artisan up
trap - EXIT

# 10. تحقّق صحّة بعد الإتاحة (نقطة /up المسجّلة في bootstrap/app.php)
APP_URL_LOCAL=$(php artisan tinker --execute='echo config("app.url");' 2>/dev/null | tail -n1)
if command -v curl &> /dev/null && [ -n "$APP_URL_LOCAL" ]; then
    HEALTH=$(curl -s -o /dev/null -w '%{http_code}' "$APP_URL_LOCAL/up" || echo 000)
    if [ "$HEALTH" != "200" ]; then
        echo "⚠️  فحص الصحّة أعاد $HEALTH — راجع السجلّات فوراً (النسخة الاحتياطية: $BACKUP_FILE)"
        exit 1
    fi
    echo "✅ Health check: 200"
fi

# 11. تحقّق من عامل الطابور: بلا عامل حيّ تُبنى أرشيفات Zoom داخل الطلب ⇒ 504
QUEUE_DRIVER=$(php artisan tinker --execute='echo config("queue.default");' 2>/dev/null | tail -n1)
if [ "$QUEUE_DRIVER" = "sync" ]; then
    echo "⚠️  QUEUE_CONNECTION=sync في الإنتاج — كل مهمة تُنفَّذ داخل طلب المستخدم."
    echo "    اضبطه على database (أو redis) وشغّل عاملاً: php artisan queue:work"
elif ! pgrep -f "artisan queue:work" > /dev/null 2>&1; then
    echo "⚠️  لا عامل طابور يعمل (artisan queue:work) — تسجيلات Zoom والإشعارات لن تُنفَّذ."
    echo "    راجع supervisorctl status، أو شغّله يدوياً: php artisan queue:work --tries=2"
else
    echo "✅ عامل الطابور يعمل ($QUEUE_DRIVER)"
fi

# 12. تحقّق من المجدول (cron): بدونه لا تُطلَق روابط الجلسات ولا تُرسَل التذكيرات ولا
# تُحسم الاجتماعات الفائتة — والأعطال صامتة تماماً: لا خطأ في أي سجلّ، فقط لا شيء يحدث.
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "✅ المجدول مسجَّل في crontab"
elif [ -f /etc/cron.d/laravel ] && grep -q "schedule:run" /etc/cron.d/laravel 2>/dev/null; then
    echo "✅ المجدول مسجَّل في /etc/cron.d/laravel"
else
    echo "⚠️  لا مدخل cron لـschedule:run — لن تُطلَق روابط الجلسات ولا التذكيرات."
    echo "    أضِفه: * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
fi
echo "=========================================="
echo "✅ Deployment Completed Successfully!"
echo "=========================================="
