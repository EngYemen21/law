# دليل نشر منصّة «سلاسل بابل» على سيرفر إنتاجيّ (Production)

> مرجع تشغيليّ متكامل. المكدّس: **PHP 8.3 · Laravel 13 · Inertia/React 19 (Vite) · MySQL · Reverb (WebSockets) · طابور database + عامل · مجدول cron**.
> خدمات خارجيّة: **تقنيات (OTP)** · **Resend (بريد)** · **Moyasar (دفع)** · **Zoom (اجتماعات)** · (اختياري: GLM/Gemini للـAI).
>
> **آخر تحديث: 2026-09-26** — خطّة رفع الدفعة الكبيرة في القسم «★» أدناه (قبل §0).
> حالة الشيفرة (2026-09-26): **2577/2581 اختباراً خضراء** (الأربعة الباقية: ثلاثةٌ معروفة لتجاوز رمز الدخول في التطوير + واحدٌ أُصلح بعدها) · 137 مهاجرة · `tsc`/`build`/`pint` نظيفة.
>
> ⚠️ **حرِج**: المنصّة لا تعمل «بشكل صحيح» بمجرّد رفع الكود — تحتاج **٣ عمليّات خلفيّة دائمة**: (1) خادم الويب PHP-FPM، (2) **عامل الطابور** `queue:work`، (3) **المجدول** `schedule:run` عبر cron، (4) **Reverb** للبثّ الحيّ. بدون العامل: لا تُرسَل إيميلات الاجتماعات ولا تُولَّد الملخّصات. بدون cron: لا تذكير ولا إطلاق روابط الجلسات. بدون Reverb: لا مزامنة لحظيّة.

---

## ★) خطّة رفع دفعة 2026-09-26 — اقرأها أوّلاً

هذه الدفعة كبيرة (فرع `refactor/journey-engine-2026-09-19`): **٤٠ مهاجرة جديدة** منذ آخر نشرٍ لـ`main`،
ومكتبة PHP جديدة، ومصادر قانونيّة مشحونة، وأحداث Zoom جديدة، وأمر مجدول جديد، وإعداداتٌ صارت من الشاشة.

### أ) قبل التشغيل (على جهاز التطوير)
1. **الحفظ والدفع:** لا شيء من هذه الدفعة في git بعد. احفظ العمل على فرعه ← ادفعه ← ادمجه في `main` ← ادفع `main`
   (الحاجز في §0 أدناه). **لا يُحفظ `.env.bak-20260912` أبداً.**
2. **مجلّد `database/legal-sources/`** يجب أن يكون ضمن الحفظ (٧ ملفّات JSON) — بدونه يبدأ الخادم بلا مصادر قانونيّة.
3. `composer.lock` ضمن الحفظ (يضيف `bacon/bacon-qr-code` و`dasprid/enum` لرموز QR الحقيقيّة).

### ب) التشغيل على الخادم
**npm:** ملفّ القفل مولَّدٌ بـnpm 11. مع npm 10 يستعمل السكربت `npm install` بدل `npm ci` تلقائياً وينجح البناء؛
والأصحّ لاحقاً توحيد الإصدار: `npm install -g npm@11`.
```bash
cd /var/www/law && bash deploy.sh
```
السكربت وحده يؤدّي كلّ ما يلزم بالترتيب: نسخة احتياطيّة ← صيانة ← سحب ← `composer install` (المكتبة الجديدة) ←
بناء الواجهة ← **إيقاف العمّال** ← `migrate --force` (الأربعون) ← **`ai:sync-sources`** (المصادر القانونيّة) ←
الكاشات ← تشغيل العمّال ← فحص الصحّة وعامل الطابور.

⚠️ **مهاجراتٌ تنقل بياناتٍ لا بنيةً فقط** — النسخة الاحتياطيّة هي الرجوع الحقيقيّ منها:
`2026_09_24_230000_move_converted_tickets_to_execution_status` · `2026_09_26_130000_settings_hours_to_minutes`
(تحوّل إعدادات الساعات المحفوظة إلى دقائق ×60) · `2026_09_26_150200_seed_legal_department_documents`
(قوائم مستندات الأقسام) · ومهاجرات حذف «المخاطبات» ومعرّف حدث جوجل (مدمِّرة).

