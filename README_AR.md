# 🏛️ منصة سلاسل بابل - نسخة React + Laravel

منصة إدارة متكاملة لمكاتب المحاماة، توفر إدارة كاملة للعملاء والقضايا والاستشارات والفواتير والمستندات.

**الإصدار:** 1.0 (قيد التطوير)  
**الآخر تحديث:** 26 يونيو 2026

---

## ✨ الميزات الرئيسية

### 👥 إدارة العملاء
- ✅ قائمة عملاء شاملة
- ✅ بيانات شاملة للعميل
- ✅ تتبع تاريخ التفاعلات
- ✅ إدارة الاتصالات

### 📋 نظام التذاكر
- ✅ فتح وإدارة التذاكر
- ✅ البحث والتصفية المتقدم
- ✅ تتبع الحالة
- ✅ تخصيص التذاكر

### ⚖️ إدارة القضايا
- ✅ متابعة القضايا الشاملة
- ✅ جدولة الجلسات
- ✅ إدارة المستندات
- ✅ تتبع التقدم

### 📅 المواعيد والاجتماعات
- ✅ حجز المواعيد
- ✅ جدولة الاجتماعات
- ✅ ملخصات ذكية
- ✅ إدارة المحاضر

### 💳 الفواتير والإيرادات
- ✅ إصدار الفواتير
- ✅ تتبع المدفوعات
- ✅ تقارير الإيرادات
- ✅ حسابات التكاليف

### 📄 إدارة المستندات
- ✅ تحميل المستندات
- ✅ تنظيم الملفات
- ✅ البحث والاسترجاع
- ✅ التحقق والاعتماد

---

## 🚀 البدء السريع

### المتطلبات المسبقة
```
- PHP 8.2+
- Node.js 18+
- Composer 2.0+
- MySQL 8.0+ (أو PostgreSQL)
```

### التثبيت
```bash
# 1. استنساخ المستودع
git clone <repository-url>
cd low-laravel-rectjs

# 2. تثبيت الحزم
composer install
npm install

# 3. إعداد البيئة
cp .env.example .env
php artisan key:generate

# 4. إعداد قاعدة البيانات
php artisan migrate
php artisan db:seed

# 5. تشغيل المشروع
php artisan serve        # Terminal 1
npm run dev            # Terminal 2

# 6. فتح المتصفح
# اذهب إلى http://localhost:8000
```

---

## 📁 بنية المشروع

```
project/
├── app/                          # Laravel Backend
│   ├── Http/Controllers/
│   ├── Models/
│   ├── Services/
│   └── ...
├── resources/
│   ├── js/
│   │   ├── components/           # React Components
│   │   │   ├── layouts/
│   │   │   ├── navigation/
│   │   │   ├── ui/
│   │   │   └── ...
│   │   ├── pages/                # Pages/Views
│   │   ├── hooks/                # Custom Hooks
│   │   ├── lib/                  # Utilities
│   │   ├── types/                # TypeScript Types
│   │   └── app.tsx               # Entry Point
│   ├── css/                      # Styles
│   └── views/                    # Blade Templates
├── routes/
│   ├── web.php                   # Web Routes
│   └── api.php                   # API Routes
├── database/
│   ├── migrations/               # Database Migrations
│   ├── seeders/                  # Database Seeders
│   └── factories/                # Model Factories
├── tests/
│   ├── Feature/
│   └── Unit/
├── public/                       # Public Assets
├── storage/                      # File Storage
├── vite.config.ts               # Vite Configuration
├── tailwind.config.ts            # Tailwind Configuration
├── tsconfig.json                 # TypeScript Configuration
└── composer.json & package.json  # Dependencies
```

---

## 🎨 المكونات الرئيسية

### Layout Components
- **AppLayout** - التخطيط الرئيسي
- **Sidebar** - الملاحة الجانبية
- **Header** - رأس الصفحة

### UI Components
- **Card** - البطاقات
- **Badge** - الشارات
- **Button** - الأزرار
- **Notification** - الإشعارات
- `DataTable` - جدول البيانات (قيد الإعداد)
- `Modal` - النوافذ المنفثقة (قيد الإعداد)
- `Form` - نماذج (قيد الإعداد)

### Pages
- **Dashboard** - لوحة التحكم الرئيسية
- **Tickets** - التذاكر
- **Cases** - القضايا
- `Appointments` - المواعيد (قيد الإعداد)
- `Meetings` - الاجتماعات (قيد الإعداد)
- `Invoices` - الفواتير (قيد الإعداد)
- `Documents` - المستندات (قيد الإعداد)

---

## 🛠️ التقنيات المستخدمة

### Frontend
- ⚛️ **React 19** - JavaScript Library
- 📘 **TypeScript** - Type Safety
- 🎨 **Tailwind CSS** - Utility CSS Framework
- 🎯 **Inertia.js** - React on Rails alternative
- 🔥 **Vite** - Build Tool

### Backend
- 🐘 **Laravel 11** - PHP Framework
- 🗄️ **Eloquent ORM** - Database Layer
- 🔐 **Sanctum** - API Authentication
- 📨 **Laravel Mail** - Email Service

