# وثيقة التسليم التقنيّة الشاملة — منصّة «سلاسل بابل» لإدارة مكتب المحاماة والقضايا
### Complete Technical Handover & Context Document

> **الغرض:** مرجع أوحد وشامل لنقل تطوير المشروع إلى وكيل/بيئة جديدة. يغطّي المعماريّة،
> نموذج البيانات، طبقة HTTP، منطق الأعمال، التكاملات الخارجيّة، الواجهة، التشغيل،
> القضايا المعروفة، واتفاقيّات الكود. مكتوب ليُقرأ بلا الحاجة لسياق المحادثات السابقة.
>
> **حالة الوثيقة:** ✅ مكتملة — كل الأقسام مُجمّعة من قراءة فعليّة للكود (74 هجرة، 24 نموذجاً، كامل طبقة
> HTTP والخدمات والواجهة). مبنيّة على مسح آليّ متوازٍ + تدقيق يدويّ للملفّات الحرجة.
>
> **خطة تطوير الذكاء الاصطناعي:** وثائقها في `docs/ai-plan/` — ابدأ من
> [`00-START-HERE.md`](docs/ai-plan/00-START-HERE.md). مواد تخطيط مرجعيّة (خطة، خارطة مراحل،
> معماريّة مستهدفة، تدقيق الـAI الحاليّ، خطط اختبار) لا تُنفَّذ إلا بطلب صريح، مرحلةً واحدة في كل مرّة.

---

## 0. ملخّص تنفيذيّ (Executive Summary)

منصّة ويب متكاملة لإدارة مكتب محاماة سعوديّ (العلامة: **سلاسل بابل** / نطاق `salasel.sa`).
تخدم أربعة أدوار (**عميل، موظف، محامٍ/مستشار، إدارة عليا**) عبر رحلات عمل مترابطة:

- **التذاكر (الاستشارات الأوليّة):** يفتح العميل تذكرة → فرز آليّ بالذكاء الاصطناعي →
  طلب مستندات → إحالة لمحامٍ → رأي قانونيّ → حجز استشارة مدفوعة → جلسة → نتيجة → تحويل لقضية.
- **الاستشارات (Consults):** دورة حجز (تسعير → دفع عبر ميسّر → موعد → جلسة مرئيّة Zoom → ملخّص).
- **القضايا (Cases):** تُحوَّل من التذاكر؛ أتعاب، جلسات محكمة، لوائح، أحكام.
- **التنفيذ (Executions):** طلبات تنفيذ مرتبطة بالقضايا.
- **الاجتماعات (Meetings/MeetRequests):** جدولة، روابط Zoom، تذكير بالبريد، ملخّصات.
- **المهام، الفوترة والمحاسبة، الأرشيف، التقارير، وإدارة المستخدمين.**

**الطابع المعماريّ:** طبقيّ (Controllers رفيعة → Support/Services للمنطق → Models)، مع
حرص شديد على: الأمان الإنتاجيّ، عزل الأدوار، البثّ اللحظيّ (Reverb)، التعطّل الآمن
للتكاملات (fallback بلا مفاتيح)، ومطابقة تصميم مرجعيّ HTML. حالات دورة الحياة كلّها نصوص
عربيّة (مثل `'قيد التحليل'`, `'موعد مؤكد'`) تُدار عبر صفوف «رحلة» مركزيّة (`*Journey`).

---

## 1. البيئة والتشغيل (Environment & Runtime)

| العنصر | القيمة |
|---|---|
| **مجلّد الكود** | `C:\Users\a\Herd\low-laravel-rectjs` (يعمل عبر Laravel Herd على ويندوز) |
| **مجلّد العمل/الذاكرة** | `D:\project-customers\lawfirm` (خطط وذاكرة فقط — ليس فيه كود) |
| **PHP للأوامر** | `C:/Users/a/.config/herd/bin/php83/php.exe` — **إلزاميّ**؛ الـ`php` العام (8.2) يفشل |
| **قاعدة البيانات (تطوير)** | MySQL، قاعدة اسمها `lawyer` (الافتراضيّ في `.env.example` هو sqlite — بيئة التطوير تستخدم MySQL) |
| **الخادم المحلّي** | المنفذ 8000 (`php artisan serve`) |
| **النفق العام (ngrok)** | `ngrok.exe http 8000` → عنوان مثل `https://…ngrok-free.dev` (يتغيّر كل تشغيل)؛ لوحة ngrok `http://127.0.0.1:4040` |
| **المنطقة الزمنيّة** | `Asia/Riyadh` |

### أوامر أساسيّة
```bash
# الاختبارات (استخدم php83 صراحةً)
"C:/Users/a/.config/herd/bin/php83/php.exe" -d max_execution_time=0 artisan test

# التنسيق والفحص
php vendor/bin/pint <files>          # تنسيق PHP (Laravel Pint)
npx tsc --noEmit                     # فحص أنواع TypeScript
npm run build                        # بناء الواجهة (Vite)

# التطوير الكامل (خادم + عامل صفّ + Vite) — من composer.json script "dev"
npx concurrently "php artisan serve" "php artisan queue:listen --tries=1" "npm run dev"
```

### ⚠️ متطلّبات تشغيليّة حرجة (بدونها ميزات تتعطّل صمتاً)
- **عامل الصفّ (Queue Worker):** `php artisan queue:work` (أو `queue:listen`) — `QUEUE_CONNECTION=database`.
  بدونه **لا تعمل**: ملخّصات الذكاء الاصطناعي، إيميلات الاجتماعات المُطابَرة (queued mailables)،
  ترقية ملخّص التذكرة، ملخّصات Zoom، وغيرها من الوظائف الخلفيّة.
- **المجدول (Cron/Scheduler):** `php artisan schedule:run` كل دقيقة — بدونه لا تعمل:
  تذكيرات الاجتماعات، إطلاق روابط الاجتماعات، سحب ملخّصات Zoom الدوريّة.
- **البثّ اللحظيّ:** للإنتاج `BROADCAST_CONNECTION=reverb` + تشغيل `php artisan reverb:start`؛
  في التطوير الافتراضيّ `log` (لا بثّ فعليّ).

---

## 2. حزمة التقنيات (Tech Stack) — من `composer.json` و`package.json`

### الخلفيّة (PHP)
- **PHP** `^8.3` · **Laravel Framework** `^13.7`
- **Inertia Laravel** `^3.0` (جسر الخلفيّة↔React، بلا API منفصل)
- **Laravel Reverb** (WebSockets للبثّ اللحظي) · **Laravel Tinker** · **Laravel Wayfinder** `^0.1.14` (توليد روابط/أنواع للواجهة)
- **resend/resend-php** (البريد) · **spatie/laravel-permission** (الأدوار والصلاحيات)
- **dev:** Pest 4 (اختبارات)، Larastan/PHPStan، Pint، Laravel Boost، Pail، Mockery، Faker

### الواجهة (JS/TS)
- **React** `^19.2` + **react-dom** · **@inertiajs/react** `^3.0` · **TypeScript** `^5.7`
- **Vite** `^8.0` + `laravel-vite-plugin` + `@vitejs/plugin-react` + `@laravel/vite-plugin-wayfinder` + `babel-plugin-react-compiler`
- **Tailwind CSS** `^4.0` (`@tailwindcss/vite`) · **lucide-react** (أيقونات) · **clsx** + **tailwind-merge**
- **laravel-echo** `^2.3` + **pusher-js** `^8.5` (عميل Reverb) · **axios**
- **الجودة:** ESLint 9 + Prettier + typescript-eslint

### أوامر npm
`dev` (vite) · `build` · `build:ssr` · `lint`/`lint:check` · `format`/`format:check` · `types:check` (tsc --noEmit)

---

## 3. الإعداد من الصفر (Setup) ومفاتيح البيئة

```bash
composer install
cp .env.example .env
php artisan key:generate
# اضبط قاعدة البيانات (MySQL: DB_CONNECTION=mysql, DB_DATABASE=lawyer ...) أو اترك sqlite
php artisan migrate --seed        # الهجرات + البذور (حسابات افتراضيّة)
npm install && npm run build
```

### مفاتيح `.env` المهمّة (كلّها في `config/services.php`)
| المفتاح | الغرض | سلوك الغياب |
|---|---|---|
| `TAQNYAT_API_KEY` / `TAQNYAT_SENDER` | رمز OTP عبر واجهة Verify الرسميّة لتقنيات | تُمنع المصادقة («الخدمة غير مهيّأة») — لا محاكاة |
| `AUTH_DEV_OTP` | رمز OTP ثابت للتطوير (مثال `1234`) | يعمل في **غير الإنتاج فقط**؛ فارغ = معطّل |
| `RESEND_API_KEY` + `MAIL_MAILER=resend` | إرسال البريد الفعليّ | `MAIL_MAILER=log` يكتب البريد في اللوق (تطوير) |
| `GEMINI_API_KEY` (`GEMINI_MODEL`) | المساعد القانونيّ الذكيّ (الأساس) | يسقط لـ GLM ثم للنائب القالبيّ الحتميّ |
| `GLM_API_KEY` (z.ai) | احتياطيّ AI متوافق مع OpenAI | — |
| `AI_TICKET_AGENT` | تفعيل الوكيل التشغيليّ للتذاكر (`services.ai_agent.enabled`) | افتراضيّ `true`؛ `false` = المسار اليدويّ القديم |
| `AI_COOLDOWN_MINUTES` | تهدئة مزوّد الـAI بعد نفاد الحصّة (قاطع دائرة) | افتراضيّ 30 |
| `MOYASAR_SECRET_KEY` / `_PUBLISHABLE_KEY` / `_WEBHOOK_SECRET` | بوّابة الدفع ميسّر | بلا مفاتيح يبقى الدفع محاكى/ممنوع |
| `ZOOM_ACCOUNT_ID`/`_CLIENT_ID`/`_CLIENT_SECRET` + `_SDK_KEY`/`_SDK_SECRET` + `_WEBHOOK_SECRET` | اجتماعات مرئيّة (S2S OAuth + Meeting SDK) | رابط احتياطيّ `ZOOM_FALLBACK_BASE` |

**ملاحظة أمنيّة:** `taqnyat.api_key`, `moyasar.secret_key` خادميّة فقط — لا تُسرَّب في props/الواجهة/اللوقات.

---

## 4. التكاملات الخارجيّة (External Integrations) — تفصيليّ

> كلّ تكامل مغلّف في صفّ خدمة/دعم مخصّص، ويتعطّل بأمان (fallback) عند غياب المفاتيح.
> التفاصيل الكاملة للصفوف في القسم 10.

1. **تقنيات (Taqnyat) — OTP:** `app/Services/TaqnyatVerifyService.php` يغلّف
   `POST https://api.taqnyat.sa/verify.php` (تقنيات تُولّد الرمز وتخزّنه وتتحقّق منه — لا توليد محلّي).
   `generate` (رمز 5/7) و`check` (رمز 10). يُغلَّف أعلاه بـ `app/Support/OtpService.php`
   (`requestId=uuid` + `devBypass()` للتطوير). راجع القسم 6.
2. **Resend — البريد:** موصل `resend` في `config/mail.php`، مغلّف في `app/Services/MailService.php`
   (`send(User|string|array, Mailable): bool` نقطة واحدة، try/catch + Log). قالب RTL موحّد في
   `resources/views/emails/layout.blade.php`. راجع القسم 10 لقائمة الـMailables.
3. **ميسّر (Moyasar) — الدفع:** `app/Services/MoyasarService.php` + `app/Support/MoyasarWebhook.php`
   + `app/Support/PaymentReconciler.php`. الفواتير المستضافة؛ التأكيد عبر webhook/callback (مصدر الحقيقة،
   لا يُوثَق بمعطيات الـURL). مسار الويب‑هوك `webhooks/moyasar` مُستثنى من CSRF (محميّ بالسرّ).
4. **الذكاء الاصطناعي — `app/Services/LegalAiService.php`:** الأساس Gemini ← احتياطيّ GLM ←
   نائب قالبيّ حتميّ (يعمل بلا مفاتيح للاختبارات). دوال: `reply`, `summarize`, `greet`,
   `triageTicket`, `analyzeDocument`, `classifyCase`, `chooseLawyer`, `analyzeConsult`,
   `fallbackSummary`, `isConfigured`, `available` (قاطع الدائرة). الثوابت
   `AGENT_NAME='خدمة العملاء'`, `AGENT_ROLE='الدعم الفني'`.
5. **Zoom — الاجتماعات المرئيّة:** `ZoomController`, `ZoomWebhookController`, وصفوف الدعم
   `ZoomWebhook`, `ZoomRecording`, `ZoomSummaryText` + وظائف `ProcessZoom*Job`. بلا مفاتيح
   يُستخدم `ZOOM_FALLBACK_BASE`. مسار الويب‑هوك `webhooks/zoom` مُستثنى من CSRF (محميّ بالتوقيع).
6. **Reverb — البثّ اللحظي:** أحداث `*Broadcast` عبر `app/Support/Live.php`؛ القنوات في
   `routes/channels.php` + `app/Support/ChannelAccess.php`. راجع القسم 13.

---

## 5. البنية التحتيّة للتطبيق (`bootstrap/app.php`)

- **Middleware عام (web):** `HandleInertiaRequests` (مشاركة props عالميّة) + `AddLinkHeadersForPreloadedAssets`.
- **Aliases:** `role` → `EnsureRole`، `permission` → `EnsurePermission`، `active` → `EnsureActive`.
- **CSRF مُستثنى:** `webhooks/zoom`, `webhooks/moyasar` (محميّة بتوقيع/سرّ).
- **Trust Proxies:** `*` مع كل ترويسات `X-Forwarded-*` — ضروريّ خلف ngrok/بروكسي لبناء روابط https
  صحيحة (رابط عودة ميسّر) واكتشاف البروتوكول الحقيقيّ.
- **التوجيه:** `routes/web.php` + `routes/console.php` + `routes/channels.php` + فحص صحّة `/up`.
- **الاستثناءات:** JSON لمسارات `api/*`.

---

## 6. المصادقة والهويّة (Authentication & Identity) — بلا كلمة مرور