### ج) بعد التشغيل مباشرةً
1. **الصلاحيّات:** `php artisan db:seed --class=PermissionSeeder --force` (الدفعة تحذف «المخاطبات» وتضيف «اعتماد المستندات»).
2. **عامل الطابور يعمل** (آخر سطر من مخرجات السكربت ✅) — هو الذي يُغلق غرفة Zoom بعد إنهاء الجلسة، ويُرسل البريد.
3. **cron يعمل** (`schedule:run` كلّ دقيقة) — أمرٌ جديد `sessions:close-stale` كلّ ربع ساعة: ينبّه الطاقم مرّةً
   ثمّ يُنهي الجلسة المنسيّة في النظام وفي Zoom.
4. **Zoom (لوحة Marketplace):** رابط الويب-هوك `https://DOMAIN/webhooks/zoom` مع الأحداث: `meeting.started` ·
   `meeting.ended` · `meeting.participant_joined` · `meeting.participant_left` · `recording.started` ·
   `recording.stopped` · `recording.paused` · `recording.resumed` · `recording.completed` · `meeting.summary_completed`.
   بدونها لا تتحدّث غرفة الجلسة لحظيّاً.
5. **حدود الرفع:** في `php.ini` لـPHP-FPM: `upload_max_filesize = 12M` و`post_max_size = 14M`، وفي Nginx
   `client_max_body_size 12M` (§7) — التطبيق يقبل مرفقاتٍ حتى 10MB، والحدّ الأدنى منها يرفض الملفّ قبل أن يصل التطبيق
   فيرى المستخدم خطأ «413» بدل رسالةٍ عربيّة.
6. **`.env`:** `APP_URL` = العنوان العامّ بـHTTPS (تُبنى منه روابط التحقّق في رموز QR). **لا تغيّر `APP_KEY`** —
   رموز التحقّق المطبوعة موقّعةٌ به؛ إن اضطُررت فضع القديم في `APP_PREVIOUS_KEYS`.

### د) من شاشة الإعدادات (`/admin/settings`) — بيد الإدارة
- **الرقم الضريبيّ للمكتب** (١٥ رقماً يبدأ وينتهي بـ3) — بدونه **لا يُطبع رمز فاتورة ZATCA** ولا سطر الضريبة (عمداً: لا رقمَ مختلَق).
- بيانات المكتب: الاسم (يظهر في كلّ مكان: الدخول، عنوان المتصفّح، المستندات، المساعد الذكيّ) · الهاتف · الموقع ·
  **البريد والعنوان والمدينة** (جديدة؛ العنوان والمدينة يرثان `OFFICE_ADDRESS/CITY` من `.env` حتى تُضبط).
- مسمّيات المتحدّثين للعميل · مهل السداد ومهلة الدفعة الأولى · ساعات الاستشارات والمسافة بين المواعيد · حدّ
  إعادة الجدولة ومهلتها · مهل التنبيه والإغلاق (كلّها **بالدقائق** الآن).

### هـ) المصادر القانونيّة
- المعتمد في التطوير (المعاملات المدنيّة ٧٢١ + التنفيذ الجديد ٦٥) يصل **معتمداً**.
- الخمسة الجديدة (العمل ٢٥٠ · الإثبات ١٢٩ · المرافعات الشرعيّة ٢٤٢ · الأحوال الشخصيّة ٢٥٢ · التنفيذ ١٤٣٣هـ ٩٨)
  تصل **مسودّة** — لا يستعملها الذكاء الاصطناعي حتى يعتمدها محامٍ أو الإدارة من `/admin/legal-sources` ←
  «اعتماد النظام كاملاً» بكتابة اسمه: `نظام العمل` · `نظام الإثبات` · `نظام المرافعات الشرعية` ·
  `نظام الأحوال الشخصية` · `نظام التنفيذ (1433هـ)`. راجع أوّلاً الفصل الخامس عشر من نظام العمل والموادّ الموسومة «(ملغاة)».
