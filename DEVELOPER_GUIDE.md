# دليل المطور - منصة سلاسل بابل

**للمطورين الجدد على المشروع**

---

## 🚀 البدء السريع

### 1. تثبيت الحزم
```bash
cd "C:\Users\a\Herd\low-laravel-rectjs"
composer install
npm install
```

### 2. إعداد المتغيرات
```bash
cp .env.example .env
php artisan key:generate
```

### 3. تشغيل المشروع
```bash
# نافذة 1: Laravel
php artisan serve

# نافذة 2: Vite (تطوير الـ assets)
npm run dev
```

### 4. فتح التطبيق
```
http://localhost:8000
```

---

## 📂 هيكل الملفات

### React Components
```
resources/js/
├── components/
│   ├── layouts/          # التخطيطات الرئيسية
│   ├── navigation/       # الملاحة (Sidebar, Header)
│   └── ui/              # مكونات UI معاد استخدامها
├── pages/               # صفحات التطبيق
├── hooks/              # Custom React Hooks
├── lib/                # دوال مساعدة
├── types/              # تعريفات TypeScript
└── app.tsx             # نقطة الدخول
```

### Laravel Backend
```
app/
├── Http/
│   ├── Controllers/    # معالجات الطلبات
│   ├── Requests/       # validation classes
│   └── Resources/      # API responses
├── Models/             # نماذج قاعدة البيانات
└── Services/           # Business logic
```

---

## 🎨 نظام المكونات

### استيراد المكونات
```typescript
// UI Components
import Card from '@/components/ui/Card';
import Badge from '@/components/ui/Badge';
import Button from '@/components/ui/Button';

// Layout
import AppLayout from '@/components/layouts/AppLayout';

// Navigation
import Sidebar from '@/components/navigation/Sidebar';
import Header from '@/components/navigation/Header';
```

### استخدام المكونات

#### Card
```tsx
<Card padding="md" hoverable>
  <h2>العنوان</h2>
  <p>المحتوى</p>
</Card>
```

#### Badge
```tsx
<Badge variant="success" size="md">
  مكتملة
</Badge>
```

#### Button
```tsx
<Button 
  variant="primary" 
  size="md"
  icon={<PlusIcon />}
  onClick={() => {}}
>
  إضافة
</Button>
```

#### Notification
```tsx
<Notification
  type="success"
  title="نجح"
  message="تم الحفظ بنجاح"
  duration={3000}
  onClose={() => {}}
/>
```

---

## 🎯 إنشاء صفحة جديدة

### الخطوة 1: إنشاء الملف
```bash
# Bash
touch resources/js/pages/invoices.tsx
```

### الخطوة 2: البنية الأساسية
```typescript
import React from 'react';
import Card from '@/components/ui/Card';
import Badge from '@/components/ui/Badge';
import Button from '@/components/ui/Button';

const Invoices: React.FC = () => {
  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
          الفواتير
        </h1>
        <p className="text-gray-600 dark:text-gray-400">
          إدارة الفواتير والمدفوعات
        </p>
      </div>

      {/* Main Content */}
      <Card>
        {/* Your content here */}
      </Card>
    </div>
  );
};

export default Invoices;
```

### الخطوة 3: تسجيل الصفحة
```typescript
// في routes/web.php (Laravel) أو Inertia config
Route::inertia('/invoices', 'invoices');
```

---

## 🔧 إنشاء مكون مخصص

### مثال: مكون DataTable
```typescript
// components/ui/DataTable.tsx
import React from 'react';
import clsx from 'clsx';

interface Column<T> {
  key: keyof T;
  label: string;
  render?: (value: T[keyof T], row: T) => React.ReactNode;
}

interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  keyField: keyof T;
  loading?: boolean;
}

const DataTable = <T extends Record<string, any>>({
  columns,
  data,
  keyField,
  loading = false,
}: DataTableProps<T>) => {
  if (loading) {
    return <div>جارٍ التحميل...</div>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full">
        <thead>
          <tr className="border-b border-gray-200 dark:border-gray-700">
            {columns.map((col) => (
              <th
                key={String(col.key)}
                className="px-6 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white"
              >
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row) => (
            <tr
              key={String(row[keyField])}
              className="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800"
            >
              {columns.map((col) => (
                <td
                  key={String(col.key)}
                  className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"
                >
                  {col.render
                    ? col.render(row[col.key], row)
                    : String(row[col.key])}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default DataTable;
```

### الاستخدام
```typescript
<DataTable
  columns={[
    { key: 'number', label: 'الرقم' },
    { 
      key: 'status', 
      label: 'الحالة',
      render: (value) => <Badge>{value}</Badge>
    }
  ]}
  data={invoices}
  keyField="id"
/>
```

---

## 🔌 العمل مع API (Laravel)

### إنشاء Controller
```php
// app/Http/Controllers/TicketController.php
namespace App\Http\Controllers;

use App\Models\Ticket;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::with(['client', 'assignedTo'])
            ->latest()
            ->paginate(15);

        return Inertia::render('tickets', [
            'tickets' => $tickets,
        ]);
    }

    public function store(StoreTicketRequest $request)
    {
        $ticket = Ticket::create($request->validated());

        return redirect()
            ->route('tickets.index')
            ->with('success', 'تم إنشاء التذكرة بنجاح');
    }
}
```

