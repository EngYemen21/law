# بنية المكونات - منصة سلاسل بابل

**تاريخ الإنشاء:** 26 يونيو 2026  
**الإطار:** React + Laravel + Inertia.js + TypeScript + Tailwind CSS

---

## 📋 هيكل المشروع

```
resources/js/
├── components/
│   ├── layouts/
│   │   └── AppLayout.tsx          # التخطيط الرئيسي للتطبيق
│   ├── navigation/
│   │   ├── Sidebar.tsx             # الشريط الجانبي
│   │   └── Header.tsx              # رأس الصفحة
│   └── ui/
│       ├── Card.tsx                # مكون البطاقة
│       ├── Badge.tsx               # مكون الشارة
│       ├── Button.tsx              # مكون الزر
│       └── Notification.tsx        # مكون الإشعار
├── pages/
│   ├── dashboard.tsx               # صفحة لوحة التحكم
│   ├── tickets.tsx                 # صفحة التذاكر
│   ├── cases.tsx                   # صفحة القضايا
│   ├── appointments.tsx            # صفحة المواعيد
│   ├── meetings.tsx                # صفحة الاجتماعات
│   ├── invoices.tsx                # صفحة الفواتير
│   └── documents.tsx               # صفحة المستندات
├── hooks/                          # Custom Hooks
├── lib/
│   └── utils.ts                    # دوال مساعدة
├── types/
│   └── index.ts                    # تعريفات TypeScript
└── app.tsx                         # نقطة الدخول الرئيسية
```

---

## 🎨 نظام التصميم

### 1. الألوان الأساسية

```typescript
// Primary: Blue
- light: #E0E7FF
- main: #0e5c9c (Blue-600)
- dark: #1e3a8a

// Success: Green
- light: #DCFCE7
- main: #16A34A
- dark: #15803D

// Warning: Yellow/Orange
- light: #FEF3C7
- main: #F59E0B
- dark: #D97706

// Danger: Red
- light: #FEE2E2
- main: #EF4444
- dark: #DC2626
```

### 2. التباعد والأحجام

```typescript
// Padding
- sm: 0.5rem (4px)
- md: 1rem (8px)
- lg: 1.5rem (12px)

// Border Radius
- sm: 0.375rem (3px)
- md: 0.5rem (4px)
- lg: 0.75rem (6px)

// Font Sizes
- xs: 0.75rem (12px)
- sm: 0.875rem (14px)
- base: 1rem (16px)
- lg: 1.125rem (18px)
- xl: 1.25rem (20px)
- 2xl: 1.5rem (24px)
- 3xl: 1.875rem (30px)
```

---

## 🧩 المكونات الأساسية

### 1. AppLayout
**الملف:** `components/layouts/AppLayout.tsx`

المسؤولية: التخطيط الرئيسي الذي يوفر الهيكل الأساسي لجميع الصفحات

**الميزات:**
- ✅ Responsive (سطح مكتب، لوحة، جوال)
- ✅ نظام التحكم بالـ sidebar
- ✅ منطقة للإشعارات
- ✅ دعم الوضع الداكن

**الـ Props:**
```typescript
interface AppLayoutProps {
  children: React.ReactNode;
}
```

**الاستخدام:**
```tsx
<AppLayout>
  {/* Page Content */}
</AppLayout>
```

---

### 2. Sidebar
**الملف:** `components/navigation/Sidebar.tsx`

المسؤولية: الملاحة الرئيسية للتطبيق

**الميزات:**
- ✅ عناصر ملاحة متعددة المستويات
- ✅ توسيع/طي العناصر الفرعية
- ✅ شارات للإشعارات (Badges)
- ✅ تمييز الصفحة النشطة
- ✅ قابل للإغلاق في الأجهزة الصغيرة

**البنية:**
```typescript
[
  { label: 'الرئيسية', icon: '🏠', href: '/dashboard' },
  { label: 'التذاكر', icon: '📋', href: '/tickets', badge: 3 },
  { 
    label: 'الإدارة', 
    icon: '⚙️',
    children: [
      { label: 'العملاء', icon: '👥', href: '/admin/clients' },
      // ...
    ]
  }
]
```