- النصوص من صفحات هيئة الخبراء المؤرشفة (فبراير 2026) — يؤكّد المحامي عند الاعتماد ألّا تعديل بعدها.

### و) تحقّقٌ بعد النشر (إضافةً إلى §10)
1. `/admin/dashboard` تعرض أحمال المحامين والتخصّصات ونبض العمليات (كانت تفرغ من الذاكرة المؤقّتة).
2. رابطٌ مجهول ⇒ صفحة «غير موجود» عربيّة داخل النظام؛ رابط غرفةٍ منتهية ⇒ عودةٌ برسالة لا صفحة خطأ.
3. فاتورة PDF (بعد ضبط الرقم الضريبيّ) تحمل رمز ZATCA يقرؤه الجوّال؛ بطاقة الموعد تحمل رمز تحقّق يفتح `/verify/…`.
4. `php artisan ai:sync-sources` مرّةً ثانية ⇒ «لا جديد» (متكرّرٌ بلا أثر).
5. إنهاء جلسة من النظام ⇒ تُغلق غرفتها في Zoom خلال دقيقة (يؤكّد عامل الطابور).

---

## 0) قائمة ما قبل النشر

### 🔴 حاجز إلزاميّ: العمل ليس في المستودع بعد

`deploy.sh` يشغّل `git pull origin main`. تشغيل النشر قبل تجهيز الإصدار **ينشر الشيفرة القديمة** ولا يصل منه شيء. تحقّق أولاً:

```bash
git status --short | wc -l                       # يجب أن يكون 0
git rev-list --count origin/main..HEAD           # يجب أن يكون 0
```

إن لم يكونا صفراً: التزم العمل على فرعه → ادفعه → ادمجه في `main` → ادفع `main`. **بعدها فقط** ابدأ النشر.

### حالة الإصلاحات الموصى بها سابقاً (محدَّثة 2026-08-21)

| البند | الحالة |
|---|---|
| **أمان الأسرار** — `AUTH_DEV_OTP` فارغ في الإنتاج | ✅ **مُنفَّذ وأمتن**: [AppServiceProvider.php:43](app/Providers/AppServiceProvider.php:43) يرمي استثناءً عند الإقلاع إن ضُبط خارج `local/testing`، فلا يعمل التطبيق أصلاً بمفتاح تجاوز مسرَّب. يبقى عليك تدوير `RESEND_API_KEY` وعدم دفع `.env`. |
| **موثوقيّة `starts_at`** | ✅ **مُعالَج**: الاجتماعات والدعوات والاستشارات وجلسات القضايا تخزّن `starts_at` حقيقيًّا (مهاجرات `2026_08_01` و`2026_08_10`)، والتذكيرات تعتمده لا النصّ العربيّ الحرّ. |
| **كلمة مرور الموظف الوهميّة** (`generatedPassword`) | ⚠️ **قائم**: [Admin/StaffController.php:59](app/Http/Controllers/Admin/StaffController.php:59) ما زال يمرّرها وتُعرض في [admin/staff.tsx](resources/js/pages/admin/staff.tsx). الدخول بالـOTP فلا قيمة لعرضها. |
| **تبديل الحساب أثناء الانتحال** | ⚠️ **قائم**: `AuthController::switchAccount` لا يفحص `impersonator_id`، و`HandleInertiaRequests` يُصدر `accounts` أثناء الانتحال. الخطر محدود (الإدارة تملك الانتحال أصلاً) لكنه يُربك مسار «العودة للإدارة». |

### تغييرات سلوكيّة تُبلَّغ للمكتب قبل التسليم

1. **كل موظف يرى كل شيء** — التذاكر والقضايا والتنفيذ والاجتماعات والاستشارات والتسجيلات، بلا حصر (أُزيل كيان «الفرع»).
2. **كل موظف نشط يصله إشعار داخليّ وبريد عند فتح أيّ تذكرة** ([TicketController.php:131](app/Http/Controllers/TicketController.php:131)) — حجم بريد ملموس في مكتب كثير التذاكر.
3. **المحامي معزول بإسناده** — لا يرى السجلات غير المسنَدة إليه.
4. **التنفيذ المُنشأ من قضية يبدأ بالمرحلة 8 عمداً** — قرار عمل قائم، ليس عطلاً.

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