**المقاربة:** دخول بلا كلمة مرور لكل الأدوار = **رقم الهويّة (10 أرقام) + رمز SMS** عبر تقنيات
(Verify API). لا بريد/كلمة مرور. مطابِق لتصميم `.lgn` المرجعيّ.

- **الصفوف:** `TaqnyatVerifyService` (نداء verify.php) → `app/Support/OtpService.php` (غلاف: `generate`/`check`
  + `devBypass()` = `app()->environment('local','testing') && filled(config('services.auth_dev_otp'))`)
  → `app/Http/Controllers/Auth/*` (متحكّم المصادقة). `app/Support/Phone.php` (تنسيق دوليّ/تقنيع).
- **تعدّد الحسابات المرتبط بالهويّة:** شخص واحد بأدوار مختلفة = **نفس الهويّة + نفس الجوال، بريد مختلف
  لكل حساب، حساب واحد لكل دور**. القيود: `unique(national_id, role)` و`unique(phone, role)` (البريد فريد عالميّاً).
  - الدخول: `requestOtp` يجمع كل حسابات الهويّة → رمز واحد للجوال المشترك؛ `verifyOtp` يحلّ الحسابات
    بـ **(national_id + phone)** → حساب واحد=دخول مباشر / أكثر=شاشة `account_choice`. ثمّ `chooseAccount`
    و`switchAccount` (يشترطان تطابق الجوال). `HandleInertiaRequests` يشارك `auth.user.accounts` (مُصفّى بالجوال).
- **حدود المعدّل:** `otp-request` (3/د بمفتاح هويّة/جوال+IP)، `otp-verify` (5/د بمفتاح IP — **لا** session id).
- **تسجيل العميل بخطوتين:** جوال (تقنيات) ثم بريد (`app/Support/EmailOtpService.php`: رمز مُجزّأ بالجلسة،
  انتهاء 10د، MAX_ATTEMPTS=5، MAX_ISSUES=4). التسجيل الذاتيّ لدور `client` فقط ويُمنع بهويّة طاقم.
- **⚠️ حاجز تفعيل معروف:** يلزم `TAQNYAT_API_KEY` صالح + اسم مُرسِل معتمد لإرسال SMS فعليّ؛ الكود صحيح
  لكن مفتاح التطوير الحاليّ غير صالح، لذا يُعتمد `AUTH_DEV_OTP` للتحقّق الحيّ.

---

## 7. الأدوار والصلاحيات وعزل الرؤية (Roles, Permissions, Visibility)

- **الأدوار:** `app/Enums/Role.php` (عميل/موظف/محامٍ/إدارة) + حزمة `spatie/laravel-permission`.
- **الحراسة:** middleware `role` (`EnsureRole`)، `permission` (`EnsurePermission`)، `active` (`EnsureActive`
  — يمنع الموقوفين). Trait `ScopedToLawyer` في المتحكّمات لعزل رؤية المحامي.
- **عزل الرؤية:** المحامي يرى المسنَد إليه فقط؛ **الموظف والإدارة يريان كل سجلّات المكتب**؛ العميل ملكه فقط.
  قاعدة `app/Rules/ActiveLawyer.php` تحرس الإسناد. تفاصيل كل مسار في القسم 9.
  > كيان «الفرع» أُزيل بالكامل (2026-08-20 — الالتزام `640e3b6`). راجع 9.7.
- **الانتحال (Impersonation):** `app/Http/Controllers/ImpersonationController.php` (الإدارة تعاين حساب مستخدم).
  > **دَيْن معروف:** `switchAccount` لا يمسح `impersonator_id` والمُبدّل يظهر أثناء المعاينة (راجع القسم 15).

---

## 8. نموذج البيانات (Data Model) — 74 هجرة · 24 نموذجاً

**اصطلاحات:** كل جدول له `id` (bigIncrements) و`timestamps` ما لم يُذكر خلافه. FK بسلوك حذف
`cascade` (يُحذف مع المرجع) أو `nullOnDelete` (يُصفَّر). **الأعمدة النصيّة للمحامي (`assigned_lawyer`)
للعرض فقط — المصدر الموثوق دائماً `assigned_lawyer_id` (FK).** **عمود `branch` أُسقِط من كل الجداول**
(مهاجرة `2026_08_20_000002_drop_branch_scoping`). المبالغ: `invoices.amount` صحيح بالريال؛ **`payments.amount` بالهللة (×100)**.

### 8.1 المستخدمون والأدوار (المجال أ)
- **`users`** (تراكم عدّة هجرات): `name`, `email` (**unique عالميّاً**), `role`(20، cast→Enum Role),
  `avatar_initials`, `title`, `phone`, `phone_verified_at`, `status`(20، active/suspended),
  `department`, `distribution_mode`(12، auto/manual — محرّك التوزيع), `job_title`, `pay_type`
  (salary/pct/both/session), `salary`, `pay_pct`, `session_fee`, **`national_id`(20)**, `join_date`,
  `work_start/end`, `email_verified_at`, `password`(hashed), `remember_token`.
  - **تفرّد مركّب لتعدّد الحسابات:** `unique(national_id, role)` و`unique(phone, role)` — شخص واحد
    (نفس الهويّة/الجوال) = حساب واحد لكل دور. فهارس: `(role,status)`, `distribution_mode`.
  - نموذج `User`: fillable/hidden عبر PHP Attributes؛ traits `HasRoles`(spatie)+`Notifiable`؛ علاقات
    `tickets/executions/cases/consults/assignedTickets`؛ دوال `isAdmin/isActive/payLabel/staffCard`.
- ~~**`branches`**~~: **الجدول محذوف** — المكتب واحد. عنوان المكتب صار في `config/office.php`
  (`OFFICE_CITY`/`OFFICE_ADDRESS`).
- **`settings`**: PK=`key`(string)، `value`. دوال static `get/put/consultPrices` (office=600, video=450,
  phone=350, vat=15% افتراضاً).
- **جداول spatie:** `permissions, roles, model_has_permissions, model_has_roles, role_has_permissions` (قياسيّة).
- **بنية تحتيّة:** `users(sessions/password_reset_tokens/cache/jobs/job_batches/failed_jobs)`.
  ⚠️ جدول `otp_codes` أُنشئ ثم أُسقط — **ليس جزءاً من المخطّط النهائيّ** (التحقّق عبر Verify API + الجلسة).

### 8.2 التذاكر (المجال ب)
- **`tickets`** (مفتاح مسار=`number`): `user_id`(FK cascade), `number`(unique),
  `type`, `department`, `assigned_lawyer`(نصّي), `assigned_lawyer_id`(FK nullOnDelete),
  `status`(index، default `قيد التحليل`), `tone`(16), `attachments`, `last_message`, `date_label`.
  علاقات النموذج: `user/messages/summary(1:1)/documents/assignedLawyer/legalCase(1:1)/consults`؛ دوال
  `toCard/toEmployeeCard/maskClient`.
- **`ticket_messages`**: `ticket_id`(cascade), `who`(16: client/ai/staff/lawyer/admin/system/note),
  `name`, `role`, `body`, `time_label`.
- **`ticket_summaries`** (الملخّص الرباعيّ): `ticket_id`, `lawyer_id`(FK nullOnDelete), `case_summary`,
  `attachments_summary`, `facts`, `key_points`, `ai_generated`(bool), `result`, `result_status`(20:
  none/approved/rejected — `pending_lawyer` و`pending_admin` حُذفتا 2026-09-19 مع مسار اعتماد النتيجة القديم
  كلّه؛ النتيجة تُعتمد اليوم مع ملخّص الجلسة), `status`(24، default `awaiting_lawyer`), `approved_at`.
- **`ticket_documents`**: `ticket_id`, `name`, `path`, `mime`, `size`, `status`(40، default `قيد الفحص`),
  `doc_type`, `summary`, `reason`, `summary_approved`(bool).

### 8.3 القضايا (المجال ج)
- **`cases`** (نموذج `LegalCase`، `$table='cases'`، مفتاح=`number`): `user_id`,
  `ticket_id`(nullOnDelete، القضية محوّلة من تذكرة), `number`(unique), `type`, `assigned_lawyer`(_id),
  `department`, `status`(default `منظورة`), `tone`, نصوص عرض
  (`update_text/next_hearing/invoice_text/paid_text`), `fee`, `lawyer_fee`, `lawyer_pct`, `fee_status`(20:
  none/pending_payment/paid), `pay_plan`(full/install), `installments_total/paid`, `pleading_status`(20:
  none/pending_lawyer/approved), `ruling`(منطوق الحكم). علاقات: `user/ticket/assignedLawyer/messages/
  hearings/execution(1:1)`.
- **`case_messages`**: `case_id`(cascade), `who/name/role/body/time_label`. **`booted()` يبثّ
  `CaseMessageBroadcast` تلقائيّاً عند الإنشاء.**
- **`case_hearings`**: `case_id`, `title`, `day`, `time`, `court`, `status`(20: مجدولة/منعقدة/مؤجلة), `outcome`.

### 8.4 الاستشارات (المجال د)
- **`consults`** (تراكم كبير): `user_id`, `ticket_id`(nullOnDelete), `appointment_id`(nullOnDelete),
  `ref`(unique CN-…), `subject`, `type`, `priority`, `channel`(مرئية/حضورية/هاتفية), `lawyer`(نصّي),
  `assigned_lawyer_id`(index مع starts_at), `specialty`, `employee`, `day/time/when_label`(**nullable**
  — الموعد يُختار بعد السداد), `phone`, **`starts_at`**(مصدر حساب التعارض), `duration_min`(60),
  حقول Zoom (`meet_id, meet_link(500), host_link(1000), meet_password, link_released_at, join_time,
  leave_time, duration_sec, transcript(longText), transcript_path, recording_url, zoom_summary_at`),
  `status`(index), `session`(index: بانتظار الجلسة/جلسة جارية/منتهية), `session_notes`, `summary`,
  `decisions`(json), `suggested_tasks`(json — اقتراحات لا التزامات), `tasks_created`(bool),
  دورة الحجز (`price/vat/total, priced_at, paid_at`), تحليل AI
  (`ai_done, ai_class, ai_summary, ai_lawyer, ai_source, missing(json), audit(json)`).
  - **حوكمة الملخّص** (أعمدة أُضيفت في 2026-09): `summary_approved_at/_by` (بوّابة وصوله العميل)،
    `summary_ai_original` (مخرج النموذج مجمَّداً عند أوّل تحرير)، `summary_edited_at/_by`،
    **`summary_ai_source`** (مصدر **الملخّص** — غير `ai_source` الذي يصف تحليل ما قبل الجلسة؛
    خلطُهما كان يُعيد وسم تحليلٍ نجح بأنه فاشل)، `session_finalized_at` (حارس تكرار الإشعار)،
    `zoom_summary` (مادّة Zoom مفصولة عن نصّ العميل).
  - ثابتا النموذج: `PRE_SESSION_STATUSES` (ثلاث حالات ما قبل الجلسة) و**`STATUSES`**
    (كتالوج الحالات الأربع عشرة — كلّ قيمةٍ فيه يكتبها مسارٌ حيّ، ويحرسه
    `ConsultStatusCatalogueTest`)؛ ودوالّ `canJoin/isStartable/isMissed/joinLink/
    toClientCard/toCard/summaryApproved/logAudit`.

### 8.5 المواعيد والاجتماعات (المجال هـ)
- **`appointments`**: `user_id`, `ticket_id`(nullOnDelete), `ext_id`, `type`, `lawyer(_id)`, `day`, `time`,
  `starts_at`, `duration_min`, **`place`**(كان `branch` — أُعيدت تسميته), `status`(default `مؤكد`), `when_kind`(up/past). علاقة
  `consult(1:1 عبر appointment_id)`.
- **`meetings`**: `user_id`(**nullable** — داخليّ=بلا عميل), `ref`(unique), `title`, `type`, `client_name`,
  `when_label`, **`starts_at`** + **`reminder_sent_at`**(idempotent للتذكير), أعلام
  (`is_up/has_link/has_minutes/has_summary/sum_approved/tasks_created`), `status`(قادم/جارٍ/منتهٍ/مؤجل/ملغى),
  `priority`, `conf`(سري/عادي), `attend`, `approve`(default `بانتظار اعتماد الإدارة`),
  `before/during/after_items`(json), `summary`, `decisions`(json), `minutes`(المحضر), `participants`,
  `case_ref`, حقول Zoom (كاملة)، `created_by`, `assigned_lawyer_id`(index).
  دوال: `isUpcoming/joinLink/toCard`(المحضر يظهر بعد اعتماد الإدارة فقط)/`toFullCard`.
- **`meet_requests`**: `user_id`, `meeting_id`(nullOnDelete), `ref`(unique MR-…), `service`, `type`,
  `case_ref`, `day`, `time`, `sent_by`, `stage`(0..3: مُرسلة/مؤكّدة/منفّذة/معتمدة), حقول Zoom.

### 8.6 التنفيذ (المجال و)
- **`executions`** (نموذج `Execution`، مفتاح=`number`، تدفّق 10 مراحل): `user_id`,
  `case_id`(nullOnDelete، منشأ من قضية محكومة), `number`(unique), `subject`, `sanad`(السند التنفيذيّ),
  `defendant`(المنفَّذ ضده), `amount`(قيمة المطالبة), `notes`, `docs`(json), `assigned_lawyer(_id)`,
  `court`, `status`(index، default `جارٍ`), `stage`(0..9)، `tone`, `last_action`, تحليل AI
  (`ai_done, ai_summary, ai_missing(json), ai_procedures(json)`), `decision`, `fee/vat`, `duration`,
  `pay_method`, `fee_approved`, `offer_status`, `invoice_no`, `paid(+paid_at)`, `exec_no`. دوال
  `toCard/toFlowCard(masked)`.
- **`execution_messages`** (`booted()` يبثّ `ExecMessageBroadcast`), **`execution_procedures`** (title/type:
  حجز/تحصيل/إخطار/إجراء، status: مجدول/منفّذ/مؤجل), **`execution_documents`** (label/status/path/mime/size).