---

### 3. Header
**الملف:** `components/navigation/Header.tsx`

المسؤولية: رأس الصفحة مع البحث والإشعارات والملف الشخصي

**الميزات:**
- ✅ شريط بحث عام
- ✅ إشعارات مع Dropdown
- ✅ قائمة المستخدم
- ✅ زر التحكم بـ sidebar

**المحتويات:**
```typescript
- Search Input
- Notifications (مع dropdown)
- User Menu (Profile, Settings, Logout)
- Sidebar Toggle
```

---

### 4. Card
**الملف:** `components/ui/Card.tsx`

مكون بطاقة معاد الاستخدام لعرض المحتوى

**الـ Props:**
```typescript
interface CardProps {
  children: React.ReactNode;
  className?: string;
  hoverable?: boolean;        // تأثير عند التحويم
  padding?: 'sm' | 'md' | 'lg';
}
```

**أمثلة:**
```tsx
// بطاقة بسيطة
<Card>
  <h3>العنوان</h3>
  <p>المحتوى</p>
</Card>

// بطاقة قابلة للنقر
<Card hoverable>
  {/* Content */}
</Card>

// بطاقة بتباعد مخصص
<Card padding="lg">
  {/* Large padding */}
</Card>
```

---

### 5. Badge
**الملف:** `components/ui/Badge.tsx`

شارة لتمييز الحالات والتصنيفات

**الـ Props:**
```typescript
interface BadgeProps {
  children: React.ReactNode;
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info';
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}
```

**الأمثلة:**
```tsx
<Badge variant="success">مكتملة</Badge>
<Badge variant="warning">قيد الانتظار</Badge>
<Badge variant="danger">عاجل</Badge>
<Badge variant="info" size="lg">معلومة</Badge>
```

---

### 6. Button
**الملف:** `components/ui/Button.tsx`

زر معاد الاستخدام مع تنسيقات متعددة

**الـ Props:**
```typescript
interface ButtonProps {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  isLoading?: boolean;
  icon?: React.ReactNode;
  iconPosition?: 'left' | 'right';
  // ... HTML button props
}
```

**الأمثلة:**
```tsx
<Button variant="primary">حفظ</Button>
<Button variant="danger">حذف</Button>
<Button variant="ghost">إلغاء</Button>
<Button icon={<Plus />}>إضافة</Button>
<Button isLoading>جارٍ...</Button>
```

---

### 7. Notification
**الملف:** `components/ui/Notification.tsx`

إشعار قابل للرفع والإغلاق التلقائي

**الـ Props:**
```typescript
interface NotificationProps {
  type?: 'success' | 'error' | 'warning' | 'info';
  title?: string;
  message: string;
  duration?: number;  // ms
  onClose: () => void;
}
```

**الاستخدام:**
```tsx
<Notification
  type="success"
  title="نجح"
  message="تم حفظ البيانات بنجاح"
  onClose={() => {}}
/>
```

---

## 📄 الصفحات

### 1. Dashboard (لوحة التحكم)
**الملف:** `pages/dashboard.tsx`

**الأقسام:**
- ✅ إحصائيات المؤشرات الرئيسية (4 بطاقات)
- ✅ النشاطات الأخيرة (من جانب واحد)
- ✅ المواعيد القادمة (من جانب واحد)
- ✅ إجراءات سريعة (4 بطاقات)

**البيانات المعروضة:**
```typescript
- التذاكر المفتوحة: 12 (↑ 3)
- القضايا النشطة: 5 (↓ 1)
- الفواتير المستحقة: 8500
- المواعيد القادمة: 3
```

---

### 2. Tickets (التذاكر)
**الملف:** `pages/tickets.tsx`

**الميزات:**
- ✅ بحث متقدم
- ✅ تصفية حسب الحالة والأولوية
- ✅ قائمة التذاكر مع البطاقات
- ✅ إحصائيات سفلية