### التسجيل في الـ Routes
```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::resource('tickets', TicketController::class);
    Route::resource('cases', CaseController::class);
    Route::resource('invoices', InvoiceController::class);
});
```

### استقبال البيانات في React
```typescript
import { usePage } from '@inertiajs/react';

const Tickets: React.FC = () => {
  const { tickets } = usePage().props;

  return (
    <div>
      {tickets.data.map((ticket) => (
        <Card key={ticket.id}>
          <h3>{ticket.number}</h3>
          {/* ... */}
        </Card>
      ))}
    </div>
  );
};
```

---

## ⌨️ معايير الكود

### TypeScript - استخدام الأنواع دائماً
```typescript
// ✅ صحيح
interface User {
  id: number;
  name: string;
  role: 'admin' | 'user' | 'lawyer';
}

const handleUser = (user: User): void => {
  console.log(user.name);
};

// ❌ خطأ - تجنب any
const handleUser = (user: any) => {
  console.log(user.name);
};
```

### React - استخدام Functional Components
```typescript
// ✅ صحيح
const MyComponent: React.FC<Props> = ({ title }) => {
  return <div>{title}</div>;
};

// ❌ خطأ - تجنب Class Components
class MyComponent extends React.Component {
  render() {
    return <div>{this.props.title}</div>;
  }
}
```

### Tailwind - استخدام Utility Classes
```typescript
// ✅ صحيح
<div className="flex items-center gap-4 p-4 bg-white rounded-lg shadow-md">
  Content
</div>

// ❌ خطأ - تجنب CSS في ملفات منفصلة
<style>
  .my-div {
    display: flex;
    padding: 1rem;
  }
</style>
```

### التسمية
```typescript
// Components
MyComponent.tsx, HeaderNav.tsx, UserCard.tsx

// Functions
getUserData(), formatDate(), calculateTotal()

// Constants
const MAX_ITEMS = 10;
const API_ENDPOINT = '/api/users';

// Variables & State
const [userName, setUserName] = useState('');
const totalAmount = calculateTotal();
```

---

## 🧪 الاختبار

### اختبار المكون
```typescript
// components/ui/__tests__/Button.test.tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Button from '../Button';

describe('Button', () => {
  it('renders correctly', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
  });

  it('calls onClick when clicked', async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Click</Button>);
    
    await userEvent.click(screen.getByText('Click'));
    expect(onClick).toHaveBeenCalled();
  });
});
```

### تشغيل الاختبارات
```bash
npm run test
npm run test:watch   # وضع المراقبة
npm run test:ui      # واجهة رسومية
```

---

## 🐛 تصحيح الأخطاء

### استخدام React DevTools
1. ثبّت [React DevTools](https://react.dev/learn/react-developer-tools)
2. افتح DevTools (F12)
3. اذهب لـ Components tab
4. تفقد props و state

### استخدام Vite Debug
```typescript
// في أي مكان في الكود
console.log('Value:', myValue);
debugger; // سيتوقف هنا عند تشغيل DevTools
```

### Logging في Laravel
```php
// في Controllers أو Models
Log::info('User action', [
    'user_id' => $user->id,
    'action' => 'ticket_created'
]);
```

---

## 📦 الحزم المهمة

### UI & Styling
```json
{
  "react": "^19.2.0",
  "react-dom": "^19.2.0",
  "tailwindcss": "^4.0.0",
  "clsx": "^2.1.1",
  "tailwind-merge": "^3.0.1"
}
```

### Icons
```bash
npm install lucide-react
```

```typescript
import { Plus, Trash2, Edit3 } from 'lucide-react';

<Button icon={<Plus size={20} />}>إضافة</Button>
```

### Forms
```bash
npm install react-hook-form
```

```typescript
import { useForm } from 'react-hook-form';

const { register, handleSubmit } = useForm();

<form onSubmit={handleSubmit(onSubmit)}>
  <input {...register('email')} />
</form>
```

---

## 📝 الاتجاهات الأفضل

### ✅ افعل
1. اكتب TypeScript قوي
2. استخدم مكونات معاد استخدامها
3. اختبر الميزات الجديدة
4. وثق الكود المعقد
5. التزم بمعايير الفريق

### ❌ لا تفعل
1. استخدم `any` أو `unknown`
2. اكتب مكونات ضخمة (>400 سطر)
3. اضف الأنماط المضمنة
4. تجاهل الأداء
5. نسي الوضع الداكن

---

## 🔗 روابط مفيدة

- [React Docs](https://react.dev)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/)
- [Tailwind CSS](https://tailwindcss.com)
- [Inertia.js](https://inertiajs.com)
- [Laravel Docs](https://laravel.com/docs)
- [Lucide Icons](https://lucide.dev)

---

## ❓ الأسئلة الشائعة

**س: كيف أضيف صفحة جديدة؟**
ج: اتبع خطوات "إنشاء صفحة جديدة" أعلاه

**س: كيف أتصل بـ API؟**
ج: استخدم Laravel Controllers مع Inertia

**س: كيف أضيف الوضع الداكن؟**
ج: Tailwind يوفره مدمجاً - استخدم `dark:` prefix

**س: كيف أختبر المكونات؟**
ج: استخدم Vitest + React Testing Library

---

**آخر تحديث:** 26 يونيو 2026
