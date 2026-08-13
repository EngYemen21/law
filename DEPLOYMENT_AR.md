# دليل نشر منصّة «سلاسل بابل» على سيرفر إنتاجيّ (Production)

> مرجع تشغيليّ متكامل. المكدّس: **PHP 8.3 · Laravel 13 · Inertia/React 19 (Vite) · MySQL · Reverb (WebSockets) · طابور database + عامل · مجدول cron**.
> خدمات خارجيّة: **تقنيات (OTP)** · **Resend (بريد)** · **Moyasar (دفع)** · **Zoom (اجتماعات)** · (اختياري: GLM/Gemini للـAI).
>
> ⚠️ **حرِج**: المنصّة لا تعمل «بشكل صحيح» بمجرّد رفع الكود — تحتاج **٣ عمليّات خلفيّة دائمة**: (1) خادم الويب PHP-FPM، (2) **عامل الطابور** `queue:work`، (3) **المجدول** `schedule:run` عبر cron، (4) **Reverb** للبثّ الحيّ. بدون العامل: لا تُرسَل إيميلات الاجتماعات ولا تُولَّد الملخّصات. بدون cron: لا تذكير ولا إطلاق روابط الجلسات. بدون Reverb: لا مزامنة لحظيّة.

---

## 0) قائمة ما قبل النشر (إصلاحات كود موصى بها أولاً)
هذه فجوات معروفة يُفضّل معالجتها قبل الإنتاج (اختياريّة لكنها تمنع سلوكاً خاطئاً):
1. **تبديل الحساب أثناء المعاينة**: امنع `switchAccount` أثناء الانتحال وأخفِ المُبدّل (`AuthController::switchAccount` يمسح `impersonator_id` أو `abort_if` عند وجوده؛ و`HandleInertiaRequests` لا يُصدر `accounts` أثناء الانتحال).
2. **كلمة مرور الموظف الوهميّة**: أزِل عرض `generatedPassword` من `Admin/StaffController` (الدخول OTP).
3. **موثوقيّة `starts_at`**: اجعل نماذج الاجتماع/الدعوة تلتقط تاريخاً/وقتاً قابلاً للتحليل (منتقي date/time) وإلا لا يعمل التذكير مع التواريخ العربيّة الحرّة.
4. **أمان الأسرار**: `AUTH_DEV_OTP=` (فارغ) في الإنتاج، وتدوير `RESEND_API_KEY`، وعدم دفع `.env` لأي مستودع.

---

## 1) متطلّبات السيرفر (Ubuntu 22.04/24.04 مثال)
```bash
# PHP 8.3 + الامتدادات المطلوبة
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd \
  php8.3-redis mysql-server nginx redis-server supervisor git unzip

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Node.js 20 LTS (لبناء أصول Vite)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```
الامتدادات الأساسيّة لـLaravel: `mbstring, xml, curl, openssl, pdo_mysql, bcmath, ctype, fileinfo, tokenizer, intl, gd, zip`.

---

## 2) قاعدة البيانات
```sql
CREATE DATABASE lawyer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lawyer_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON lawyer.* TO 'lawyer_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## 3) تجهيز حسابات الخدمات الخارجيّة (قبل الضبط)
| الخدمة | المطلوب | ملاحظات |
|---|---|---|
| **تقنيات (Taqnyat)** | Bearer Token من قسم المطوّرين (Application، خدمة SMS) + **اسم مُرسِل معتمد ونشط** | تحقّق: `curl -H "Authorization: Bearer KEY" https://api.taqnyat.sa/account/balance` يجب ألّا يعيد `Invalid ApiKey`. الدخول لا يعمل بدون مفتاح صالح. |
| **Resend** | API Key + **توثيق النطاق `salasel.sa`** (SPF/DKIM في DNS) | بدون توثيق النطاق يُرفض الإرسال. `MAIL_FROM_ADDRESS` يجب أن يكون على النطاق الموثّق. |
| **Moyasar** | `sk_live_`/`pk_live_` + Webhook secret | أضِف webhook على `https://DOMAIN/webhooks/moyasar`. |
| **Zoom** | Server-to-Server OAuth (account/client id+secret) + Meeting SDK key/secret + Webhook secret | أضِف Event Subscription على `https://DOMAIN/webhooks/zoom`. بدون مفاتيح يعمل رابط احتياطيّ فقط. |
| **AI (اختياري)** | GLM (z.ai) و/أو Gemini | لميزات المساعد/الفرز؛ بدونها تتعطّل ميزات AI فقط. |