# البذّار آمن على الإنتاج: يُنشئ الصلاحيات الـ23 وأدوار القوالب الخمسة والحسابات الأساسية
# الأربعة فقط — ولا بيانات تجريبية (DemoDataSeeder صار اختيارياً وصريحاً للتطوير وحده).
# ويقلّم أيضاً الصلاحيات المهجورة («إدارة الفروع» بعد إزالة كيان الفرع).
php artisan db:seed --force
# ⚠️ بعدها فوراً: غيّر كلمة مرور الحسابات الأربعة — تُنشأ بكلمة `password` الموحّدة.

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
REVERB_HOST="DOMAIN"                 # ⚠️ اقرأ التحذير أسفل الكتلة قبل ضبطه
REVERB_PORT=443
REVERB_SCHEME=https
# تُبنى في الواجهة (يجب ضبطها قبل npm run build):
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

> ⚠️ **فخّ `REVERB_HOST` — سبب أعطال البثّ المتكرّرة.** المتغيّر الواحد يخدم **دورين متضادّين**:
>
> | المستهلك | الدور | ما يناسبه |
> |---|---|---|
> | `config/reverb.php` → `servers.reverb.hostname` | اسم المضيف الذي يعلنه الخادم للمتصفّح | **النطاق العامّ** |
> | `config/reverb.php` → `apps[].options.host` و`config/broadcasting.php` → `host` | الوجهة التي **يَنشُر إليها الخادم الخلفي** أحداثه | **حلقة محلّية** (`127.0.0.1:8080`) |
>
> فضبطه على النطاق العامّ وحده يجعل PHP يُرسل أحداثه إلى الإنترنت ثم يعود عبر البروكسي —
> ويفشل صامتاً إن لم يسمح البروكسي بذلك (وكثير لا يسمح). العرَض: الواجهة تتّصل بنجاح ولا
> يصلها شيء.
>
> **الربط ليس المشكلة:** `servers.reverb.host` يقرأ `REVERB_SERVER_HOST` المنفصل بافتراضي
> `0.0.0.0`.
>
> **التشخيص قبل التخمين:** شغّل `php artisan tinker` على الخادم وابثّ حدثاً، وراقب
> `storage/logs/laravel.log` — فشل النشر يظهر استثناء اتصال لا صمتاً. وافحص أن
> `curl -I http://127.0.0.1:8080` يستجيب من الخادم نفسه.

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
AI_COOLDOWN_MINUTES=30

# هويّة المكتب المكانيّة — تُختَم على المواعيد الحضوريّة وتظهر في التقويم وملفات ICS والـPDF
OFFICE_CITY="الرياض"
OFFICE_ADDRESS="الرياض — حي العليا"

# ⚠️ تصفير قاعدة البيانات من لوحة الإدارة — يبقى false في الإنتاج (الأمر محظور أصلاً في production
# ما لم يُفتح هذا المفتاح، مع تأكيد نصّي RESET وتسجيل المنفِّذ)
ALLOW_DB_RESET=false

# توليد PDF (browsershot/كروم) — المهلة بالثواني
PDF_ENGINE=auto
PDF_TIMEOUT=20
# عند فشل اكتشاف المسارات على السيرفر (راجع `php artisan pdf:diagnose`):
# NODE_BINARY=/usr/bin/node
# CHROME_PATH=/root/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome

