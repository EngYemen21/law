<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حارس الصلاحيات التفصيلية (deny-by-default) — يُطبَّق صراحةً لكل مسار حسّاس:
 *   ->middleware('permission:إدارة التذاكر')  أو  'permission:صلاحية أ,صلاحية ب' (أيٌّ منها يكفي).
 * الإدارة تتجاوز عبر Gate::before؛ غياب الصلاحية → إعادة توجيه لِلوحة المستخدم مع خطأ.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest('/login');
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        // نداءات axios/fetch (لا Inertia) تتبع التحويل تلقائياً فتقرأ 200 وتظنّ الإجراء ناجحاً.
        // الرفض هنا صريح (403) حتى يعرض الزرّ خطأً حقيقياً بدل توست نجاح كاذب.
        if (! $request->inertia() && ($request->expectsJson() || $request->ajax())) {
            abort(403, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }

        return redirect($user->role->home())
            ->with('error', 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
    }
}
