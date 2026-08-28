<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        // أُلغيت معاينة اللوحات (الإمبرسنيشن) 2026-08-28 — لا قراءة لمفتاح الجلسة القديم
        // $impersonatorId = $request->session()->get('impersonator_id');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role->value,
                    'roleLabel' => $user->role->label(),
                    'avatar' => $user->avatar_initials,
                    'home' => $user->role->home(),
                    // الصلاحيات التفصيلية (spatie) — الإدارة تتجاوز الكل (isSuper)
                    'isSuper' => $user->isAdmin(),
                    'permissions' => $user->isAdmin() ? [] : $user->getPermissionNames()->all(),
                    // حسابات نفس الشخص (نفس الهُويّة **والجوال** المُثبت) — لمُبدّل «تبديل الحساب».
                    // أمنيّ: التجميع بالجوال أيضاً يمنع ظهور حساب مزوّر يشارك الهُويّة بجوال مختلف.
                    'accounts' => ($user->national_id && $user->phone)
                        ? User::where('national_id', $user->national_id)->where('phone', $user->phone)->where('status', 'active')
                            ->get(['id', 'role'])
                            ->map(fn ($u) => ['id' => $u->id, 'roleLabel' => $u->role->label(), 'current' => $u->id === $user->id])
                            ->values()
                        : [],
                ] : null,
            ],
            // أُلغيت لافتة معاينة لوحة الموظف (الإمبرسنيشن) بقرار 2026-08-28
            // 'impersonating' => ($impersonatorId && $user) ? ['name' => $user->name] : null,
            // كتالوج الصلاحيات (المصدر الوحيد من الخادم) — للتصفية وشاشة الموظفين
            'permCatalog' => $user ? Permissions::catalog() : null,
            // عدّ الإشعارات غير المقروءة الحقيقي (كسول) — يغذّي نقطة الجرس وشارة «الإشعارات»
            'unreadNotifications' => fn () => $user
                ? UserNotification::where('user_id', $user->id)->where('is_read', false)->count()
                : 0,
            // شارات شريط العميل (كسولة) — كانت مشتقّة من بيانات DATA الوهمية في الواجهة،
            // فيرى كل عميل الأرقام نفسها (تذاكر 3 · مواعيد 2 · فواتير 4) مهما كان سجلّه.
            'navBadges' => fn () => ($user && $user->role === Role::Client)
                ? [
                    '/tickets' => Ticket::where('user_id', $user->id)
                        ->whereNotIn('status', ['مكتملة', 'مغلقة'])->count(),
                    // with('consult') إلزامي: liveState() يقرأ الاستشارة، وبدونه استعلام لكل موعد في كل عرض صفحة
                    // المفتاح /calendar لا /appointments: تبويب «المواعيد» طُوي في التبويب
                    // الزمني الموحّد، وبقاء المفتاح القديم كان يُخفي الشارة تماماً.
                    '/calendar' => Appointment::with('consult')->where('user_id', $user->id)
                        ->get()->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->count(),
                    '/invoices' => Invoice::where('user_id', $user->id)->where('paid', false)->count(),
                ]
                : [],
            // المفتاحان مقبولان: with('success', …) وwith('flash', …) — الأخير مستعمل في 17 متحكّماً
            // وكان يُهمَل صامتاً لأنه غير مشارك، فتضيع كل رسائل التأكيد.
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'success' => fn () => $request->session()->get('success') ?? $request->session()->get('flash'),
            ],
            // كلمة المرور المولّدة للموظف الجديد (تُعرض مرة واحدة لدى الإدارة)
            'generatedPassword' => fn () => $request->session()->get('generatedPassword'),
        ];
    }
}