---

## 4) رفع الكود والإعداد
```bash
cd /var/www
git clone <REPO_URL> salasel && cd salasel      # أو رفع الملفّات

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# حرّر .env (انظر القسم 5)، ثمّ:
php artisan migrate --force
php artisan db:seed --force                       # لأوّل نشر فقط (يُنشئ الأدوار/الصلاحيات/الحسابات)
php artisan storage:link

# بناء أصول الواجهة (مطلوب — Vite ينتج public/build)
npm ci
npm run build                                     # يجب ضبط VITE_* في .env قبل البناء

# صلاحيّات الكتابة
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# تخبئة الإنتاج (بعد ضبط .env نهائيّاً)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```
> **مهمّ**: أصول Vite تُبنى بقيم `VITE_REVERB_*` وقت البناء (الـfrontend echo يحتاجها). أي تغيير عليها يتطلّب إعادة `npm run build`.
> **مهمّ**: بعد أي تعديل على `.env` شغّل `php artisan config:clear && php artisan config:cache`.

---

## 5) ملفّ البيئة `.env` للإنتاج (القيم الحاسمة)
```dotenv
APP_NAME="سلاسل بابل"
APP_ENV=production
APP_DEBUG=false                     # ⚠️ إلزاميّ في الإنتاج
APP_URL=https://DOMAIN               # https الحقيقيّ
APP_TIMEZONE=Asia/Riyadh
APP_LOCALE=ar

# قاعدة البيانات
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=lawyer
DB_USERNAME=lawyer_user
DB_PASSWORD=STRONG_PASSWORD

# الجلسة/التخبئة/الطابور (Redis موصى به للإنتاج)
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.salasel.sa
CACHE_STORE=redis
QUEUE_CONNECTION=database            # (أو redis — كلاهما يحتاج عاملاً)
REDIS_HOST=127.0.0.1

# البثّ الحيّ (Reverb)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=xxxxx
REVERB_APP_KEY=xxxxx
REVERB_APP_SECRET=xxxxx
REVERB_HOST="DOMAIN"                 # النطاق العامّ خلف بروكسي wss
REVERB_PORT=443
REVERB_SCHEME=https
# تُبنى في الواجهة (يجب ضبطها قبل npm run build):
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# البريد (Resend)
MAIL_MAILER=resend
RESEND_API_KEY=re_xxx               # مفتاح جديد مُدوَّر
MAIL_FROM_ADDRESS="no-reply@salasel.sa"   # نطاق موثّق في Resend
MAIL_FROM_NAME="${APP_NAME}"

# تقنيات (OTP) — إلزاميّ لعمل الدخول
TAQNYAT_API_KEY=BEARER_TOKEN_SALEH
TAQNYAT_SENDER=اسم_المُرسِل_المعتمد
TAQNYAT_BASE_URL=https://api.taqnyat.sa

# ⚠️ التجاوز التطويريّ — يجب أن يبقى فارغاً في الإنتاج (بلا قيمة)
AUTH_DEV_OTP=

# Moyasar / Zoom
MOYASAR_SECRET_KEY=sk_live_xxx
MOYASAR_PUBLISHABLE_KEY=pk_live_xxx
MOYASAR_WEBHOOK_SECRET=xxx
ZOOM_ACCOUNT_ID=... ; ZOOM_CLIENT_ID=... ; ZOOM_CLIENT_SECRET=...
ZOOM_SDK_KEY=... ; ZOOM_SDK_SECRET=... ; ZOOM_WEBHOOK_SECRET=...

# AI (اختياري)
GLM_API_KEY=... ; GEMINI_API_KEY=...
AI_TICKET_AGENT=true
```

---

## 6) العمليّات الخلفيّة الدائمة (Supervisor + cron) — ⚠️ لا غنى عنها

### أ) عامل الطابور (بدونه لا بريد اجتماعات ولا توليد ملخّصات)
`/etc/supervisor/conf.d/salasel-worker.conf`:
```ini
[program:salasel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/salasel/artisan queue:work --queue=default --tries=3 --max-time=3600 --sleep=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/salasel/storage/logs/worker.log
stopwaitsecs=3600
```

### ب) Reverb (خادم WebSockets للبثّ الحيّ)
`/etc/supervisor/conf.d/salasel-reverb.conf`:
```ini
[program:salasel-reverb]
command=php /var/www/salasel/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/salasel/storage/logs/reverb.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start all
```