# تصيير الخادم لإنيرشيا — معطّل عمداً (لا عمليّة SSR دائمة في هذا النشر)
INERTIA_SSR_ENABLED=false
```

> `.env.example` في المستودع محدَّث ويحوي كل المفاتيح أعلاه بقيمها الافتراضيّة — اعتمده مرجعاً.

---

## 6) العمليّات الخلفيّة الدائمة (Supervisor + cron) — ⚠️ لا غنى عنها

### أ) عامل الطابور (بدونه لا بريد اجتماعات ولا توليد ملخّصات)
> ⚠️ **ترتيب إلزاميّ عند تفعيل فصل طوابير الذكاء:** حدِّث أمر العامل أدناه **أوّلاً**
> (الطوابير الثلاثة قبل `default` — الترتيب أولويّة معالجة)، ثم `supervisorctl reread &&
> supervisorctl update`، وبعدها فقط اضبط `AI_SEPARATE_QUEUES=true`. العكس يوقف **كل**
> معالجة الذكاء صامتةً: لا خطأ ولا سجلّ، فقط مهامّ في طوابير لا يستمع لها أحد.
> والمفتاح مُطفأ افتراضياً، فالنشر بلا تغيير آمن.

`/etc/supervisor/conf.d/salasel-worker.conf`:
```ini
[program:salasel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/salasel/artisan queue:work --queue=ai-low-risk,ai-documents,ai-legal-review,default --tries=3 --max-time=3600 --sleep=3
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

    client_max_body_size 12M;  # أكبر مرفقٍ يقبله التطبيق 10MB (مستندات التذاكر والقضايا والتنفيذ) + هامش
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
  الأحداث المطلوب اشتراكها: `meeting.started` · `meeting.ended` · `meeting.participant_joined` · `meeting.participant_left` · `recording.started` · `recording.stopped` · `recording.paused` · `recording.resumed` · `recording.completed` · `meeting.summary_completed`.

> ⚠️ **أوّل ما تحفظ رابط Zoom، تحقّق من نجاح `endpoint.url_validation`.** التطبيق يفحص ختماً زمنيًّا
> بالثواني ضمن نافذة 5 دقائق ([ZoomWebhook.php:38](app/Support/ZoomWebhook.php:38)). لو ردّ الخادم **403**
> بدل قبول التحدّي فالسببان المحتملان: **ساعة الخادم منحرفة** (شغّل `timedatectl` وفعّل NTP)، أو
> **Zoom يرسل الختم بالمللي ثانية** على حسابك — وهي حالة لا تكشفها الاختبارات لأنها كلّها توقّع
> بالثواني. راقب `storage/logs/laravel.log` عند أوّل حدث حقيقيّ.

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
8. **الصلاحيات**: `php artisan tinker --execute='echo Spatie\Permission\Models\Permission::count();'` ⇒ **27** (بعد `PermissionSeeder`)، ولا وجود لصلاحيتَي «إدارة الفروع» و«المخاطبات».
9. **الجلسة المرئيّة**: احجز استشارة مرئيّة → تأكّد أنّ `meet_id` غير فارغ في `consults`، وأنّ `storage/logs/laravel.log` **خالٍ** من `Zoom: تعذّر إنشاء اجتماع الاستشارة` — وجودها يعني أنّ الجلسة حُجزت بلا اجتماع Zoom وتحتاج معالجة يدويّة.
10. **إطلاق روابط الجلسات**: بعد دقيقة من اقتراب موعد جلسة مرئيّة، يجب أن يمتلئ `link_released_at`. إن بقي فارغاً فالمجدول لا يعمل (راجع §6-ج) — **ولن يُفعَّل زرّ الدخول أبداً**.
11. **PDF**: `php artisan pdf:diagnose` — يؤكّد مسار كروم/Node وبيانات المكتب (من شاشة الإعدادات، والعنوان والمدينة يرثان `config/office.php` حتى تُضبط).
12. **الأصول**: افتح أي صفحة وتأكّد أنّ `public/build/manifest.json` موجود وأنّ لا `public/hot` متبقٍّ (وجوده يجعل Laravel يتجاهل البناء ⇒ صفحات بيضاء).

---

## 11) إجراء التحديث (Deploy لاحق)

**استعمل السكربت — لا الخطوات اليدويّة.** [`deploy.sh`](deploy.sh) في جذر المستودع يؤدّي التسلسل كاملاً وبحمايات لا توفّرها الأوامر اليدويّة:

```bash
cd /var/www/law && bash deploy.sh
```

يفعل بالترتيب: **نسخة احتياطيّة `mysqldump` أولاً** (ويتوقّف إن غاب `mysqldump`) → `down` → `git pull origin main` → `composer install --no-dev` → `npm ci` + `build` → **إيقاف العمّال قبل الترحيل** (عامل بكود قديم يضرب أعمدة مُرحَّلة ⇒ `SQLSTATE 42S22`) → `migrate --force` → الكاشات الأربع → تشغيل العمّال → `up` → **فحص صحّة `/up`** → **فحص عامل الطابور**.

وفيه `trap` يُعيد الموقع من وضع الصيانة مهما أخفق — الخطوة التي كان نسيانها يترك الموقع مُقفلاً.

⚠️ كتلة `supervisorctl` داخله مشروطة بـ`$EUID -eq 0`؛ بمستخدم غير جذر تتخطّى **بصمت**، فاقرأ رسالة فحص عامل الطابور في آخر المخرجات بعناية:
- `✅ عامل الطابور يعمل` ⇒ سليم.
- `⚠️ QUEUE_CONNECTION=sync` ⇒ خطأ إعداد: كل مهمّة تُنفَّذ داخل طلب المستخدم، وأرشفة تسجيل Zoom تعني **504**.
- `⚠️ لا عامل طابور يعمل` ⇒ شغّله عبر Supervisor (§6-أ).

> **قاعدة ذهبيّة**: عامل الطابور يحمّل الكود في الذاكرة — **أعِد تشغيله بعد كل نشر** وإلا ينفّذ كوداً قديماً. السكربت يفعلها؛ إن نشرت يدويًّا فلا تنسَها.

> **المصادر القانونيّة تُزامَن تلقائياً** بعد `migrate`: `php artisan ai:sync-sources` يُدخل ملفّات `database/legal-sources/*.json` (الجديد «مسودة» يعتمده محامٍ من `/admin/legal-sources`، والمعتمد لا يُخفَّض)، وملفٌّ معطوب يوقف النشر قبل أيّ كتابة.

> **السكربت لا يشغّل أيّ بذّار.** بعد ترحيل يضيف/يحذف صلاحيات، شغّل يدويًّا:
> `php artisan db:seed --class=PermissionSeeder --force`

---

## 12) النسخ الاحتياطيّ والمراقبة (موصى)
- نسخ MySQL يوميّاً (`mysqldump`) + `storage/app` (المستندات المرفوعة).
- راقب: `storage/logs/laravel.log`, `worker.log`, `reverb.log`, و`queue:failed`.
- تنبيهات على فشل الطابور وتوقّف Reverb/العامل (Supervisor autorestart يغطّي الانهيار).

---

## 13) بنود مفتوحة تُراقَب بعد النشر

من تدقيق تكامل Zoom (‏2026-08-21) — لا تمنع النشر، لكن راقبها:

| البند | المؤشّر |
|---|---|
| وحدة ختم الـwebhook الزمنيّ | ‏403 على كل حدث Zoom بما فيه تحدّي التحقّق ⇒ راجع §8 |
| طول `start_url` مقابل عمود `varchar(1000)` | رابط مضيف مبتور أو خطأ إدراج عند أوّل اجتماع Zoom حقيقيّ |
| نافذة `ReleaseMeetingLinks` ‏`[−60د، +5د]` | تعطّل المجدول ساعةً يترك `link_released_at` فارغاً **للأبد** لتلك الجلسات — راقب §10-10 |
| لا سياسة احتفاظ لـ`recordings/` و`transcripts/` | نموّ بلا حدّ في `storage/app/private/` — راقب المساحة |
| إغلاق/حذف غرف Zoom صار مهامّ طابور تعيد المحاولة (`EndZoomMeetingJob`/`DropZoomMeetingJob`) | راقب `queue:failed` — مهمّةٌ فاشلة نهائياً تعني غرفةً باقية في حساب Zoom |
| `ZOOM_FALLBACK_BASE` | إعداد ميت (لا قارئ له في الكود) — لا تعتمد عليه |