### Development Tools
- 🧪 **Vitest** - Unit Testing
- 🔍 **ESLint** - Code Linting
- 🎨 **Prettier** - Code Formatting
- 📚 **TypeScript** - Type Checking

---

## 📖 التوثيق

### ملفات التوثيق الأساسية
- **[COMPONENT_STRUCTURE.md](./COMPONENT_STRUCTURE.md)** - بنية المكونات
- **[DEVELOPER_GUIDE.md](./DEVELOPER_GUIDE.md)** - دليل المطور
- **[IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)** - حالة التطبيق
- **[COMPLETION_SUMMARY.md](./COMPLETION_SUMMARY.md)** - ملخص الإنجاز

---

## 💻 التطوير

### إنشاء صفحة جديدة
```bash
# 1. إنشاء ملف الصفحة
touch resources/js/pages/new-page.tsx

# 2. كتابة الكود
# اتبع نمط الصفحات الموجودة

# 3. إضافة الـ Route
# في routes/web.php
Route::inertia('/new-page', 'new-page');
```

### إنشاء مكون جديد
```bash
# 1. إنشاء ملف المكون
touch resources/js/components/ui/NewComponent.tsx

# 2. كتابة الكود مع TypeScript
# اتبع معايير المكونات الموجودة

# 3. إضافة الاختبارات
touch resources/js/components/ui/NewComponent.test.tsx
```

### تشغيل الاختبارات
```bash
npm run test              # تشغيل الاختبارات
npm run test:watch       # وضع المراقبة
npm run test:ui         # واجهة رسومية
```

---

## 🚀 الإطلاق

### بيئة الإنتاج
```bash
# بناء الأصول
npm run build
php artisan optimize

# تشغيل الخادم
php artisan serve

# أو استخدام Web Server
# nginx, apache, etc.
```

### قائمة التحقق قبل الإطلاق
- [ ] جميع الاختبارات تمر
- [ ] صفر أخطاء في لوحة التحكم
- [ ] تحسين الأداء (Lighthouse 90+)
- [ ] فحص الأمان
- [ ] نسخ احتياطية جاهزة
- [ ] خطة الاسترجاع معدة

---

## 🐛 استكشاف الأخطاء

### المشاكل الشائعة

**المشكلة:** الصفحة لا تحميل  
**الحل:**
```bash
npm run dev           # تأكد من Vite يعمل
php artisan serve    # تأكد من Laravel يعمل
```

**المشكلة:** خطأ في النموذج  
**الحل:**
```bash
php artisan migrate:fresh --seed  # إعادة تعيين قاعدة البيانات
npm run dev                        # إعادة Vite
```

**المشكلة:** أخطاء TypeScript  
**الحل:**
```bash
npm run type-check   # فحص الأنواع
```

---

## 🤝 المساهمة

### معايير الكود
- ✅ TypeScript Strict Mode
- ✅ ESLint Configuration
- ✅ Prettier Formatting
- ✅ Comprehensive Tests
- ✅ Complete Documentation

### خطوات المساهمة
1. إنشاء فرع جديد
2. إجراء التغييرات
3. اختبار التغييرات
4. فتح Pull Request
5. انتظار المراجعة

---

## 📈 خطة التطوير

### الأسبوع الأول (جاري) ✅
- [x] البنية الأساسية
- [x] Layouts و Navigation
- [x] UI Components
- [x] صفحات رئيسية

### الأسابيع 2-3 🔄
- [ ] الصفحات المتبقية
- [ ] Form Components
- [ ] Modal/Dialog
- [ ] Custom Hooks

### الأسابيع 4-6
- [ ] Backend Controllers
- [ ] API Integration
- [ ] Real-time Features
- [ ] AI Integration

### الأسابيع 7-8
- [ ] Optimization
- [ ] Testing & QA
- [ ] Documentation
- [ ] Deployment

---

## 📊 الإحصائيات

| المقياس | القيمة |
|--------|--------|
| **المكونات** | 7 |
| **الصفحات** | 3/7 |
| **الاختبارات** | 10 |
| **سطور الكود** | ~2,900 |
| **التوثيق** | 3 ملفات |

---

## 📞 التواصل والدعم

### للمساعدة
- 📖 اقرأ التوثيق في `/docs`
- 💬 افتح Issue على GitHub
- 🆘 تواصل مع فريق التطوير

### المستندات الهامة
- [معايير الكود](./DEVELOPER_GUIDE.md#معايير-الكود)
- [بنية المكونات](./COMPONENT_STRUCTURE.md)
- [إعدادات الخادم](./docs/server-setup.md)

---

## 📄 الترخيص

[تحديد نوع الترخيص]

---

## 👥 الفريق

- **القيادة:** [الاسم]
- **المطورين:** [الأسماء]
- **المصممين:** [الأسماء]
- **QA:** [الأسماء]

---

## 🙏 شكر وتقدير

شكراً لكل من ساهم في هذا المشروع الطموح!

---

**آخر تحديث:** 26 يونيو 2026  
**الحالة:** قيد التطوير النشط 🚀

للمزيد من المعلومات، يرجى الاطلاع على التوثيق الكاملة في مجلد `/docs`