### 8.7 الفواتير والمدفوعات والمهام (المجالان ح/ط)
- **`invoices`** (مفتاح=`number`): `user_id`, `case_id`, `consult_id`, `exec_id` (كلّها nullOnDelete —
  الفاتورة تخصّ أحد المجالات), `number`(unique), `description`, `amount`(صحيح), `status`(default `مستحقة`),
  `tone`, `due_label`, `paid`(bool), `gateway_ref`, `gateway_payment_id`, `proof_path/proof_uploaded_at`
  (إثبات تحويل يدويّ). علاقات: `user/consult/payments`.
- **`payments`** (دفتر ميسّر): `invoice_id`(nullOnDelete), `gateway`(default moyasar), `gateway_invoice_id`,
  **`gateway_payment_id`(unique — idempotency)**, `status`, `amount`(**بالهللة**), `currency`(SAR),
  `source_channel`(webhook/callback/backfill), `raw`(json), `reconciled_at`.
- **`documents`**: `user_id`, `name`, `meta`, `direction`(out/up), `path/mime/size`.
- **`user_notifications`**: `user_id`, `icon`, `tone`(t-blue/t-green/t-cyan/t-amber), `body`, `is_read`.
- **`tasks`**: `assigned_to`(FK cascade), `title`, `ref`, `due`(نصّ), `status`(20: مفتوحة/قيد العمل/منجزة), `tone`.

### 8.8 خريطة العلاقات (سلاسل التحويل)
```
users (client/employee/lawyer/admin)
 ├─< tickets ─< messages/documents/summary(1:1)
 │      ├─< consults ─1:1─ invoice ;  └─ cases(1:1 عبر ticket)
 ├─< cases ─< case_messages/case_hearings ; ─< invoices ; ─1:1─ execution
 │      └─< executions ─< exec_messages/procedures/documents ; ─< invoices(exec_id)
 ├─< appointments ─1:1─ consults ;  ├─< meetings ─< meet_requests
 ├─< invoices ─< payments ;  └─< documents / user_notifications / tasks
 (assigned_lawyer_id على tickets/cases/executions/consults/meetings)
سلسلة التحويل: ticket → consult / case → execution → invoices → payments
```
> ملاحظة: `consults.assigned_lawyer_id`, `appointments.lawyer_id`, `meetings.assigned_lawyer_id` أعمدة
> بلا قيد FK صريح (توافقاً مع SQLite بالاختبارات) لكن علاقاتها معرّفة في النماذج.

### 8.9 `app/Enums/Role.php`
enum نصّيّ: `Client/Employee/Lawyer/Admin`؛ دوال `label()` (العميل/الموظف/المحامي/الإدارة)، `home()`
(المسار الرئيسيّ لكل دور)، `prefix()` (بادئة الحماية).

---

## 9. طبقة HTTP (Routes & Controllers) — خريطة كاملة حسب الدور

**ملاحظات معماريّة:** كل المسارات في `routes/web.php` (لا API). **لا Form Requests** (`app/Http/Requests`
غير موجود) — كل التحقّق مضمّن في المتحكّمات عبر `$request->validate()` برسائل عربيّة. سياسة واحدة
(`DocumentPolicy`). المتحكّمات: 14 بالجذر + Admin(13) + Employee(4) + Lawyer(5) + Staff(3).

### 9.1 طبقات الحراسة (من الأعلى للأسفل)
1. **`active` (`EnsureActive`):** أيّ `status=suspended` يُخرَج فوراً (logout+invalidate+regenerate).
2. **`role:<role>` (`EnsureRole`):** يقارن `$user->role` (enum) — **الإدارة تمرّ لأي لوحة**؛ عدم التطابق →
   إعادة توجيه لـ`home()`.
3. **`permission:<perm>` (`EnsurePermission`):** deny-by-default فوق الدور (`$user->can(...)`) — **الإدارة
   تتجاوز الكلّ** عبر `Gate::before` (`AppServiceProvider.php`). يُطبَّق على كل مسار موظف/محامي حسّاس.
4. **حراسة داخل المتحكّم:** `abort_if`/`abort_unless`/Traits/Policy (خطّ الدفاع الأخير).
- **تحديد المعدّل** (`AppServiceProvider::boot`): `otp-request` (3/د بمفتاح هويّة/جوال+IP)، `otp-verify` (5/د بـ IP).

### 9.2 المسارات العامّة والمصادقة
- **عام (بلا مصادقة):** `GET /` (welcome, `home`) · `POST /webhooks/zoom` (`ZoomWebhookController@handle`، CSRF
  مُستثنى، HMAC) · `POST /webhooks/moyasar` (`MoyasarWebhookController@handle`، CSRF مُستثنى، secret_token).
- **المصادقة (`guest`) — `AuthController`:** `GET /login` (`show`) · `POST /auth/otp/request` (`requestOtp`،
  throttle otp-request) · `/auth/otp/verify` (`verifyOtp`) · `/auth/otp/resend` (`resend`) · `/auth/register`
  (`register`) · `/auth/register/verify-phone` · `/auth/register/verify-email` · `/auth/choose-account`
  (`chooseAccount`) · `POST /logout` (`auth`) · `POST /auth/switch-account` (`switchAccount`، `auth,active`).
- **الحساب (`auth,active` لأي مستخدم):** `/notifications`(+`read-all`) · `/profile`(+`profile.update`,
  `profile.password`) · `POST /impersonate/leave` (`ImpersonationController@leave`) · `POST /zoom/sdk-signature`
  (`ZoomController@sdkSignature` — `abort_unless(ChannelAccess::ownerOrStaff)` يسدّ IDOR).

### 9.3 منصّة العميل (`auth, active, role:client`)
- **التذاكر — `TicketController`:** `GET /tickets/new`(Inertia)، `POST /tickets`(`store`)، `GET /tickets`,
  `/tickets/{ticket}`(`show`)، `POST .../messages`(`storeMessage`)، `.../attach`(`attach`، ملف ≤10MB)،
  `GET .../availability`، `POST .../book`(`book`).
- **القضايا — `CaseController`:** `index/show/storeMessage`، `POST .../pay`(+`pay/callback`,
  `pay-installment`).
- **التنفيذ — `ExecFlowController`:** `GET /execs`(`client`).
- **الاستشارات:** `ConsultBookingController` (`GET /book`(+`availability`)، `POST /book`(`store`))؛
  `ConsultController` (`GET /myconsults`، `POST /consults/{consult}/pay`(+`pay/callback`)، `.../schedule`،
  `GET /consults/room`).
- **المواعيد/الاجتماعات:** `AppointmentController@index`، `MeetingController`(`index/room`)،
  `MeetRequestController`(`index`، `POST .../confirm` — ينشئ جلسة Zoom+اجتماع+بريد)، `CalendarController@index`.
- **المستندات/الفواتير:** `DocumentController`(`index/store`(≤2MB)/`download` عبر Policy)، `InvoiceController`(`index`، `.../proof`(≤2MB)).
- **التنفيذ المشترك (`auth,active`، الحراسة داخليّة):** `ExecFlowController` — `POST /exec-flow`(`store`)،
  `.../action`(`act` موزّع إجراءات مركزيّ بخرائط صلاحية)، `.../pay`(+`callback`)، `.../messages`،
  `.../documents/{document}`(رفع)، `.../review`.

### 9.4 لوحة الموظف (`role:employee`, prefix `employee`) — كل مسار حسّاس فوقه `permission:`
- **التذاكر — `Employee\TicketController` (صلاحيّة «إدارة التذاكر»/«الرد على العملاء»):** `index/show/note/
  status/advance/convert/reply`. (`status`+`advance` تحرسان الرحلة؛ `convert` يشترط «مكتملة».)
- **القضايا — `Employee\CaseController`:** `index/show/reply` («إدارة القضايا والأتعاب»).
- **التنفيذ:** `ExecFlowController@employee`.
- **الاستشارات — `Staff\ConsultController` («استقبال الاستشارات»):** `index/show/take/requestDocs/analyze/
  saveAnalysis/approveAnalysis/refer/recv/start/end/createTasks/room`.
- **الجدولة/التحويل — `Employee\ScheduleController`(`index/store`)، `Employee\TransferController`(`index/
  transfer`)** («جدولة المواعيد»/«تحويل التذاكر»؛ كلاهما عبر `ActiveLawyer`).
- **الاجتماعات — `Staff\MeetRequestController`:** `index/store/cancel/start` («إرسال دعوات الاجتماعات»).

### 9.5 لوحة المحامي (`role:lawyer`, prefix `lawyer`)
- **التذاكر/الملخّصات — `Lawyer\TicketController` («اعتماد الملخصات»):** `dashboard/index/show/summaries/
  showSummary/updateSummary/approveSummary` (+`convert/close/requestDocs` بصلاحيّة القضايا). ‏`approveResult`
  ومساره حُذفا 2026-09-19 (لا يكتب شيءٌ `pending_lawyer`).
- **القضايا — `Lawyer\CaseController`:** `index/show/approvePleading/addHearing/recordHearing/recordRuling/
  convertToExecution`.
- **التنفيذ/المهام/التقويم:** `ExecFlowController@lawyer`، `Lawyer\TaskController`(`index/store/complete`)،
  `Lawyer\CalendarController@index`.
- **الاجتماعات/المساعد — `Staff\MeetingController` («إدارة الاجتماعات»):** `index/show/room/
  saveSummary/saveMinutes/end/createTasks`؛ `Staff\MeetRequestController`؛ `Lawyer\AssistantController`(`index/
  generate` — «المساعد القانوني»).
- **الاستشارات:** `Staff\ConsultController` (`recv/show/start/end/take/requestDocs/analyze/…/refer/tasks/room`).

### 9.6 لوحة الإدارة (`role:admin`, prefix `admin`) — الحماية بالدور فقط (تتجاوز spatie عبر `Gate::before`)
- **الإشراف:** `DashboardController@admin`، `Admin\ClientController@index` (PII مُقنّع)، `Admin\TicketController`
  (`index/show/summaries/correctStatus/proposeTrack/approveTrack`؛ `approveResult` حُذف 2026-09-19)، `Admin\LawyerController`(`index/toggleMode`).