### ج) المجدول (cron) — بدونه لا تذكير/إطلاق روابط/جلب ملخّصات
```bash
sudo crontab -u www-data -e
# أضِف:
* * * * * cd /var/www/salasel && php artisan schedule:run >> /dev/null 2>&1
```
> هذا يشغّل الأوامر المجدولة في `routes/console.php`: `meetings:send-reminders` (كل دقيقة)، `zoom:release-links` (كل دقيقة)، `zoom:pull-summaries` (كل 5د).

---

## 7) Nginx + HTTPS
`/etc/nginx/sites-available/salasel`:
```nginx
server {
    listen 80;
    server_name DOMAIN;
    root /var/www/salasel/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # بروكسي WebSocket لـ Reverb (البثّ الحيّ)
    location /app { # مسار Reverb الافتراضيّ
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    client_max_body_size 5M;   # يوافق حدّ رفع الملفّات (2MB منطقيّاً + هامش)
    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/salasel /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d DOMAIN        # HTTPS إلزاميّ (الجلسات الآمنة + wss + Zoom SDK)
```

---

## 8) ضبط الويب-هوكس (بعد HTTPS)
- **Moyasar** → `https://DOMAIN/webhooks/moyasar` (محميّ بـ`MOYASAR_WEBHOOK_SECRET`، مستثنى من CSRF).
- **Zoom** → `https://DOMAIN/webhooks/zoom` (محميّ بتوقيع HMAC، مستثنى من CSRF).

---

## 9) قائمة تحقّق أمان الإنتاج
- [ ] `APP_ENV=production` و`APP_DEBUG=false`.
- [ ] `AUTH_DEV_OTP=` **فارغ** (وإلا يبقى الدخول برمز ثابت — التجاوز محصور بـlocal/testing لكن تأكّد من البيئة).
- [ ] `TAQNYAT_API_KEY` صالح ومُرسِل معتمد (اختبر balance).
- [ ] نطاق Resend موثّق ومفتاح مُدوَّر؛ `.env` غير مدفوع لأي مستودع.
- [ ] HTTPS مفعّل + `SESSION_SECURE_COOKIE=true`.
- [ ] صلاحيّات `storage`/`bootstrap/cache` لـ`www-data`.
- [ ] `config:cache`+`route:cache`+`view:cache` بعد ضبط `.env`.
- [ ] جدار ناريّ يمنع الوصول المباشر لمنفذ Reverb (8080) وMySQL/Redis من الخارج.

---

## 10) التحقّق بعد النشر (Smoke tests)
1. **الدخول**: افتح `/login` → أدخل هوية حساب مبذور → يصل رمز SMS فعليّ → دخول للوحة الدور. (يؤكّد تقنيات + الجلسات.)
2. **تعدّد الحسابات**: هوية لها دوران → يظهر مُنتقي الحساب → اختيار → تبديل من الشريط الجانبي.
3. **البريد**: أنشئ اجتماعاً → تحقّق وصول `MeetingScheduledMail` (يؤكّد Resend + **العامل يعمل**). راقب `storage/logs/worker.log`.
4. **التذكير/الروابط**: تأكّد `php artisan schedule:list` يعرض الأوامر، وأنّ cron يعمل (`grep CRON /var/log/syslog`).
5. **البثّ الحيّ**: افتح لوحتين → غيّر حالة → تحديث لحظيّ (يؤكّد Reverb + بروكسي wss).
6. **الدفع**: دورة استشارة → ميسّر ببطاقة اختبار/حيّة → callback + webhook.
7. **الطابور الفاشل**: `php artisan queue:failed` يجب أن يكون فارغاً.

---

## 11) إجراء التحديث (Deploy لاحق)
```bash
cd /var/www/salasel
php artisan down                      # وضع الصيانة
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo supervisorctl restart salasel-worker:* salasel-reverb   # ⚠️ إعادة تشغيل العامل بعد كل تحديث كود
php artisan up
```
> **قاعدة ذهبيّة**: عامل الطابور يحمّل الكود في الذاكرة — **أعِد تشغيله بعد كل نشر** وإلا ينفّذ كوداً قديماً.

---

## 12) النسخ الاحتياطيّ والمراقبة (موصى)
- نسخ MySQL يوميّاً (`mysqldump`) + `storage/app` (المستندات المرفوعة).
- راقب: `storage/logs/laravel.log`, `worker.log`, `reverb.log`, و`queue:failed`.
- تنبيهات على فشل الطابور وتوقّف Reverb/العامل (Supervisor autorestart يغطّي الانهيار).