**الحقول:**
```typescript
- الرقم (SB-2026-1042)
- النوع (نزاع تجاري، قضية عمالية، إلخ)
- القسم
- الحالة
- الأولوية
- آخر رسالة
- تاريخ آخر تحديث
```

---

### 3. Cases (القضايا)
**الملف:** `pages/cases.tsx`

**العناصر:**
- ✅ بطاقات قضايا في تخطيط شبكة 2×2
- ✅ معلومات القضية الأساسية
- ✅ اسم المحامي المختص
- ✅ الجلسة القادمة
- ✅ عدد المستندات والفاتورة
- ✅ إحصائيات عامة

**البيانات:**
```typescript
- رقم القضية (ق-2026-0211)
- النوع
- الحالة
- المحامي
- الجلسة القادمة
- عدد المستندات
```

---

## 🔌 Hooks و Utilities

### الـ Hooks المخطط إنشاؤها:

```typescript
// useSearch.ts
useSearch(items, searchTerm, searchFields)
// البحث والتصفية

// useFilter.ts
useFilter(items, filters)
// تطبيق مرشحات متعددة

// usePagination.ts
usePagination(items, itemsPerPage)
// تقسيم البيانات لصفحات

// useLocalStorage.ts
useLocalStorage(key, initialValue)
// تخزين في localStorage

// useFetch.ts
useFetch(url, options)
// استدعاء API
```

---

## 🎯 معايير الكود

### TypeScript
- ✅ استخدام أنواع قوية دائماً
- ✅ تجنب `any`
- ✅ interfaces للـ Props

### React
- ✅ Functional Components فقط
- ✅ Hooks للـ state management
- ✅ Memoization عند الحاجة

### CSS / Tailwind
- ✅ Utility-first approach
- ✅ Responsive design (mobile-first)
- ✅ Dark mode support

### التسمية
- ✅ PascalCase للـ Components
- ✅ camelCase للـ functions و variables
- ✅ UPPER_SNAKE_CASE للـ constants

---

## 🚀 الخطوات التالية

### الصفحات المتبقية:

1. **Appointments** (`pages/appointments.tsx`)
   - قائمة المواعيد
   - نموذج حجز موعد
   - تقويم المواعيد

2. **Meetings** (`pages/meetings.tsx`)
   - قائمة الاجتماعات
   - تفاصيل الاجتماع
   - ملخصات الاجتماعات

3. **Invoices** (`pages/invoices.tsx`)
   - قائمة الفواتير
   - تفاصيل الفاتورة
   - نموذج دفع

4. **Documents** (`pages/documents.tsx`)
   - إدارة المستندات
   - تحميل ملفات
   - معاينة المستندات

5. **Admin Pages**
   - `pages/admin/clients.tsx`
   - `pages/admin/lawyers.tsx`
   - `pages/admin/employees.tsx`
   - `pages/admin/reports.tsx`

### الميزات المتقدمة:

1. **عمليات CRUD** (Create, Read, Update, Delete)
2. **التحقق من صحة البيانات** (Form Validation)
3. **معالجة الأخطاء** (Error Handling)
4. **التحميل والحالات** (Loading States)
5. **Modals و Dialogs** (لتأكيد الإجراءات)
6. **Export to PDF/Excel**
7. **Real-time Updates** (WebSockets)

---

## 📚 المراجع

- [React Documentation](https://react.dev)
- [Tailwind CSS Docs](https://tailwindcss.com/docs)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/)
- [Inertia.js Guide](https://inertiajs.com/guide)
- [Lucide Icons](https://lucide.dev)

---

## ✅ Checklist للتطوير

- [x] Layout الأساسي
- [x] Navigation (Sidebar + Header)
- [x] UI Components (Card, Badge, Button, Notification)
- [x] Dashboard Page
- [x] Tickets Page
- [x] Cases Page
- [ ] Remaining Pages
- [ ] Form Components & Validation
- [ ] Modal/Dialog Components
- [ ] API Integration
- [ ] Error Handling
- [ ] Loading States
- [ ] Testing
- [ ] Documentation

---

**آخر تحديث:** 26 يونيو 2026
