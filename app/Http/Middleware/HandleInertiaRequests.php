<?php

namespace App\Http\Middleware;

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
                    'role' => $user->role->value,
                    'roleLabel' => $user->role->label(),
                    'avatar' => $user->avatar_initials,
                    'home' => $user->role->home(),
                    // الصلاحيات التفصيلية (spatie) — الإدارة تتجاوز الكل (isSuper)
                    'isSuper' => $user->isAdmin(),
                    'permissions' => $user->isAdmin() ? [] : $user->getPermissionNames()->all(),
                ] : null,
            ],
            // لافتة معاينة لوحة الموظف (إمبرسنيشن) — نشطة عند وجود مُدير أصلي في الجلسة
            'impersonating' => ($impersonatorId && $user) ? ['name' => $user->name] : null,
            // كتالوج الصلاحيات (المصدر الوحيد من الخادم) — للتصفية وشاشة الموظفين
            'permCatalog' => $user ? Permissions::catalog() : null,
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'success' => fn () => $request->session()->get('success'),
            ],
            // كلمة المرور المولّدة للموظف الجديد (تُعرض مرة واحدة لدى الإدارة)
            'generatedPassword' => fn () => $request->session()->get('generatedPassword'),
        ];
    }
}