- **الاستشارات — `Staff\ConsultController`:** `index/requests/`**`setPrice`**`/take/…/refer/priority/…`.
- **الإدارة العليا:** `Admin\StaffController` (`index/lookup`(جوال مُقنّع)/`store`/`update`/`toggle`/**`preview`**
  إمبرسنيشن)، `Admin\ArchiveController@index`،
  `Admin\DistributeController`(`index/auto/`**`assign`** عبر `ActiveLawyer`)، `Admin\CaseController`
  (`fees/index/`**`setFee`**`/closeCase`)، `Admin\TaskController`(`index/store`).
- **العمليّات/الاجتماعات — `Staff\MeetingController`:** `mgmt/store/log/index/`**`approve`**`/end/createTasks/
  reports`؛ `Staff\MeetRequestController`؛ `Admin\ClientNotifController`(`index/send/markRead`)؛ ملخّصات
  (`Admin\TicketController@summaries` + `Lawyer\TicketController@showSummary/updateSummary/approveSummary`).
- **الماليّة/التقارير:** `Admin\ReportController`(`revenue/reports`)، `Admin\PriceController`(`index/update`)،
  `Admin\AccountingController`(`index`، `POST /admin/invoices/{invoice}/pay`).

### 9.7 عزل الرؤية والإسناد (Traits & Rules)
> **كيان «الفرع» أُزيل بالكامل** (2026-08-20، الالتزام `640e3b6`). المكتب واحد، والموظف والإدارة يريان
> كل سجلّات المكتب؛ العزل الباقي هو عزل **المحامي** بالإسناد وعزل **العميل** بالملكيّة.
> زالت معه: `BranchScoped`, `HasBranch`, `Models\Branch`, `Admin\BranchController`, `Rules\LawyerInBranch`,
> `BranchSeeder`, `pages/admin/branches.tsx`، وعمود `branch` من كل الجداول.

- **`ScopedToLawyer`** (`Lawyer\*` + `Staff\*`): `guardAssigned()` — 403 إن لم يكن `assigned_lawyer_id`
  يساوي المستخدم. الإدارة مستثناة عبر `Gate::before`.
- **`Staff\*`** يعزل بالدور عبر `scopeForRole/cards`: المحامي المسنَد إليه فقط؛ **الموظف والإدارة: الكلّ**.
- **`app/Rules/ActiveLawyer.php`** (خَلَف `LawyerInBranch`): يرفض تمرير غير المحامي أو الموقوف كـ`lawyer_id` —
  في `distribute.assign`, `employee.transfer/schedule`, `consults.refer`, `meetings.store`.

### 9.8 نقاط التحقّق الأمنيّة البارزة
- **IDOR/403 (ملكيّة):** `authorizeTicket/authorizeCase`, `Consult::pay/schedule/room`, `Meeting::room`,
  `MeetRequest::confirm`, `Invoice::uploadProof`, `Task::complete` —
  كلّها `abort_unless(model->user_id === auth id)`. و`ZoomController::sdkSignature` عبر `ChannelAccess::ownerOrStaff`.
  و`DocumentController::download` عبر `DocumentPolicy` (الطاقم يرى الكلّ، العميل مستنداته فقط).
- **حراسة انتقالات الحالة:** `Employee\TicketController::status` (`abort_if` إعادة فتح + `abort_unless
  canTransition` منع القفز)؛ `Lawyer\TicketController::approveSummary` (**حارس الصدق**: منع اعتماد قالب غير
  محلَّل)؛ حارسات شرطيّة على `setFee/closeCase/approvePleading/recordRuling/approveResult/pay`.
- **منع التكرار (409):** `convertToCase` (قضية قائمة)، `createTasks` (استشارة/اجتماع).
- **`ExecFlowController::act`** (الأخطر): مصفوفة صلاحيّات لكل إجراء (client/adminOnly/intake/lawyerPickup/
  staffProc) بسلسلة `abort_unless/abort_if`، وإجراء مجهول → `abort(422)`.
- **حراسة تهيئة الخدمات (503):** بوّابة ميسّر/تضمين Zoom/أسرار الـwebhooks غير مهيّأة.
- **الـwebhooks:** لا تثق بالجسم — تعيد جلب الدفعة من ميسّر بالمعرّف؛ Zoom يتحقّق HMAC + طزاجة الختم (±5د).
- **الإمبرسنيشن:** `StaffController::preview` ↔ `ImpersonationController::leave` — تجديد الجلسة + Log؛ اللافتة
  عبر `HandleInertiaRequests`. > ⚠️ راجع القضيّة 3 في القسم 15 (تبديل الحساب أثناء المعاينة).

---

## 10. منطق الأعمال: الخدمات والدعم والوظائف والأحداث

**النمط المعماريّ السائد عبر كل التكاملات: أفضل-جهد (best-effort)** — كل نداء خارجيّ داخل
`try/catch` يسجّل ولا يرمي، **ومصدر الحقيقة قاعدة البيانات لا الشبكة**. بلا مفاتيح: التكامل إمّا
يعمل محاكاةً (ميسّر، Zoom) أو يُعطّل بأمان (تقنيات، الذكاء الاصطناعي).

### 10.1 الخدمات (`app/Services/*`)
- **`LegalAiService.php` — الذكاء الاصطناعي القانونيّ.** مزوّدان بسلسلة احتياط:
  **Gemini** (`POST generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`، ترويسة
  `x-goog-api-key`، فحص متعدّد الوسائط PDF/صور عبر `inline_data`، `thinkingBudget=0`) ← **GLM/z.ai**
  (`POST {base}/chat/completions`, Bearer). الدوال: `reply/greet/caseReply/execReply`،
  `summarize/classifyCase/triageTicket`، `analyzeDocument`، `draftPleading/assist`،
  `consultSummary/meetingSummary/extractDecisions`، `analyzeConsult/analyzeExecution`،
  `chooseLawyer/rankLawyers`، `isConfigured/available/parseJsonResponse`. **قاطع دائرة** عبر `Cache`
  (`ai:cooldown:{provider}`) عند 429/`RESOURCE_EXHAUSTED`/رصيد GLM `1113`. عند تعذّر الكلّ → **قالب
  احتياطيّ أمين** موسوم `ai_generated=false` (لا آراء/مسودّات مُختلقة؛ `extractDecisions` تُرجع `[]`).
  `run()` يرفع المهلة إلى 150ث.
- **`TaqnyatVerifyService.php` — OTP** (راجع القسم 6): `generate/check/isConfigured` عبر `verify.php`.
- **`MailService.php` — البريد:** `send($to, Mailable)` نقطة موحّدة (يسجّل `mail.send.failed` ولا يرمي).
- **`MoyasarService.php` — الدفع:** `createInvoice/fetchPayment/getInvoice/hostedUrlForInvoice`
  (يعيد استخدام فاتورة `initiated` لمنع الازدواج). مبالغ بالهللة، SAR، `metadata` للمطابقة، Basic Auth.
- **`ZoomService.php` — الجلسات المرئيّة:** S2S OAuth (`POST zoom.us/oauth/token`، رمز مُخبّأ ~50د)،
  `createMeeting` (تسجيل سحابيّ + ملخّص AI Companion + غرفة انتظار)، `deleteMeeting`, `sdkSignature`
  (JWT HS256، دور مضيف/مشارك)، `zakToken`, `downloadTranscript/cleanVtt`, `meetingSummary`,
  `summaryFromPayload`. تخبئة مع كبح فشل (تهدئة 60ث عند الفشل).
> لا تكامل Slack فعليّ (مفاتيح `slack/postmark/ses` قياسيّة فقط في `config/services.php`).

### 10.2 صفوف الدعم المحوريّة (`app/Support/*`)
- **صفوف الرحلة (مصدر وحيد للحالات/النغمات/الانتقالات):** `TicketJourney`, `CaseJourney`, `ExecJourney`.
- **التذاكر:** `TicketTriage` (الوكيل التشغيليّ: `onOpened/onDocumentAttached/onClientMessage/referToLawyer/
  requestHuman`؛ كل إجراء آليّ يُوثّق `who=note`)، `TicketTexts` (كلمات التصعيد)، `ServiceDocs`.
- **الإسناد والتفرّغ:** `TicketAssignment` (`pickLawyer` تصفية بالتخصّص + ترتيب حتميّ بالحمل/الأقدميّة +
  اختيار AI)، `LawyerAvailability` (`rankedSpecialists/assignLawyer/successScore/isBusy/slotsFor` فترات 60د)،
  `Specialties` (تطبيع 14 قسماً + مرادفات).
- **الاستشارات:** `ConsultBooking` (`request→setPrice→markPaid (idempotent)→schedule` بعد الدفع فقط،
  Zoom خارج المعاملة + `guardNoConflict` + تنظيف Zoom اليتيم)، `ConsultSummary`, `DecisionTasks`
  (قرارات→مهام `Task`، idempotent)، `AppointmentCard`, `TicketResult`.
- **القضايا/التنفيذ:** `CaseConversion`, `CaseFee` (سداد+تفعيل idempotent → `DraftCasePleadingJob`),
  `ExecService` (تدفّق 10 مراحل: `submit→AnalyzeExecutionJob→applyAnalysis→…→markPaid`)، `ExecFlow`,
  `ExecutionCreation` (فتح تنفيذ من قضية «صدر الحكم»).
- **بنية تحتيّة:** `Live` (غلاف البثّ)، `Notify` (إشعارات المستخدم)، `AfterResponse` (تأجيل الأعمال الثقيلة
  لما بعد الاستجابة — يتفادى مهلة 30ث؛ فوريّ في الطرفية/الاختبار)، `Phone`, `OtpService`, `EmailOtpService`,
  `MoyasarWebhook`, `ZoomWebhook`, `ZoomRecording`, `ZoomSummaryText`, `ChannelAccess`, `Permissions`
  (كتالوج صلاحيات spatie — **يجب مطابقته لـ`resources/js/lib/admin-data.ts`**)، `ClientDirectory`, `MeetingTime`.

### 10.3 الوظائف الخلفيّة (`app/Jobs/*`) — كلّها `ShouldQueue` (تتطلّب queue worker)
| الوظيفة | متى تُطلق | ماذا تفعل | خصائص |
|---|---|---|---|
| `TriageTicketOnOpenJob` | فتح تذكرة | `TicketTriage::onOpened` أو رسالة افتتاحيّة | — |
| `TriageDocumentJob` | إرفاق مستند | `TicketTriage::onDocumentAttached` (فحص فعليّ) | — |
| `AssignTicketJob` | التوزيع الإداريّ | اختيار AI خارج القفل ثم كتابة مقفولة + إعادة فحص السباق | — |
| `GenerateTicketReplyJob` | رسالة عميل بتذكرة | ردّ الدعم (يتوقّف إن أُغلقت) | 🔴 راجع القضيّة 1 |
| `GenerateTicketSummaryJob` | بعد الإحالة | ترقية الملخّص القالبيّ لتحليل AI حقيقيّ | `retryUntil=+24h`، `release(+30m)`، `failed()` يُصعّد لبشر |
| `GenerateCaseReplyJob` / `GenerateExecutionReplyJob` | رسالة عميل بقضية/تنفيذ | ردّ الفريق/قسم التنفيذ | — |
| `AnalyzeExecutionJob` | تقديم طلب تنفيذ | `analyzeExecution`+`applyAnalysis` (idempotent) | — |
| `DraftCasePleadingJob` | تفعيل القضية | مسودّة لائحة الدعوى | — |
| `FinalizeConsultJob` | إنهاء جلسة استشارة | ملخّص + قرارات + إشعار (idempotent) | — |
| `GenerateMeetingSummaryJob` | إنهاء اجتماع مكتب | ملخّص + محضر + قرارات + بثّ (idempotent) | — |
| `ProcessZoomRecordingJob` | `recording.completed` | `ZoomRecording::pull` (MP4 + نصّ) | `tries=3, backoff=[60,300], timeout=120, retryUntil=+6h` |
| `ProcessZoomSummaryJob` | `meeting.summary_completed` | `ConsultSummary/MeetingSummary::pull` | `tries=3, backoff=[60,300], timeout=120` |

### 10.4 الأوامر والجدولة (`app/Console/*` + `routes/console.php`) — تتطلّب `schedule:run` كل دقيقة
- `zoom:release-links` (**كل دقيقة**، `ReleaseMeetingLinks`): يُطلق رابط الجلسة قبل الموعد بـ5د + بثّ +
  `MeetingLinkReady` (idempotent عبر `link_released_at`).
- `zoom:pull-summaries` (**كل 5د**، `PullMeetingSummaries`): يجلب ملخّص AI Companion للجلسات المنتهية
  (بديل الويب‑هوك) (idempotent عبر `zoom_summary_at`).
- `meetings:send-reminders` (**كل دقيقة**، `SendMeetingReminders`): تذكير بريديّ قبل الموعد بـ`--lead=60`د،
  يختم `reminder_sent_at`. الكلّ `withoutOverlapping()`.

### 10.5 البريد (`app/Mail/*` + `resources/views/emails/*`) — كلّها `ShouldQueue` عدا رمز التحقّق
- `VerificationCodeMail` → `emails.verify` (**متزامن** — للوصول الفوريّ).
- `ConsultBooked`, `MeetingLinkReady`, `MeetingScheduledMail` → `emails.meeting-scheduled`,
  `MeetingReminderMail` → `emails.meeting-reminder`, `MeetingEndedMail` → `emails.meeting-ended`.
- القوالب: `emails/layout.blade.php` (RTL موحّد) + `partials/button.blade.php` + قوالب أعلاه.

---

## 11. المجالات الوظيفيّة ودورات الحياة (Domain Lifecycles)

### 11.1 رحلة التذكرة (Ticket Journey) — `app/Support/TicketJourney.php`
سبع مراحل متسلسلة (`STAGES`) + حالات بديلة (`ALIASES`):
```
جديدة(0) → قيد التحليل(1) → محالة للقسم القانوني(2) → الرأي القانوني(3)
→ بانتظار حجز الاستشارة(4) → موعد مؤكد(5) → مكتملة(6)
ALIASES: بانتظار مستندات(1) · بانتظار اعتماد المستشار(2) · بانتظار اعتماد الإدارة للملخّص(2)
         بانتظار تحديد الموعد(4) · بانتظار ملخّص الجلسة(5) · بانتظار قرار المآل(6)
         بانتظار اعتماد الإدارة للمسار(6) · محولة إلى قضية(6) · مغلقة(6)
```
> **حُذفت 2026-09-19** (لا يكتبها أيّ كود): «بانتظار الدفع»، «قيد التنفيذ» (للتذكرة؛ باقيةٌ حالةَ تنفيذ)،
> «بانتظار اعتماد النتيجة»، «بانتظار اعتماد الإدارة». يحرسها `RetiredStatusesStayGoneTest`. المصدر
> الواحد للحالات `App\Domain\Journey\Enums\TicketStatus`، وتسميات العميل `clientLabel()`.
- **الفتح:** `TicketController::store` (يجمع type/department/details فقط) → إسناد آليّ فوريّ
  (`TicketAssignment::assign`) → `TriageTicketOnOpenJob` → (إن كان الوكيل مفعّلاً) `TicketTriage::onOpened`
  ينقلها إلى **«بانتظار مستندات»** ويعرض `ServiceDocs::for($type)`.
- **المستندات:** `attach()` → `TriageDocumentJob` → `TicketTriage::onDocumentAttached` (فحص ارتباط بالـAI؛
  المرتبط يُحيل آليّاً عبر `referToLawyer`، غير المرتبط يُرفض، المتعذّر يُترك لمراجعة يدويّة).
- **الإحالة:** `referToLawyer` يكتب ملخّصاً رباعيّاً قالبيّاً فوراً ثم `GenerateTicketSummaryJob` يُرقّيه
  بتحليل AI حقيقيّ (شفاء ذاتيّ حتى 24 ساعة، ثم تصعيد بشريّ عند الفشل).
- **الاعتماد (كلّه عبر `Workflow::run`):** ملخّص الملفّ (عمود `ticket_summaries.status`): المحامي
  (`LawyerApproveTicketSummary` → `awaiting_admin`، والتذكرة بـ`AwaitAdminSummaryApproval` → «بانتظار اعتماد الإدارة
  للملخّص») ثمّ الإدارة (`FinalApproveTicketSummary` → `approved`، والتذكرة بـ`PublishLegalOpinion` → «الرأي القانوني»). الجلسة:
  `SessionEnded` → «بانتظار ملخّص الجلسة» → `ReadyForOutcome` → «بانتظار قرار المآل» → اقتراح المسار
  (`ProposeOutcomeTrack`) → «بانتظار اعتماد الإدارة للمسار» → `ApproveOutcomeTrack` (قضية/تنفيذ/إغلاق) أو
  `RejectOutcomeTrack`. التحويل لقضية ينشئها بـ`CaseConversion` بحالة «بانتظار اعتماد الأتعاب».
- **بوّابة الموظف:** `Employee\TicketController::advance` تحرس البوابات (مستندات، إحالة، انعقاد جلسة)
  و`status` تحرس الانتقالات (`TicketJourney::canTransition` — لا تقدّم للأمام عبر القائمة اليدويّة).

### 11.2 رحلة الاستشارة (Consult) — دورة الحجز
`TicketController::book` (طلب نوع) → تسعير الإدارة (`Staff\ConsultController::setPrice`) → دفع
(`ConsultController::pay`→ميسّر→`payCallback`) → اختيار موعد (`ConsultController::schedule`، إسناد ذكيّ
بأقفال تزامن، يضبط التذكرة «موعد مؤكد») → إطلاق الرابط (`zoom:release-links`، قبل الموعد بـ٥د) →
جلسة (`start` داخل نافذة ربع الساعة يفرضها الخادم / `end` **يُنهي اجتماع Zoom أيضاً**) →
`FinalizeConsultJob` (ملخّص + قرارات) → **اعتماد بشريّ** → وصولُه العميل.

**والاعتماد خطوةٌ لازمة لا تفصيل:** الملخّص المولَّد **محجوبٌ عن العميل** حتى يعتمده محامٍ
(`Consult::toClientCard` يُرجع `null` قبل الاعتماد، والقرارات تتبعه). ويقع الاعتماد من بابين
بكاتبٍ واحد (`AiReviewOutcome::approveConsultSummary`): صندوق المراجعة `/{role}/ai-review`،
أو شاشة الملفّ `POST /{role}/consults/{id}/summary/approve`. والباب الثاني لازم لأن ملخّصاً
يكتبه المحامي بيده — حين تنتهي الجلسة بلا تدوين — لا قيد له في `ai_runs` فلا يبلغ الصندوق أبداً.
وصلاحيّة التحرير/الاعتماد `اعتماد/تعديل ملخص الاستشارة`: **البوّابة صلاحيّة لا دور**، فالموظّف
يقرأ ولا يحرّر إلّا أن تمنحه الإدارة العليا إيّاها.

### 11.3 القضايا (Case Lifecycle) — `CaseJourney`
تُنشأ القضية من تذكرة مكتملة عبر `CaseConversion::convert` بحالة **«بانتظار اعتماد الأتعاب»**
(`fee_status=none`). ثمّ: الإدارة `Admin\CaseController::setFee` (تحدّد الأتعاب + نصيب المحامي + تُصدر
فاتورة → «بانتظار سداد الأتعاب») → العميل يسدّد (`CaseController::pay/payInstallment` عبر ميسّر) →
`CaseFee::activate` (idempotent: القضية «منظورة» + خطة عمل + `DraftCasePleadingJob` لمسودّة اللائحة) →
المحامي: `approvePleading`, `addHearing`/`recordHearing` (جلسات المحكمة، `case_hearings`), `recordRuling`
(«صدر الحكم») → `convertToExecution` (عند الأهليّة) أو `Admin\CaseController::closeCase` (أرشفة).

### 11.4 التنفيذ (Execution Lifecycle) — `ExecService` / `ExecJourney` (10 مراحل)
يُفتح من قضية محكومة (`ExecutionCreation`) أو طلب عميل (`ExecFlowController::store`، دور client فقط) →
`AnalyzeExecutionJob` (`analyzeExecution`+`applyAnalysis`) → موزّع الإجراءات المركزيّ
`ExecFlowController::act` يحرس كل انتقال بخريطة صلاحية (client/adminOnly/intake/lawyerPickup/staffProc):
الإدارة `setFee`/القرار، المحامي الالتقاط والإجراءات، العميل `acceptOffer` والدفع (`pay`+`markPaid`
idempotent → فتح ملف التنفيذ). المستندات (`execution_documents`: رفع→مراجعة) والإجراءات (`execution_procedures`).

### 11.5 الاجتماعات (Meetings / MeetRequests)
- **الدعوة:** الطاقم `Staff\MeetRequestController::store` يرسل دعوة (`meet_requests`, stage=SENT) → العميل
  `MeetRequestController::confirm` (ينشئ جلسة Zoom + `Meeting` «قادم» + بريد `MeetingScheduledMail`).
- **الجدولة المباشرة:** `Staff\MeetingController::store` (ينشئ اجتماعاً + جلسة Zoom + دعوة + بريد).
- **دورة الحياة:** إطلاق الرابط قبل 5د (`zoom:release-links` + `MeetingLinkReady`) → انعقاد (Zoom webhooks
  `meeting.started/ended`) → `end` → `GenerateMeetingSummaryJob` (ملخّص + محضر + قرارات) → **اعتماد الإدارة**
  `approve` (يُظهر المحضر للعميل + `MeetingEndedMail`) → `createTasks` (قرارات→مهام). التذكير عبر
  `meetings:send-reminders` (`starts_at`+`reminder_sent_at`).

### 11.6 الفوترة والمدفوعات (Billing)
فاتورة واحدة (`invoices`) تخصّ استشارة/قضية/تنفيذ (`consult_id`/`case_id`/`exec_id`). الدفع عبر ميسّر
(مستضاف) → webhook/callback → `PaymentReconciler::settle` (idempotent عبر `payments.gateway_payment_id`،
مبلغ بالهللة) → يوجّه حسب نوع الفاتورة: `ConsultBooking::markPaid` / `CaseFee::markPaid` / `ExecService::markPaid`.
دفع يدويّ: العميل يرفع إثباتاً (`InvoiceController::uploadProof`) والإدارة تُحصّل (`AccountingController::pay`).

---

## 12. الواجهة الأماميّة (Frontend — React 19 / Inertia / babylon.css)

**النمط:** SPA عبر Inertia — **لا Router مستقلّ في React**؛ التنقّل عبر `Link`/`router.visit`/`router.post`
من `@inertiajs/react`. نقطة الدخول `resources/js/app.tsx` تحلّ الصفحات بـ `import.meta.glob('./pages/**/*.tsx')`.
كل الصفحات تُغلّف تلقائيّاً بـ `AppLayout` **عدا** `welcome` وصفحات `auth/`. الواجهة **عربيّة RTL بالكامل**.
⚠️ التصميم الحيّ يعتمد كليّاً على **نظام أنماط CSS يدويّ واحد (`babylon.css`) لا Tailwind** (رغم وجود Tailwind في البناء).

### 12.1 شجرة الصفحات حسب الدور (`resources/js/pages/`)
الدور يُشتقّ من المسار عبر `roleOfPath()` (بلا بادئة = client).
- **مصادقة/عامّة:** `auth/login.tsx` (دخول بالهويّة+OTP، تسجيل ذاتي، اختيار حساب، أنماط `.lgn`)، `welcome.tsx` (هبوط).
- **العميل (جذر `pages/*`):** `dashboard`, `tickets`, `newticket`, `ticketchat`, `cases`, `casechat`,
  `execflow`, `book`, `myconsults`, `appointments`, `meetings`, `meetreqs`, `meetingroom`,
  `videoroom`, `calendar`, `documents`, `invoices`, `notifications`, `profile`.
- **الموظف (`pages/employee/`, بادئة `/employee`):** `dashboard`, `tickets`, `ticketchat`, `cases`, `case`,
  `consults`, `consult`, `consultrecv`, `schedule` (جدولة نيابةً)، `transfer` (تحويل بين المحامين)،
  `meetreqs`, `meetingroom`, `videoroom`.
- **المحامي (`pages/lawyer/`, بادئة `/lawyer`):** `dashboard`, `tickets`, `ticketchat`, `cases`, `case`,
  `consult`, `consultrecv`, `meetings`, `meeting`, `meetreqs`, `meetingroom`, `calendar`,
  `assistant` (المساعد الذكيّ لتوليد المسودّات)، `summaries`, `summary` (اعتماد الملخّص→الرأي القانونيّ)،
  `tasks`, `videoroom`.
- **الإدارة (`pages/admin/`, بادئة `/admin` — ~28 صفحة):** إشراف (`dashboard, clients, tickets, cases,
  execs, lawyers, consults, consult-requests, consult, consultrecv`)، إدارة عليا
  (`staff` تسجيل + صلاحيّات spatie، `archive, distribute, casefees, tasks`)، عمليّات/اجتماعات
  (`meetmgmt, meetreqs, meetlog, meeting, meetings, meetingroom, videoroom, clientnotifs, summaries`)،
  ماليّة/تقارير (`revenue, prices, accounting, meetreports, reports`).

### 12.2 التخطيطات والمكوّنات المشتركة
- **`components/layouts/AppLayout.tsx`:** الغلاف الوحيد (Sidebar + scrim للجوال + `ImpersonationBanner` +
  Topbar + المحتوى). يشترك في قناة `notifications.{userId}` (Echo/Reverb) ويعرض Toast + يحوّل `flash.error` لـ Toast.
- **`components/navigation/`:** `Sidebar.tsx` (يبني القائمة من `ROLE_NAV[role]` مصفّاة بالصلاحيّات، مبدّل
  «عرض اللوحات» للإدارة، مبدّل «تبديل الحساب» `/auth/switch-account`، خروج)، `Topbar.tsx` (عنوان/مسار من
  `ROLE_TITLES`، بحث، جرس إشعارات)، `ImpersonationBanner.tsx` (لافتة المعاينة، إنهاء `/impersonate/leave`).
- **`components/babylon/` (قلب النظام):** `Toast` (`ToastProvider`+`useToast`)، `ChatThread` (خيط موحّد
  بثلاثة أوضاع: محاكى/خادم/بثّ لحظيّ عبر أحداث `.message`+`.status`)، `DetailShell` (قشرة التفصيل)،
  `TicketActions` (`useTicketActions`: مودالات نواقص/تحويل/جدولة)، `TicketTalkingNotice` (منع الردّ
  المزدوج عبر presence+whisper)، `Modal`, `Badge`, `MsgMeta`, `StatRow`, `FlowLine`, `admin-charts`.
  و`components/SpecialistPicker.tsx` (منتقي المستشارين والفترات — يجلب التفرّغ من الخادم).

### 12.3 مكتبات `lib/` والبيانات المولّدة
- `data.ts` (مصدر التنقّل: `ROLES, ROLE_NAV, ROLE_TITLES, roleOfPath, NAV/TILES/VIEW_ROUTE`)، `icons.tsx`
  (~45 أيقونة SVG)، `chat.ts` (أنواع الرسائل + `TKT_LIFE`)، `echo.ts` (عميل Echo/Reverb + CSRF لـ axios)،
  `permissions.ts` (`usePermCatalog/canViewRoute`)، `newticket-data.ts` (`SVC` 31 خدمة، الأسعار، QR)،
  `admin/employee/lawyer-data.ts`، طبقات UI: `consult-ui, meeting-ui, zoom-room, case-ui, exec-ui`،
  `utils.ts` (`cn` + `maskLawyer`).
- **مولَّد بـ Wayfinder (لا يُحرَّر يدويّاً):** `resources/js/actions/**`, `resources/js/routes/**`, `wayfinder/index.ts`.

### 12.4 نظام الأنماط
- المصدر: `resources/css/app.css` (Tailwind + `babylon.css` + `html{direction:rtl}`) و**`babylon.css`
  (~799 سطر، مستخرج حرفيّاً 1:1 من التصميم المرجعيّ — ممنوع تعديل القيم).**
- **متغيّرات `:root`:** أزرق قانونيّ — `--primary:#0E5C9C`, `--ink:#13314F`, `--cyan:#11A0C8`,
  `--success:#1E9D6B`, `--amber:#C0832B`, `--red:#C0392B`، تدرّج `--brand`، `--sbw:256px` (عرض الشريط).
  الخطّ `Tajawal`. أصناف: `.app/.sidebar/.topbar`, `.stat/.tile/.card/.tbl/.btn`, `.journey/.jstep`,
  `.thread/.msg/.bubble/.composer`, `.tflow/.tf-grid`, `.lgn*` (الدخول)، `.perm-grid`. متجاوب + `@media print`.

### 12.5 props المشتركة عبر Inertia (`HandleInertiaRequests::share`, rootView=`app`)
| المفتاح | المحتوى |
|---|---|
| `auth.user` | `id, name, email, phone, role, roleLabel, avatar, home, isSuper, permissions (spatie؛ فارغة للمدير), accounts (نفس الهويّة+الجوال — لمبدّل «تبديل الحساب»)` — أو `null` |
| `impersonating` | `{name}` عند وجود `impersonator_id` في الجلسة |
| `permCatalog` | كتالوج صلاحيّات spatie (`Permissions::catalog()` — permissions/groups/presets/**viewMap**) |
| `unreadNotifications` | عدّ كسول للإشعارات غير المقروءة (الجرس/الشارة) |
| `flash.error`/`flash.success` | رسائل الجلسة (error→Toast تلقائيّاً) |
| `generatedPassword` | كلمة مرور الموظف الجديد (تُعرض مرّة للإدارة) — ⚠️ وهميّة، مرشّحة للإزالة |

### 12.6 أدوات البناء
- `vite.config.ts`: `laravel-vite-plugin` (مدخلات `app.css`+`app.tsx`) + `@inertiajs/vite` +
  `@vitejs/plugin-react` (+ **react-compiler**) + `@tailwindcss/vite` + **wayfinder** (`formVariants:true`).
- `tsconfig.json`: `strict`, `moduleResolution: bundler`, اسم مستعار **`@/* → ./resources/js/*`**.
- مدير الحزم: **pnpm** (`pnpm-workspace.yaml`).

---

## 13. البثّ اللحظي (Realtime — Reverb)
- **الجسر:** `app/Support/Live.php` — `push(...$events)` أفضل-جهد (يسجّل الفشل ولا يرمي؛ البثّ تحسينٌ لا
  مصدر حقيقة). يُؤجَّل عبر `DB::afterCommit`/`AfterResponse` لتفادي سباقات المعاملة.
- **كل الأحداث `ShouldBroadcastNow`** (تُرسل شبكيّاً داخل الطلب نفسه):

| الحدث | القناة | ملاحظة |
|---|---|---|
| `TicketMessageBroadcast` | `ticket.{id}` أو `ticket.{id}.staff` (ملاحظات داخليّة) | `broadcastAs('message')` |
| `TicketStatusBroadcast` | `ticket.{id}` | حالة التذكرة |
| `CaseMessageBroadcast` / `CaseStatusBroadcast` | `case.{id}` | القضايا |
| `ExecMessageBroadcast` / `ExecStatusBroadcast` | `exec.{id}` | التنفيذ |
| `ConsultStatusBroadcast` | `consult.{id}` | الاستشارة/الجلسة/الملخّص |
| `MeetingStatusBroadcast` | `meeting.{id}` | الاجتماعات |
| `UserNotificationBroadcast` | `notifications.{userId}` | `broadcastAs('notify')` — غلاف `Notify` |

- **التفويض:** `routes/channels.php` عبر قاعدة موحّدة `app/Support/ChannelAccess.php`: العميل المالك، أو
  الإدارة مطلقاً، أو المحامي المسنَد، أو أيّ موظف (مكتب واحد). قناة
  `ticket.{id}.presence` تُرجع بيانات العضو (لمنع الردّ المزدوج بين الموظفين) و`null` للعميل.
- **لا `app/Listeners/` ولا `app/Broadcasting/` ولا `app/Notifications/`** — البثّ يُطلق مباشرةً من
  Support/Jobs عبر `Live::push`. العميل عبر `laravel-echo` + `pusher-js`.

---

## 14. الاختبارات وبوّابات الجودة (Testing & QA)
- **الإطار:** Pest 4 + PHPUnit، `tests/Feature/*` و`tests/Unit/*`. قاعدة اختبار: `RefreshDatabase`.
- **حالة معروفة:** المجموعة خضراء بالكامل — **743 اختباراً في 133 ملفّاً** (2026-08-21) مع
  `tsc`/`pint`/`build` نظيفة.
- **⚠️ شغّلها على دفعات (~10 ملفّات لكل استدعاء).** الحزمة كاملة في استدعاء واحد كانت تسقط بـ
  «Maximum execution time exceeded»: `LegalAiService` كان يستدعي `set_time_limit(150)` فيخفض مهلة
  الطرفية اللامحدودة. عولج بـ`App\Support\WebTimeLimit` (يرفع ولا يخفض، ويتخطّى الطرفية).
- **أمثلة مفتاحيّة:** `tests/Feature/TicketTriageTest.php` (الوكيل الذكيّ للتذاكر)، `AiResilienceTest.php`
  (مرونة الـAI/قاطع الدائرة).
- **بوّابات ما قبل الدمج:** `artisan test` + `pint --test` + `phpstan` (larastan) + `tsc --noEmit` +
  `eslint` + `prettier --check`. (script `composer test`/`ci:check`.)

---

## 15. القضايا المعروفة والديون التقنيّة (Known Issues & Tech Debt)
> حُدِّث 2026-08-21 بعد دفعات الإصلاح أ–و. ما كان مُدرَجاً هنا وعولج نُقل إلى «عولجت» بمرجعه.

### مفتوحة
1. **🟠 الانتحال + تبديل الحساب:** [`AuthController::switchAccount`](app/Http/Controllers/AuthController.php:178)
   لا يفحص `impersonator_id`، ومبدّل الحسابات يظهر أثناء المعاينة.
   **الحلّ:** `abort_if($request->session()->has('impersonator_id'), 403)` + إخفاء `accounts` في المشاركة.
2. **🟠 لا إعادة توليد لملخّص التذكرة عند مستند جديد:** `GenerateTicketSummaryJob` يُطلق مرّة واحدة؛
   المستند الجديد لا يُحدّث `TicketSummary`. **الحلّ:** علم `force` + إعادة إطلاق مشروط (لغير المعتمد).
3. **🟡 `generatedPassword` وهميّة:** [`Admin\StaffController:59`](app/Http/Controllers/Admin/StaffController.php:59)
   يعرض كلمة مرور مولّدة، والدخول OTP لا يستخدمها إطلاقاً — تضليل للإدارة.
4. **🟡 `starts_at` هشّ** عند إدخال تاريخ عربيّ حرّ للاجتماعات (قد يصبح `null`) — يُنصح بمنتقي تاريخ/وقت.
5. **🟡 لا مسار إلغاء/تعديل موعد استشارة** — فجوة مستقبليّة؛ إن أُضيف يلزم ربط عكسيّ بحالة التذكرة.
6. **🟡 دَين تنسيق:** `phpstan`/larastan غير مضبوط في بوّابات ما قبل الدمج رغم ذكره في القسم 14.

### قرار عمل معلّق (ليس عطلاً)
- **التنفيذ الناشئ من قضية يبدأ بالمرحلة 8:** [`ExecutionCreation::fromCase`](app/Support/ExecutionCreation.php)
  يُخزّن `stage = null` فتُرجع `Execution::effectiveStage()` القيمة 8 «قيد التنفيذ» — أي يتخطّى
  الدراسة والأتعاب والعرض والسداد. **موثَّق صراحةً كسلوك مقصود**: الملفّ يخصّ عميلاً قائماً بحكم صادر،
  فيُعامَل كملفّ مفتوح لا كطلب استقبال. تغييره يعني ألّا يبدأ العميل تنفيذ حكمه حتى يقبل عرضاً جديداً
  ويسدّد أتعاباً — **قرار عمل لا هندسة**، ولم يُتّخذ.

### عولجت (2026-08-20/21)
- ✅ **ردود الـAI فوق المحامي:** حاجز `TicketJourney::indexOf` في `GenerateTicketReplyJob:35`.
- ✅ **مهلة الحزمة:** `WebTimeLimit` (يرفع ولا يخفض) بدل `set_time_limit(150)` في `LegalAiService`.
- ✅ **`route:cache` يفشل:** تسجيل مكرّر لنفس اسم المسار (لم يُحذف أيّ مسار — راجع `route:list`).
- ✅ **الأرقام الدوليّة محجوبة عند التسجيل:** توحيد `Phone::RULE` بين `login.tsx` والخادم.
- ✅ **`approveFee` يرفض `0`:** الواجهة ترسل `0` حين يُترك الحقل الاختياريّ فارغاً.
- ✅ **رابط الجلسة يطرد الموظف:** `Consult::joinLink($user)` صار `match` لكل الأدوار، و`toCard()` يمرّر المشاهد.
- ✅ **أرشيف Zoom يستنزف الذاكرة:** `writeStream` بدل `File::get()`؛ و`ShouldBeUnique` + كابح 6 ساعات
  للفاشل يمنع 96 إعادة صفّ يوميّاً.
- ✅ **مراحل بلا مُطلِق:** التنفيذ 7 (`markPaid` يمرّ بها).
- ✅ **التذكرة تعلق عند جدولة الموظف:** `ticket_no` يُمرَّر و`ConsultBooking::create()` يُقدّم الحالة.
- ✅ **تضارب البذور:** `DatabaseSeeder` صار متكرّر الاستدعاء بأربعة حسابات (القسم 17)؛ السيدرات
  المذكورة سابقاً (`StaffSeeder`/`TicketSeeder`/…) **لم تعد موجودة**.
- ✅ **`public/hot` البائت:** كان يشير لخادم Vite ميت على نطاق مشروع آخر ⇒ صفحات بيضاء. محذوف ومُتجاهَل.
- ✅ **XSS مخزّن (5 نواقل)** و**11 صلاحية ميتة** و**كيان الفرع** (راجع 9.7).

### تشغيليّة
- **`queue:work` + `schedule:run` إلزاميّان** — بدونهما تتعطّل صمتاً: البريد المُطابَر، ملخّصات الـAI،
  أرشيف Zoom، التذكيرات. `deploy.sh` صار يفحص وجود عامل حيّ ويحذّر إن كان `QUEUE_CONNECTION=sync`.
  ومحلّياً (`sync`) تُؤجَّل مهمّة الأرشيف إلى ما بعد إرسال الاستجابة كي لا يقع 504.
- تدوير مفتاح Resend وتوثيق نطاق `salasel.sa` فيه؛ ومفتاح تقنيات صالح لإرسال SMS فعليّ.
- **`PermissionSeeder` يُنشئ ولا يحذف** — للتنظيف: `php artisan permissions:prune [--force]`.

---

## 16. اتفاقيّات وأنماط الكود (Conventions & Patterns)
- **معماريّة طبقيّة/SOLID:** المتحكّمات رفيعة؛ المنطق في `Support/*` و`Services/*`؛ مصدر وحيد لكل قرار
  (مثل `*Journey::toneFor` للألوان، `TicketAssignment` للإسناد) لمنع تفرّق المفردات.
- **حالات دورة الحياة نصوص عربيّة** ثابتة تُدار حصراً عبر صفوف `*Journey` (لا نصّ حرّ).
- **البثّ بعد الالتزام:** `DB::afterCommit` / `AfterResponse` قبل `Live::push` لتفادي سباقات المعاملة.
- **التعطّل الآمن للتكاملات:** كل مزوّد خارجيّ له نائب/fallback (AI قالبيّ، Zoom رابط احتياطيّ، بريد log،
  دفع محاكى) — النظام يعمل بلا مفاتيح للاختبار.
- **الأمان الإنتاجيّ:** أسرار خادميّة لا تُسرَّب؛ ويب‑هوكس محميّة بتوقيع/سرّ؛ تقنيع الهواتف/العملاء في العرض؛
  عزل صارم بالدور والملكيّة؛ تحقّق مزدوج (واجهة + خادم).
- **الوقت:** `Concerns/UsesClock` / دوال `clock()` تُنسّق الوقت العربيّ (ص/م).
- **التوجيهات الحاكمة للمالك:** لا تعديل/استنتاج من ملفّات المكتبات؛ اتّباع أنماط المشروع؛ لا ادّعاء «يعمل»
  قبل تحقّق حيّ؛ فهم التوثيق الرسميّ واختبار فعليّ (لا تخمين)؛ حدّ رفع الملفّات 2MB.

---

## 17. الحسابات الافتراضيّة (من البذور)
**الدخول OTP (رقم الهويّة + رمز SMS).** البذور تضبط `password='password'`، لكنّ مسار الدخول لا يستخدمها
(الدخول بالهويّة+OTP؛ رمز التطوير عبر `AUTH_DEV_OTP`). `DatabaseSeeder` يزرع أربعة حسابات فقط — واحد لكل
دور — ويستدعي `PermissionSeeder`. البذرة **متكرّرة الاستدعاء** (idempotent): تطابق بالهويّة+الدور، وأي حساب
قديم يحمل نفس البريد يُؤرشَف بريده (`archived+{id}.{email}`) بدل أن يفشل الزرع.

| الدور | الهويّة | البريد | الجوال |
|---|---|---|---|
| admin (الإدارة العليا) | 1000000001 | `kfykfy2020@gmail.com` | ‎+966537434000 |
| lawyer (المحامي) | 1000000002 | `law@salasel.sa` | ‎+966537434000 |
| employee (الموظف) | 1000000003 | `emp@salasel.sa` | ‎+966537434000 |
| client (العميل) | 1000000004 | `m.bander.it@gmail.com` | ‎+967779475324 |

- المحامي/الموظف يُمنحان `Permissions::ROLE_PERMISSIONS[role]`؛ الإدارة تتجاوز الحرّاس عبر `Gate::before`.
- **`DemoDataSeeder`** (يُستدعى من `DatabaseSeeder`) يزرع بيانات عرض إضافيّة فوق هذه الأربعة.
- **`PermissionSeeder` يُنشئ ولا يحذف:** الصلاحية التي تُزال من الكتالوج تبقى صفّاً في القاعدة ومُسنَدة.
  للتنظيف: `php artisan permissions:prune` للعرض، و`--force` للحذف الفعليّ.

## ديونٌ تنتظر قرار المالك (2026-09-05)

أربعةُ أمورٍ كشفها تدقيق شاشات الاستشارات، **كلٌّ منها قرار منتجٍ لا إصلاح عطل** — فوُثِّقت
ولم تُغيَّر. ولكلٍّ حارسٌ يمنع تفاقمَه بصمت.

### 1. النصّ التفريغيّ يحمل الأسماء والقائمة تُقنّعها — **ينتظر خطّة المالك**

> **حالته:** مؤجَّل بقرار المالك (2026-09-05) — لديه خطّة متكاملة للتنقيح والتشفير
> تُذكر لاحقاً. لا تغييرَ شيفرة حتى تصل. وما يلي وصفُ التناقض كما هو اليوم.

`ArchiveController::index` يمرّر اسم العميل عبر `Ticket::maskClient` — وهي تُقنّع **بلا
شرط**، فالمدير نفسه يرى «ع••••ه (مشفّر)». ثمّ يُنزّل الملفّ التفريغيّ (`transcript.txt`)
فيجد فيه **أسماء المتحدّثين كاملةً** كما تكتبها Zoom في VTT.

فإمّا أنّ التقنيع في القائمة زائدٌ (المدير مخوَّلٌ أصلاً)، وإمّا أنّ الملفّ يحتاج تنقيةً
قبل التسليم. **الأمران لا يجتمعان.** والقرار خصوصيّةٌ لا شيفرة.

### 2. صلاحيّاتٌ ممنوحةٌ لا تفتح باباً

`PermissionReachabilityTest` يمسح جدول المسارات ويُسقط أيّ منحةٍ محجوبةٍ عن صاحبها.
أُصلحت واحدة (**«أرشيف الاستشارات»** أُزيلت من دور المحامي وقالبه: مساراتها الأربعة
داخل `role:admin` و`EnsureRole` يحجب غير الإدارة بلا استثناء موثَّق). وبقيت ستٌّ في
`KNOWN_DEAD` لأنّ إصلاحها **يغيّر من يقدر على ماذا**:

| الدور | الصلاحيّة |
|---|---|
| employee | توزيع التذاكر · إشعارات العملاء · إدارة المواعيد والحجوزات |
| lawyer | اعتماد الاجتماعات · تقارير الاجتماعات |

**✅ وأُصلحت منها الأخطر: «تشغيل تلخيص الفريق القانوني»** (2026-09-05). كان مسارا
`analyze` للموظّف والمحامي محروسَين بـ«استقبال الاستشارات» لا بها — فمن ينزعها عن محامٍ
يظنّ أنه منعه ولا يمنعه، **والموظّف لا يملكها ويشغّل التلخيص**. صار الوسيط على المسارين.

**وبقرار المالك:** الصلاحيّة **للمحامي**، والموظّف يفقد الإطلاق (يبقى له طلب المستندات
وتحرير التحليل واعتماده والإحالة). وأُضيفت إلى **سقف** الموظّف
(`ROLE_PERMISSIONS['employee']`) **بلا منحٍ افتراضيّ** — غائبةٌ عن قالب «خدمة عملاء» وعن
بذرة الموظّف — فيبقى بيد الإدارة مفتاحُ استثناءٍ لموظّفٍ بعينه. حارسها
`ConsultAnalyzePermissionTest`.

**ومن يُطلق التحليل بدلاً منه؟ المحامي المسنَد** — أزرار رحلة الاستشارة مشروطةٌ بالحالة
لا بالدور، فيفتح الصفحة نفسها ويرى الزرّ نفسه.

### 3. الحجز المتعذّر يُرفع إلى الإدارة — **أُصلح** (2026-09-05)

كان `ConsultController::schedule` يرمي خطأ تحقّقٍ حين لا يجد محامياً: عميلٌ **سدّد
الفاتورة** ثمّ صُدّ عند اختيار الموعد، **ولا أحد في الإدارة يعلم**. ورسالتُه كاذبة فوق
ذلك — «لا يوجد مستشار **مختصّ**» بينما `rankedSpecialists` يسقط إلى **كلّ** المحامين
النشطين حين لا يطابق أحدٌ التخصّص. فالسبب الحقيقيّ أنّ الجميع مشغولون في تلك الساعة.

**بقرار المالك:** لا رفض — يُحجز الموعد ويُرفع الملفّ إلى **أقدم إداريّ** بوسم
«الإدارة العليا» (احتذاءً بسابقة `EscalateUnassignedTicketJob` في التذاكر، بقفلٍ يمنع
الكتابة فوق إسنادٍ يدويّ)، ويُشعَر كلُّ إداريّ ليوزّعه، ويُقيَّد السبب في سجلّ التدقيق.
والعميل يُشعَر بأنّ **موعده** مؤكَّد و**مستشاره** يُسنَد قبل الجلسة.

ويُعرَض في شاشة الاستشارات بمرشِّح **«بانتظار إسناد مستشار»** وشارةٍ على البطاقة — فلا
يبقى التصعيد إشعاراً يمرّ. والتوزيع بزرّ «إعادة إسناد المحامي» القائم.

**ويبقى الرفض** في حالةٍ واحدة: ألّا يوجد إداريٌّ أصلاً (تثبيتٌ جديد) — فلا يُترك ملفٌّ
بلا مالك. ورسالتُه لا تنسب التعذّر إلى التخصّص. حارسه `ConsultBookingEscalationTest`.

**ملاحظةٌ للمالك:** في القاعدة الحاليّة **محامٍ نشطٌ واحد** — فجدولُه سقفُ المكتب كلّه،
وكلّ تعارضٍ في الوقت يصير تصعيداً. زيادةُ المحامين النشطين تقلّل التصعيد من أصله.

### 4. الفاتورة ليست فاتورةً ضريبيّة (ZATCA)

وسم «فاتورة معتمدة» في شاشات الطلبات لا يقابله امتثالٌ لفوترة هيئة الزكاة والضريبة: لا
رقم تسجيل ضريبيّ، ولا QR بصيغة TLV، ولا توقيع. **قرارك: مخطَّطٌ لاحقاً — يُترك.** ويُسجَّل
هنا كي لا يُقرأ الوسم إقراراً بالامتثال.

### 5. `mins` عمودٌ بلا كاتب

بقي في `consults` وفي `fillable` للتوافق، ولا يُقرأ في أيّ شاشة. البديل `ageMins` يُشتقّ
من `created_at` عند كلّ قراءة. حذفُ العمود يحتاج هجرة — يُؤجَّل حتى هجرةٍ مجمَّعة.

---

## 16. حوكمة رحلة الاستشارة والاسترداد المالي (2026-09-18)

- ✅ **حوكمة إلغاء طلبات الاستشارة وتوثيق سبب الإلغاء إدارياً ورقابياً:**
  1. **الواجهة (Frontend) وتوحيد شروط الظهور:** مطابقة المنطق تماماً بين `consult-requests.tsx` و`consults.tsx` بحيث يشترط كلاهما قيد `CONSULT_BOOKING_STATUSES.includes(status)` لمنع إتاحة الإلغاء لطلبات منتهية أو ملغاة مسبقاً، مع توحيد تصميم بطاقة الإلغاء في الدرج (إطار أحمر جانبي `borderRight: 4px solid #C0392B` وعنوان أحمر ونص تنبيهي موحّد)، وتوفير زر الإلغاء السريع على بطاقات كافة أعمدة الكانبان (بانتظار التسعير، بانتظار السداد، وبانتظار الموعد) وفي أسطر جداول العرض في الشاشتين، وإتاحته داخل تبويب التسعير كذلك لتمكين الموظف من الإلغاء المباشر دون تنقل زائد.
  2. **الخادم ومحرك الرحلة (Backend & FSM):** التحقق من صحة السبب `reason` في المتحكم `StaffConsultController::cancelRequest` وتمريره لمحرك الحالات `Workflow::run(new CancelRequest, ..., ['reason' => $reason])`؛ توثيقه في عمود `reason` وحمولة `payload` في جدول `journey_transitions`، وإدراجه في سجل التدقيق المحلي للاستشارة `audit` وسجل التدقيق الأمني العام `Audit::log`.
  3. **الإشعارات المتكاملة (Omnichannel Alerts):** تضمين سبب الإلغاء في إشعار العميل، واستحداث إشعار تقويمي خاص للمحامي المسند (`assigned_lawyer_id`) عبر `Notify::send($consult->assigned_lawyer_id, 'cal', 't-amber', ...)` يبلغه بصريح العبارة بإلغاء موعد جلسة الاستشارة المسندة إليه مع تفاصيل اليوم والساعة وسبب الإلغاء.
  4. **أتمتة تنبيه الاسترداد المالي (Refund Notification):** في حال كانت الاستشارة مدفوعة مسبقاً (`paid_at !== null` أو توجد فاتورة مسددة `paid = true` أو الحالة السابقة كانت بانتظار تحديد/اعتماد الموعد)، يقوم المستمع `HandleConsultCancelled` تلقائياً ببث تنبيه بطاقة حمراء عاجلة (`card`, `t-red`) لكافة حسابات الإدارة والمحاسبة تتضمن رقم الاستشارة ورقم الفاتورة المسددة ومبلغ الاسترداد لرد المبلغ للعميل، وتوثيق قيد استرداد مالي مستحق حرج في سجل التدقيق الأمني العام `Audit::log`.
  5. **تحرير جدول تفرغ المحامي (Availability Leak Fix):** استثناء المواعيد والاستشارات والاجتماعات الملغاة بكافة صيغها الإملائية (`AppointmentStatus::Cancelled->value`, `'ملغى'`, `'ملغي'`, `'ملغاة'`) في `LawyerAvailability::busyIntervals` لتحرير أوقات المحامي فور الإلغاء ومنع حجز الفترات الزمنية للمحامي بمواعيد ملغاة.
  6. **تغطية الاختبارات الآلية الشاملة:** إضافة اختبارات متقدمة في `JourneyConsultLifecycleTest` للتأكد من حفظ السبب في سجلات الانتقالات والتدقيق، وإشعار العميل والمحامي بإلغاء موعد الجلسة، وإطلاق تنبيه الاسترداد المالي للإدارة عند إلغاء استشارة مدفوعة، وتحرير فترات انشغال المحامي فوراً (17 اختباراً و84 تأكيداً بنجاح 100%).

---

## 18. محرّك الحالات: الكاتب الوحيد، وحذف الحالات القديمة (2026-09-18 → 19)

> العمل على الفرع `refactor/journey-engine-2026-09-19` (غير مدموج في `main` — الدمج بإذن المالك).
> كلّ خطوةٍ commit مستقلّ قابل لـ`git revert`.

### 18.1 المحرّك (`app/Domain/Journey/Workflow.php`)
- **`Workflow::run(Transition, Model, ?User, payload)`:** قفل الصفّ ← `accepts(from)` (رفض 422) ← `deny` (403)
  ← `guard` (422) ← `apply` ← كتابة العمود ← سطرٌ في `journey_transitions` (الفاعل، والسبب، و`record()`).
  الأحداث تُطلق **بعد الالتزام** (`DB::afterCommit`)، وأحداث البثّ منها عبر `Live::push` (قاطع دائرة) لا
  `event()`: تعذُّر Reverb بعد الحفظ كان يُجهض ما بعد الانتقال ويعيد 500 على تغييرٍ تمّ.
- **`Workflow::open($name, $create, ...)`:** إنشاء الكيان بحالته الأولى داخل المحرّك، وأوّل سطرٍ في سجلّه
  (`from_state = null`).
- **`StateWriteGuard`** يراقب: `Ticket.status`، و`Consult.status/session/summary_approved_at`،
  و`Appointment.status`، و`Invoice.status/paid` (فواتير الاستشارة)، و`TicketSummary.status/result_status`،
  و`LegalCase.status`، و`Execution.status/stage`. **`LEGACY_WRITERS` فارغة** — لا كاتب خارج المحرّك،
  ويحرس فراغها `LegacyWritersListTest`. الاختبارات تعمل بـ`JOURNEY_GUARD=throw` (أيّ كاتبٍ جديد يُسقطها)؛
  الإنتاج `off` حتى قرار المالك (`record` ثمّ `throw`).

### 18.2 الحالات المحذوفة (قرار المالك 2026-09-19)
- **التذكرة:** «بانتظار الدفع»، «قيد التنفيذ»، «بانتظار اعتماد النتيجة»، «بانتظار اعتماد الإدارة».
- **التنفيذ:** «مكتمل» (`Execution::CLOSED_STATUSES = ['مغلق']`).
- **نتيجة الملخّص:** `pending_lawyer` مع مسار «اعتماد المحامي لنتيجة الجلسة» كلّه (المتحكّم والمسار والانتقالان).
- **الواجهة (بموافقة المالك):** زرّ «سداد الرسوم» في تذاكر العميل، وبطاقة «نتيجة الجلسة» وزرّها للمحامي — كانا
  لا يظهران مع بياناتٍ حيّة.
- **الحارس:** `RetiredStatusesStayGoneTest` — الكتالوج، وكود الخادم والبذور والمسارات والإعدادات بأيّ علامة
  اقتباس، وكلّ مقارنة (`===`/`case`) في الواجهة، والمواضع السابقة.

### 18.3 ضمانة الواجهة: `scripts/ui-inventory.mjs`
أداةٌ تجرد من كلّ `.ts/.tsx` ما يُرى أو يحدّد شكله: النصوص (ومعها ما فيه قيمة مُدرجة «الكل (*)»)، وأصناف الألوان
`b-*/t-*`، والأصناف (ومنها الشرطيّة)، والأيقونات، والألوان، والمسارات (ومنها المبنيّة من متغيّر)، وعدد العناصر
(`<button>`/`<Badge>`/`onClick=`…)، وقيم شروط الإظهار، وقواعد CSS.
```bash
node scripts/ui-inventory.mjs snapshot before.json            # الشجرة الحاليّة
node scripts/ui-inventory.mjs snapshot base.json <dir>        # نسخة مستخرجة من commit (git archive)
node scripts/ui-inventory.mjs diff before.json after.json allow.json   # «ناقص» خارج قائمة السماح = توقّف
```
نتيجة هذه الخطوة من `259f6d8` إلى نهاية الفرع: **ناقص 0** خارج قائمة السماح (30 عنصراً كلّها من العنصرين المحذوفين
بقرار المالك ونصوص الحالات المحذوفة)، و`resources/css` لم يُمسّ، و`tsc` نظيف، و`eslint` مطابق لما قبل.

### 18.4 إصلاحاتٌ وجدها مراجعان مستقلّان
- **البثّ بعد الحفظ** (أعلاه) — `JourneyWorkflowEngineTest::test_an_unreachable_broadcaster_…`.
- **سباق خطّاف ميسّر والعودة:** الخاسر كان يكتب قيداً حرجاً «تتطلّب استرداداً» على دفعةٍ سليمة؛ الآن تُعاد قراءة
  الفاتورة (`PaymentReconciler::settleConsult`، و`ConsultController::payCallback`).
- **شريط رحلة العميل:** ثلاث تسميات عميل غابت عن `tktStage` فارتدّ الشريط إلى الصفر على الحالات الأخيرة؛
  `TicketStageParityTest` يفحص الاتّجاهين الآن.

### 18.5 الحزمة الكاملة (2026-09-19، بعد كلّ ما سبق، على 32 دفعة و`JOURNEY_GUARD=throw`)
**2136 اختباراً — نجح 2133، وفشل 3 معروفة** (`MultiAccountAuthTest::test_dev_bypass_*`، تجاوز رمز الدخول في
التطوير — متروكة بقرار المالك). لا إخفاق جديد، وملفّات قرص التطوير الحقيقيّة سليمة بعد التشغيل.

### 18.6 ما ينتظر قرار المالك (وُجد ولم يُعدَّل)
- ✅ **حُسم (2026-09-19):** سلسلة `pending_admin` حُذفت بقرار المالك — شارة «بانتظار اعتماد النتيجة» وزرّ «اعتماد
  نهائي وإرسال النتيجة» في «سجلّ المعتمد والنتائج»، و`Admin\TicketController::approveResult` ومساره
  `admin.tickets.result`، والانتقال `AdminApprovePendingResult`؛ و`RejectTicketResult` يقبل «approved» وحدها.
  التحقّق قبلها: لا كاتب لها (الحارس)، وصفر صفوف في قاعدة التطوير. جرد الواجهة: 11 عنصراً ناقصاً كلّها منها.
- ✅ **أُصلح (2026-09-20، بموافقة المالك):** نافذتا «تأكيد الموافقة» و«تأكيد الرفض» في مركز الاعتمادات كانتا بلا زرّ
  تنفيذ — `handleApprove`/`handleReject` معرَّفتان ولا يناديهما شيء منذ أوّل حفظٍ للملفّ. أُضيف «تأكيد الاعتماد»
  و«تأكيد الرفض والإعادة» (معطَّلٌ حتى يُكتب السبب؛ ٣ أحرف كشرط الخادم) و«إلغاء». جُرّب في المتصفّح على تذكرتَي تجربة
  (اعتمادٌ ورفض لمقترح مسار، عبر المحرّك وسجلّه) ثمّ حُذفتا. يحرسه
  `AdminApprovalsOperationsTest::test_the_approve_and_reject_dialogs_have_their_confirm_buttons`.
  وكانت للإدارة طرقٌ بديلة تعمل: صفحة التذكرة (المسار والملخّص)، وصفحة الاستشارات (ملخّص الجلسة)، وطلبات الاستشارة (الموعد).
- أصناف ألوان بلا CSS: `b-purple`/`b-teal` (`admin/distribute.tsx`، `lawyer/editor.tsx`)، `b-muted` (`lawyer/editor-index.tsx`).
- `pages/ticketchat.tsx`: رابط `/executions` والمسار `/execs`، والتمرير إلى `.book-consult` غير الموجود.
- `lawyer/case.tsx` يقرأ `c.status` لا `live.status`؛ عدّاد «بحاجة لملخّص» في `lawyer/consults.tsx` يخالف قائمته.
- إسناد التذكرة يدويّاً والوكيل الذكيّ مفعّل لم يعد ينقلها إلى «محالة للقسم القانوني» (`TicketAssignment::write`) — مقصود؟
- **تصميميّ (أكبر):** إشعاراتٌ تُرسل داخل معاملة الانتقال (`ApproveOutcomeTrack`، `ProposeOutcomeTrack::events`)؛
  وكتاباتٌ لكيانٍ ثانٍ داخل `apply()` (تذكرة/فاتورة/موعد) بلا سطر سجلٍّ ولا قفل؛ و`ConsultBooking::request`
  و`ExecFee::openOnAcceptance` خطوتان في معاملتين. تُعالَج في مرحلة «فكّ الحلقات» بخطّةٍ مستقلّة.

---

## 19. إسناد التذاكر: الحالة الآن وتحسينات مؤجّلة (2026-09-20)

### 19.1 ما استقرّ عليه العمل (قرار المالك 2026-09-20)
- **الفتح لا يُسنِد أحداً.** حُذف نداء الإسناد الأوّل من `TicketController::store`، ومعه `TicketAssignment::assign`.
- **الإسناد بشريّ:** الموظّف من `/employee/transfer` (صلاحيّة «تحويل التذاكر»)، والإدارة من `/admin/distribute`
  (صلاحيّة «توزيع التذاكر»)، ومنها زرّ التوزيع الجماعيّ (`distribute/auto` ← `AssignTicketJob` ← `pickLawyer`).
- **إحالة الموظّف بلا محامٍ** (`TicketTriage::referToLawyer`) تُصعّد للإدارة العليا وتُسنِدها لها مباشرةً.
- **شبكة الأمان:** `tickets:escalate-unassigned` كلّ ١٥ دقيقة تُسنِد ما تجاوز عمرُه `ticket_escalate_minutes`
  (الافتراض ١٢٠ دقيقة) للإدارة العليا مع إشعار وبريد لأصحاب قرار الإسناد.
- يحرس القرار `ManualAssignmentOnlyTest` و`LawyerAssignmentPolicyTest`.

### 19.2 ثغرات معروفة في هذا المسار — تُغلق أوّلاً
1. **لا إدارة ⇒ إحالة بلا صاحب ملفّ.** `EscalateUnassignedTicketJob` يعود بصمت إن لم يجد إداريّاً، فتمضي
   الإحالة بملخّصٍ بلا `lawyer_id` وبلا إشعار. المطلوب: رفض الإحالة برسالة «أسنِد محامياً أوّلاً» بدل المضيّ.
2. **الإحالة ليست ذرّية.** `referToLawyer` يكتب الملخّص ثمّ ينادي `ReferTicketToLawyer`؛ فشل الانتقال يترك
   الملخّص مكتوباً، ونقرتان متسارعتان قد تُنشئان ملخّصين. المطلوب: قفل التذكرة ومعاملة واحدة تضمّ الخطوتين.
3. **التصعيد يُسنِد لأقدم إداريّ دائماً** (`User::where('role', Admin)->orderBy('id')->first()`) — اختناقٌ عند
   إداريّ واحد. المطلوب: تناوب أو الأقلّ حملاً بين الإداريّين.
4. **المهلة بالساعة الجداريّة لا بساعات الدوام.** تذكرة مساء الخميس تُصعَّد ليلاً. وفي المشروع مفهوم أيّام
   العمل مستعمل في مهل التنفيذ (الجمعة والسبت عطلة) — يُعاد استعماله هنا.

### 19.3 تحسينات أكبر (بقرار المالك عند البدء)
- **صندوق وارد للمحامين (سحب بدل دفع):** قائمة غير المسند يسحب منها المحامي الملفّ لنفسه — يرفع الاختناق عن
  الإدارة ويمنع الإسناد لمن هو مشغول أو مجاز.
- **عدّاد مهلة ظاهر:** شاشة التوزيع تعرض **عدد** غير المسند فقط (`DistributeController` ~213) لا عمر كلّ تذكرة.
  عمرٌ ظاهر ولونُ تحذيرٍ قبل انقضاء المهلة يجعل الطاقم يسبق التصعيد.
- **سقف حمل وحالة توفّر:** `pickLawyer` يوازن الحمل بلا سقف، ولا يعرف الإجازات؛ المجاز يبقى مرشّحاً ما دام نشطاً.
- **سلّم تصعيد متدرّج** بدل قفزة واحدة: تنبيه ← رئيس القسم ← الإدارة العليا.
- **قياس:** متوسّط زمن الإسناد · عدد المُصعَّد أسبوعيّاً · أقدم تذكرة منتظِرة — بدونها لا يُعرف إن كانت
  الساعتان مهلةً مناسبة.
- **الإشعارات بعد اكتمال الحفظ** (البند البنيويّ نفسه في 18.6): إشعارٌ يسبق نجاح الحفظ يُخبر العميل بما لم يقع.

---

## 20. إزالة تكامل تقويم Google (2026-09-20)

**بقرار المالك أُزيل تكامل تقويم Google من المشروع كاملاً.** ما حُذف:

- `App\Services\GoogleCalendarService` وكلّ ندائه (`syncConsult` · `syncMeeting` ·
  `deleteConsultEvent` · `deleteMeetingEvent` · `deleteEvent`) من `Staff\MeetingController`
  و`Support\MeetInvitation` ومستمعي الرحلة الثلاثة.
- مفاتيح `services.google_calendar` و`GOOGLE_CALENDAR_ID`/`GOOGLE_CALENDAR_CREDENTIALS`
  (من `config/services.php` و`.env.example` و`phpunit.xml`). لم يعد للمشروع حساب خدمة جوجل
  ولا ملفّ اعتماد، ولا خطوة إعداد له في النشر.
- عمود `google_event_id` من `consults` و`meetings` و`appointments` — بمهاجرة
  `2026_09_20_000003_drop_google_event_id_from_calendared_models` لها `down()` يعيده.
  المهاجرة الأصليّة `2026_08_27_000002` باقية كما هي (المهاجرات تراكميّة).
- خاصّيّة `oldGoogleEventId` من حدث `ConsultRescheduled` ومن انتقال `RescheduleConsult`.
- `IcalendarService::googleUrl()` و`googleSchemaJsonLd()`، ومعها مفتاح `gcal` من كلّ حمولة
  (التقاويم الثلاثة · `TimelineCard` · `Appointment::toCard`) وزرّ «أضف إلى تقويم جوجل»
  من الواجهة كلّها.

**ما بقي عمداً:** بقيّة `IcalendarService` — توليد ملفّات `.ics` (`generate`) وتغذية الاشتراك
الحيّة (`feedForUser`) ومسار `calendar.feed` — معيار مفتوح (RFC 5545) يقرؤه أيّ برنامج تقويم،
ولا صلة له بجوجل. والتقاويم الداخليّة للأدوار الأربعة تعمل كما هي. وكذلك مفاتيح Gemini
(نموذج لغة من جوجل، لا تقويم) ومسارات متصفّح Chrome في مُصيِّر الـPDF.

---

## 21. إزالة وحدة «المخاطبات» (2026-09-20)

**بقرار المالك أُزيلت وحدة المخاطبات الرسميّة من المشروع كاملاً** — لا كوداً ولا واجهةً ولا
مساراً ولا صلاحيّةً ولا جدولاً ولا اختباراً. ما حُذف:

- الصفوف: `App\Models\Correspondence` · `CorrespondenceController` · `Support\CorrespondenceFlow`
  · `Support\CorrFlow` · `Events\CorrStatusBroadcast` · `Services\ExternalSystemService`
  (محوّل «النظام الخارجيّ» لم يكن له مستهلكٌ سواها).
- المسارات الـ21 (`/correspondences/*` للمحامي والإدارة، و`/mycorr` و`request-brief`
  و`brief.pdf` و`letter.pdf` للعميل)، وقناة البثّ `corr.{id}` من `routes/channels.php`.
- الواجهة: `pages/correspondence(s).tsx` · `pages/mycorr.tsx` · `lib/corr-ui.ts`، وبنودها من
  `lib/data.ts` (التنقّل والعناوين وخريطة المسارات) وتبويب المخاطبات في لوحة المحامي وبطاقة
  «المخاطبات المرتبطة بالملفّ» في `execflow.tsx`. وملفّات Wayfinder المولَّدة أُعيد توليدها
  بـ`php artisan wayfinder:generate` (لا تُحرَّر يدويّاً).
- زرّ «طلب مخاطبة» في ملفّ التنفيذ مع `ExecService::requestCorr` وإجراء `requestCorr` في
  `ExecFlowController::act` وعلاقة `Execution::correspondences()` وحمولة `linkedCorr`.
  **ولم تُمسّ مصفوفات مراحل التنفيذ:** المخاطبة لم تكن مرحلةً مرقّمة بل إجراءً مفتوحاً على
  الملفّ العامل (7‑8)، فلم يُزَح ترقيمُ شيء.
- صلاحيّة «المخاطبات» من `Support\Permissions` (المجموعات و`ROLE_PERMISSIONS` والقوالب —
  صارت 27 صلاحيّة)، ومن القاعدة بمهاجرة `2026_09_20_000002_drop_correspondence_permission`
  (spatie تخزّن الإسناد، و`PermissionSeeder` يُنشئ ولا يحذف).
- الجدول `correspondences` بمهاجرة `2026_09_20_000001_drop_correspondences_table` لها `down()`
  يعيد بناءه. المهاجرة الأصليّة `2026_07_31_000001` باقية كما هي (المهاجرات تراكميّة).
- مفاتيح `services.external_corr` و`EXTCORR_*` من `config/services.php` و`.env.example`،
  وبادئة `MKH` من توثيق `Support\ReferenceNumber`، وبذرة المخاطبات الستّ من `RichDemoSeeder`.
- الاختبارات: `CorrespondenceTest` كاملاً، وشقّ «إثبات الإرسال» من
  `ResultAndDispatchHonestyTest`، وقسم «ردّ الجهة الحكوميّة» من `AiClaimHonestyTest`، وحالاتٌ
  مفردة من `StageIntegrityTest` و`ReferenceNumberTest` و`AdminProductGapsTest`
  و`EmployeeParityTest` و`EmployeeSeesEverythingTest` و`ExecFlowFixesTest`.
- نصّ التسويق المعلِن لميزةٍ لم تعد موجودة: بطاقة «مخاطباتي» و«المخاطبات الرسمية» من
  `components/landing/content.ts` و`pages/auth/login.tsx`.

**ما بقي عمداً:** الخدمة القانونيّة **«مخاطبة الجهات الحكومية»** في
`database/data/legal_catalogue.php` — خدمةٌ يبيعها المكتب في كتالوج الخدمات، لا علاقة لها
بالوحدة البرمجيّة المحذوفة.

---

## 22. المرحلة م٠ من الخطّة الماليّة: تصحيح الأرقام الكاذبة (2026-09-20)

**مصدرٌ واحد للدخل.** `app/Support/Finance/RevenueSnapshot.php` هو المصدر الوحيد لأرقام
`/admin/revenue` و`/admin/revenue.pdf` معاً — كان الحساب مكتوباً مرّتين حرفيّاً في
`ReportController::revenue()` و`revenuePdf()`. **إجمالي الدخل = مجموع الفواتير المدفوعة**،
والتصنيف (استشارات · قضايا · تنفيذ · غير مصنَّف) **تقسيمٌ** لذلك المجموع بحسب
`consult_id`/`case_id`/`exec_id` على الفاتورة. وكان يجمع ثلاثة مصادر متقاطعة فيَعُدّ
الاستشارة مرّتين والتنفيذ مرّتين.

**مؤشّرات حُذفت — تعود بعد م١ (حين تحمل `invoices` عمود `paid_at` فتصير التصفية بالفترة ممكنة):**

- **«صافي التدفق بعد الرواتب»** (`netCashFlow`) — من الشاشة ومن كتلة PDF الأولى. كان يطرح
  رواتب **شهرٍ واحد** من إيراد **العمر كلّه**.
- **«تغطية الرواتب من الدخل المحصل»** (نسبة مئويّة في بطاقة الرواتب) — عينُ العطل نفسه بصيغة
  نسبة: إيراد العمر كلّه ÷ رواتب شهر.

**أسماء حمولة الشاشة تغيّرت:** `collected`/`totalFirmGross` ⇐ `totalIncome`، و`bookingRevenue`
⇐ `consultIncome`، وأُضيفت `caseIncome` و`execIncome` و`otherIncome` و`unbilledPaidConsults`،
وحُذفت `totalExecFees` و`netCashFlow`.

**الاستشارة المسدَّدة بلا فاتورة** لا تدخل الدخل ويُعرض عددها على الشاشة
(`unbilledPaidConsults`): مسار الإنتاج الوحيد الذي يكتب `consults.paid_at` هو الانتقال
`SettlePayment` وهو يعلّم فاتورةً مدفوعةً في الحركة نفسها، فوجودها خللُ بيانات (بذورُ عرض) لا
دخلٌ يملك المكتب إثباته. **وفي قاعدة التطوير اليوم صفٌّ واحد كهذا.**

**وحدة دفتر المدفوعات:** `payments.amount_halalas` (مهاجرة
`2026_09_20_000004_add_amount_halalas_to_payments`) **بالهللة دائماً**، وهو وحده القابل للجمع.
العمود القديم `payments.amount` **يبقى كما هو حرفاً** (البوّابة بالهللة، التحصيل اليدويّ
بالريال) — لقطةُ ما وردت ومرجعُ اختباراتٍ قائمة. **ولم يُنقل أيّ قارئ إلى العمود الجديد بعد**؛
النقل حفظٌ لاحق.

---
---
_نهاية الوثيقة — كل الأقسام مُجمّعة من قراءة فعليّة للكود. للتحقّق: شغّل `artisan test` + `queue:work` +
`schedule:run`، واستخدم `AUTH_DEV_OTP` للدخول الحيّ. آخر تحديث: **2026-09-19**._

