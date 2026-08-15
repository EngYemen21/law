<?php

namespace App\Http\Middleware;

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
        $impersonatorId = $request->session()->get('impersonator_id');

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
            // لافتة معاينة لوحة الموظف (إمبرسنيشن) — نشطة عند وجود مُدير أصلي في الجلسة
            'impersonating' => ($impersonatorId && $user) ? ['name' => $user->name] : null,
            // كتالوج الصلاحيات (المصدر الوحيد من الخادم) — للتصفية وشاشة الموظفين
            'permCatalog' => $user ? Permissions::catalog() : null,
            // عدّ الإشعارات غير المقروءة الحقيقي (كسول) — يغذّي نقطة الجرس وشارة «الإشعارات»
            'unreadNotifications' => fn () => $user
                ? UserNotification::where('user_id', $user->id)->where('is_read', false)->count()
                : 0,
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
